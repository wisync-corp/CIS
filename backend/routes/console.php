<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('cis:bootstrap-admin {email}', function (string $email) {
    if (\App\Models\User::query()->exists()) { $this->error('Bootstrap disponível apenas quando não existem utilizadores.'); return 1; }
    $password = $this->secret('Nova palavra-passe (mínimo 12 caracteres)');
    if (!is_string($password) || mb_strlen($password) < 12) { $this->error('Palavra-passe insuficiente'); return 1; }
    \App\Models\User::create(['name'=>'Administrador CIS','email'=>$email,'password'=>$password,'role'=>'administrador','active'=>true]);
    $this->info('Administrador criado'); return 0;
})->purpose('Criar primeiro administrador do CIS com palavra-passe segura');

Artisan::command('cis:reset-password {email}', function (string $email) {
    $user = \App\Models\User::query()->where('email', $email)->first();
    if (!$user) { $this->error('Conta não encontrada.'); return 1; }
    if ($user->role !== 'administrador') { $this->error('Este comando só permite redefinir contas de administrador.'); return 1; }
    if (!$this->confirm('Confirma a redefinição da palavra-passe deste administrador?', false)) {
        $this->warn('Operação cancelada.'); return 1;
    }
    $password = $this->secret('Nova palavra-passe (mínimo 12 caracteres)');
    $confirmation = $this->secret('Confirme a nova palavra-passe');
    if (!is_string($password) || mb_strlen($password) < 12 || $password !== $confirmation) {
        $this->error('Palavra-passe insuficiente ou confirmação diferente.'); return 1;
    }
    $user->password = $password;
    $user->save();
    $user->tokens()->delete();
    $this->info('Palavra-passe actualizada. Sessões anteriores revogadas.');
    return 0;
})->purpose('Redefinir palavra-passe de administrador de forma interactiva');

Artisan::command('cis:test-db', function () {
    // Apenas verifica conectividade. Não consulta dados financeiros nem imprime segredos.
    try {
        $db = \Illuminate\Support\Facades\DB::connection('mysql_legacy');
        $result = $db->selectOne('SELECT 1 AS connection_ok');
        if ((int) ($result->connection_ok ?? 0) !== 1) {
            $this->error('O servidor respondeu de forma inesperada.');
            return 1;
        }
        $this->info('Ligação MySQL estabelecida. SELECT 1 executado com sucesso.');
        $this->warn('Isto não valida permissões nas tabelas, TLS, nem autorização para dados reais.');
        return 0;
    } catch (\Throwable $e) {
        $this->error('Não foi possível executar SELECT 1 na ligação mysql_legacy.');
        $this->line('Tipo de erro: '.class_basename($e));
        $this->warn('Consulte host, rede, TLS e permissões localmente. Não publique logs com credenciais.');
        return 1;
    }
})->purpose('Testar ligação MySQL legado por SELECT 1, sem expor dados');

Artisan::command('cis:inspect-schema', function () {
    // Compatível com servidores MySQL antigos: não depende de generation_expression.
    // Apenas metadados; não devolve registos dos clientes.
    try {
        $db = \Illuminate\Support\Facades\DB::connection('mysql_legacy');
        $tables = ['Clientes', 'Contractos', 'Extracto'];
        $rows = $db->select(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?) ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $tables
        );
        $found = [];
        foreach ($rows as $row) {
            $found[$row->TABLE_NAME][] = $row->COLUMN_NAME.' ('.$row->DATA_TYPE.')';
        }
        foreach ($tables as $table) {
            $columns = $found[$table] ?? [];
            $this->line($table.': '.count($columns).' campos');
            if ($columns) {
                foreach ($columns as $column) { $this->line('  - '.$column); }
            } else {
                $this->warn('  Não encontrada ou sem acesso a metadados.');
            }
        }
        $this->warn('A consulta de metadados não comprova permissão SELECT, TLS ou isolamento de uma réplica.');
        return count($found) === count($tables) ? 0 : 1;
    } catch (\Throwable $e) {
        $this->error('Não foi possível consultar metadados básicos das tabelas.');
        $this->line('Tipo de erro: '.class_basename($e));
        return 1;
    }
})->purpose('Inspeccionar colunas de Clientes, Contractos e Extracto sem comandos db:table');

Artisan::command('cis:check-db-safety', function () {
    // Diagnóstico sem ler linhas financeiras nem mostrar identificadores ou segredos.
    try {
        $db = \Illuminate\Support\Facades\DB::connection('mysql_legacy');
        $grants = $db->select('SHOW GRANTS');
        $stat = $db->select("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $cipher = '';
        foreach ($stat as $row) {
            $arr = (array) $row;
            if (($arr['Variable_name'] ?? '') === 'Ssl_cipher') {
                $cipher = trim((string) ($arr['Value'] ?? ''));
            }
        }
        $grantStrings = array_map(fn ($row) => strtoupper(implode(' ', array_values((array) $row))), $grants);
        $hasWrites = false;
        $hasSelect = false;
        foreach ($grantStrings as $grant) {
            if (preg_match('/\\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|GRANT OPTION|SUPER|FILE)\\b/', $grant)) {
                $hasWrites = true;
            }
            if (preg_match('/\\bSELECT\\b/', $grant)) {
                $hasSelect = true;
            }
        }
        $this->line('Ligação TLS (SSL cipher da sessão): '.($cipher !== '' ? 'activa' : 'não confirmada / ausente'));
        $this->line('SELECT explícito nos privilégios: '.($hasSelect ? 'sim' : 'não identificado'));
        $this->line('Privilégios de escrita/perigosos detectados: '.($hasWrites ? 'sim' : 'não identificados'));
        $this->warn('TLS activo não é suficiente: é preciso validar o certificado com CA confiável.');
        $this->warn('Inspecção indicativa dos grants, não certifica isolamento, identidade da réplica ou conformidade.');
        return $cipher !== '' && $hasSelect && !$hasWrites ? 0 : 1;
    } catch (\Throwable $e) {
        $this->error('Não foi possível validar privilégios e TLS nesta ligação.');
        $this->line('Tipo de erro: '.class_basename($e));
        return 1;
    }
})->purpose('Verificação inicial de TLS e privilégios SELECT sem mostrar dados');

Artisan::command('cis:list-admins', function () {
    $admins = \App\Models\User::query()->where('role', 'administrador')->get(['id', 'name', 'email', 'active']);
    $this->table(['ID', 'Nome', 'Email', 'Activo'], $admins->map(fn ($u) => [$u->id, $u->name, $u->email, $u->active ? 'Sim' : 'Não'])->all());
})->purpose('Consultar administradores locais do CIS');

Artisan::command('cis:check-clients', function () {
    // Diagnóstico local: não imprime dados pessoais nem modifica tabelas.
    try {
        $db = \Illuminate\Support\Facades\DB::connection('sqlite');
        $this->line('Tabela users: '.($db->getSchemaBuilder()->hasTable('users') ? 'presente' : 'ausente'));
        $exists = $db->getSchemaBuilder()->hasTable('clients');
        $this->line('Tabela clients: '.($exists ? 'presente' : 'ausente'));
        if (!$exists) {
            $this->warn('Execute: docker compose exec cis-api php artisan migrate --force');
            return 1;
        }
        $columns = ['id','created_by','nome_completo','created_at'];
        foreach ($columns as $column) {
            if (!$db->getSchemaBuilder()->hasColumn('clients', $column)) {
                $this->error('Falta a coluna: '.$column);
                return 1;
            }
        }
        $this->info('Estrutura de clientes local disponível.');
        $this->line('Total de clientes locais: '.$db->table('clients')->count());
        return 0;
    } catch (\Throwable $e) {
        $this->error('Falha na consulta SQLite: '.class_basename($e));
        return 1;
    }
})->purpose('Verificar o estado da tabela clients sem apresentar dados pessoais');

Artisan::command('cis:diagnose-tls', function () {
    // Metadados TLS apenas: não lê Clientes, Contractos ou Extracto.
    $db = \Illuminate\Support\Facades\DB::connection('mysql_legacy');
    try {
        $db->selectOne('SELECT 1');
    } catch (\Throwable $e) {
        $this->error('Ligação MySQL falhou ('.class_basename($e).').');
        return 1;
    }
    foreach (['have_ssl', 'require_secure_transport'] as $variable) {
        try {
            $row = $db->selectOne("SHOW VARIABLES LIKE '".$variable."'");
            $value = $row ? (string) (array_values((array) $row)[1] ?? 'indisponível') : 'não disponível neste servidor';
            $this->line($variable.': '.$value);
        } catch (\Throwable $e) {
            $this->warn($variable.': consulta não permitida pelo servidor.');
        }
    }
    try {
        $row = $db->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $cipher = $row ? trim((string) (array_values((array) $row)[1] ?? '')) : '';
        $this->line('TLS da sessão: '.($cipher !== '' ? 'activo' : 'não detectado'));
    } catch (\Throwable $e) {
        $this->warn('TLS da sessão: não foi possível verificar.');
    }
    $ca = (string) env('LEGACY_DB_SSL_CA', '');
    $this->line('CA configurada: '.($ca !== '' ? 'sim' : 'não'));
    if ($ca !== '' && !is_readable($ca)) {
        $this->error('CA configurada, mas o ficheiro não pode ser lido pelo contentor.');
        return 1;
    }
    $this->warn('Só TLS com certificado validado e privilégios restritos permite avaliar autorização de dados reais.');
    return 0;
})->purpose('Diagnosticar suporte TLS MySQL sem consultar dados pessoais');

Artisan::command('cis:probe-verified-tls', function () {
    // Prova ligação autenticada e TLS verificado sem executar consultas a clientes.
    // Nunca altera a conexão normal, os dados nem a configuração do CIS.
    $host = (string) env('LEGACY_DB_HOST', '');
    $database = (string) env('LEGACY_DB_DATABASE', '');
    $username = (string) env('LEGACY_DB_USERNAME', '');
    $password = (string) env('LEGACY_DB_PASSWORD', '');
    $port = (int) env('LEGACY_DB_PORT', 3306);
    $ca = (string) env('LEGACY_DB_SSL_CA', '');
    if ($ca === '') {
        $ca = '/etc/ssl/certs/ca-certificates.crt';
        $this->line('A testar com as autoridades certificadoras do sistema.');
    }
    if (!is_readable($ca)) {
        $this->error('Certificado CA não disponível no contentor.');
        return 1;
    }
    try {
        $pdo = new \PDO(
            'mysql:host='.$host.';port='.$port.';dbname='.$database.';charset=utf8mb4',
            $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 8,
                \PDO::MYSQL_ATTR_SSL_CA => $ca,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
            ]
        );
        $row = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(\PDO::FETCH_ASSOC);
        $cipher = trim((string) ($row['Value'] ?? ''));
        if ($cipher === '') {
            $this->error('Ligação estabelecida, mas sem cifra TLS activa. Não aprovada.');
            return 1;
        }
        $this->info('Ligação TLS com verificação de certificado estabelecida com sucesso.');
        $this->line('Cifra TLS negociada: '.$cipher);
        $this->warn('Ainda é necessário validar privilégios SELECT-only e autorização dos dados.');
        return 0;
    } catch (\Throwable $e) {
        $this->error('Não foi possível estabelecer TLS com certificado verificado.');
        $this->line('Tipo de erro: '.class_basename($e));
        $this->warn('Não desactive a verificação do certificado. Confirme suporte TLS e CA com o fornecedor.');
        return 1;
    }
})->purpose('Testar TLS verificado no MySQL original sem consultar dados de clientes');


Artisan::command('cis:inspect-legacy-users', function () {
    // Metadados apenas; nunca lê nomes, hashes nem palavras-passe.
    try {
        $rows = \Illuminate\Support\Facades\DB::connection('mysql_legacy')->select(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) IN (?, ?) ORDER BY TABLE_NAME, ORDINAL_POSITION',
            ['usuarios', 'users']
        );
        if (!$rows) {
            $this->warn('Tabela Usuarios/Users não encontrada ou metadados inacessíveis.');
            return 1;
        }
        foreach ($rows as $row) {
            $this->line($row->TABLE_NAME.' · '.$row->COLUMN_NAME.' ('.$row->DATA_TYPE.')');
        }
        $this->warn('Apenas nomes de colunas; não comprova TLS, privilégio SELECT-only nem tipo dos hashes.');
        return 0;
    } catch (\Throwable $exception) {
        $this->error('Não foi possível verificar a estrutura da tabela de utilizadores.');
        return 1;
    }
})->purpose('Inspeccionar apenas os nomes e tipos das colunas Usuarios do legado');


Artisan::command('cis:activate-imported-user {email}', function (string $email) {
    $user = \App\Models\User::query()->where('email', $email)->first();
    if (!$user || !$user->legacy_username || $user->active || $user->role !== 'agente') {
        $this->error('Conta importada inactiva e sem privilégios elevados não encontrada.');
        return 1;
    }
    if (!$this->confirm('Identidade, email e autorização foram verificados fora do sistema?', false)) {
        return 1;
    }
    $password = $this->secret('Nova palavra-passe CIS (mínimo 12 caracteres)');
    $again = $this->secret('Confirme a nova palavra-passe');
    if (!is_string($password) || mb_strlen($password) < 12 || $password !== $again) {
        $this->error('Palavra-passe insuficiente ou confirmação diferente.');
        return 1;
    }
    $user->password = $password;
    $user->active = true;
    $user->save();
    $user->tokens()->delete();
    $this->info('Conta CIS activada com nova palavra-passe. Nunca reutilize a senha numérica do VBA.');
    return 0;
})->purpose('Activar individualmente uma conta migrada com identidade verificada');
