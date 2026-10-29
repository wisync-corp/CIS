<?php
namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ImportLegacyUsers extends Command
{
    protected $signature = 'cis:import-legacy-users {--apply : Executar importação após aprovação explícita}';
    protected $description = 'Preparar contas desactivadas a partir de Usuarios, com TLS validado e MySQL SELECT-only';

    public function handle(): int
    {
        if (!filter_var(env('CIS_LEGACY_IMPORT_APPROVED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Importação bloqueada: aprovação do responsável não configurada.');
            return self::FAILURE;
        }
        if (app()->environment('production') && config('database.default') === 'sqlite') {
            $this->error('Importação bloqueada: SQLite efémero não é armazenamento de produção.');
            return self::FAILURE;
        }
        $ca = (string) env('LEGACY_DB_SSL_CA', '');
        if (!$ca || !is_readable($ca)) {
            $this->error('Importação bloqueada: CA TLS validada não configurada.');
            return self::FAILURE;
        }
        try {
            $db = DB::connection('mysql_legacy');
            $row = $db->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            if (trim((string) (array_values((array) $row)[1] ?? '')) === '') {
                $this->error('Importação bloqueada: sessão MySQL sem TLS.');
                return self::FAILURE;
            }
            $grants = $db->select('SHOW GRANTS');
            $statements = strtoupper(implode(' ', array_map(
                fn ($r) => implode(' ', (array) $r), $grants
            )));
            if (!str_contains($statements, 'SELECT') ||
                preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|GRANT OPTION|SUPER|FILE)\b/', $statements)) {
                $this->error('Importação bloqueada: conta MySQL não é exclusivamente de leitura.');
                return self::FAILURE;
            }
            // Never fetch legacy Password; its type was confirmed as INT.
            $rows = $db->select('SELECT `Username` AS login, `Email` AS email, `Nome_cli` AS name FROM `Usuarios`');
            $valid = [];
            $skipped = 0;
            foreach ($rows as $row) {
                $login = trim((string) ($row->login ?? ''));
                $email = strtolower(trim((string) ($row->email ?? '')));
                $name = trim((string) ($row->name ?? ''));
                if ($login === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                    $skipped++;
                    continue;
                }
                $valid[] = compact('login', 'email', 'name');
            }
            $emailCounts = array_count_values(array_column($valid, 'email'));
            $loginCounts = array_count_values(array_column($valid, 'login'));
            $valid = array_values(array_filter($valid, function ($row) use ($emailCounts, $loginCounts, &$skipped) {
                if ($emailCounts[$row['email']] !== 1 || $loginCounts[$row['login']] !== 1) {
                    $skipped++;
                    return false;
                }
                return true;
            }));
            $this->line('Contas elegíveis: '.count($valid).'; ignoradas: '.$skipped.'.');
            if (!$this->option('apply')) {
                $this->info('Simulação concluída. Nenhuma conta foi criada.');
                return self::SUCCESS;
            }
            if (!$this->confirm('Criar apenas contas INACTIVAS, sem importar passwords nem atribuir privilégios?', false)) {
                return self::FAILURE;
            }
            $created = 0;
            foreach ($valid as $record) {
                DB::connection(config('database.default'))->transaction(function () use ($record, &$created) {
                    if (User::query()->where('email', $record['email'])->exists() ||
                        User::query()->where('legacy_username', $record['login'])->exists()) {
                        return;
                    }
                    User::query()->create([
                        'name' => $record['name'],
                        'email' => $record['email'],
                        'legacy_username' => $record['login'],
                        'password' => Hash::make(Str::random(64)),
                        'role' => 'agente',
                        'active' => false,
                    ]);
                    $created++;
                });
            }
            $this->info('Contas inactivas criadas: '.$created.'. Activação e password exigem aprovação individual.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Importação interrompida. Confirme TLS, SELECT-only, tabelas e base persistente.');
            return self::FAILURE;
        }
    }
}
