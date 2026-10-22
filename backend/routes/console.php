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
