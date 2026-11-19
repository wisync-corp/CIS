<?php

namespace App\Console\Commands;

use App\Services\ContractNumbering as CN;
use App\Support\CompanySettings as CS;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renumera todos os contratos para PREFIXO-ANO-SEQUÊNCIA, recomeçando em 1 em cada ano
 * (pela data de desembolso). Sem --aplicar só mostra o plano.
 */
class RenumberContracts extends Command
{
    protected $signature = 'cis:renumerar-contratos
        {--aplicar : Gravar a renumeração (depois de rever o plano)}
        {--confirmo= : Escrever RENUMERAR para aplicar sem pergunta interactiva}
        {--ligacao= : Ligação MySQL com escrita em Contractos, Extracto e tabelas cis_* (por omissão, a principal)}
        {--por=deploy : Quem executa}';
    protected $description = 'Numeração anual dos contratos (PREFIXO-ANO-SEQUÊNCIA) para todos os contratos existentes';

    public function handle(): int
    {
        $db = DB::connection($this->option('ligacao') ?: null);
        if (!Schema::connection($db->getName())->hasTable('cis_contract_numbers')) {
            $this->error('Tabela cis_contract_numbers em falta. Execute primeiro a migração 2026_11_19_000140.');
            return self::FAILURE;
        }
        CS::forget();
        $prefix = CN::prefix(); $digits = CN::digits();
        $plan = CN::plan($db, $prefix, $digits);
        $this->info("Formato: {$prefix}-ANO-" . str_repeat('9', $digits) . ' (ex.: ' . CN::format((int)date('Y'), 1, $prefix, $digits) . ')');
        $this->table(['Ano', 'Contratos', 'Primeiro', 'Último', 'A alterar'], array_map(fn($r) => array_values($r), CN::summary($plan)));
        foreach (array_slice($plan['linhas'], 0, 8) as $l) $this->line("  {$l['atual']}  →  {$l['novo']}   (desembolso {$l['data']})");
        if ($plan['sem_data']) $this->warn('Sem data de desembolso (ficam com o número actual): ' . implode(', ', array_slice($plan['sem_data'], 0, 20)));
        $changes = count(array_filter($plan['linhas'], fn($l) => $l['atual'] !== $l['novo']));
        if (!$this->option('aplicar')) { $this->info("Plano apenas ({$changes} contrato(s) a alterar). Use --aplicar para gravar."); return self::SUCCESS; }
        $ok = $this->option('confirmo') === 'RENUMERAR' || $this->ask('Escreva RENUMERAR para gravar') === 'RENUMERAR';
        if (!$ok) { $this->warn('Cancelado.'); return self::FAILURE; }
        $lock = $db->selectOne('SELECT GET_LOCK(?, 30) AS acquired', ['cis_open_new_contract']);
        if ((int)($lock->acquired ?? 0) !== 1) { $this->error('Há uma abertura de contrato em curso. Tente de novo.'); return self::FAILURE; }
        try {
            $plan = CN::plan($db, $prefix, $digits); // recalcular dentro do bloqueio
            $st = CN::apply($db, $plan, (string)$this->option('por'));
            foreach (['contrato_prefixo' => $prefix, 'contrato_digitos' => (string)$digits, 'numeracao_anual_activa' => '1'] as $k => $v)
                DB::table('cis_company_settings')->updateOrInsert(['chave' => $k], ['valor' => $v, 'updated_by' => mb_substr((string)$this->option('por'), 0, 120), 'updated_at' => now('Africa/Maputo'), 'created_at' => now('Africa/Maputo')]);
            CS::forget();
        } finally {
            $db->select('SELECT RELEASE_LOCK(?)', ['cis_open_new_contract']);
        }
        $this->info("RENUMERADO: {$st['contratos']} contrato(s), {$st['prestacoes']} prestação(ões), {$st['extracto']} movimento(s) do Extracto, {$st['mensagens']} mensagem(ns).");
        $this->info('Os novos contratos passam a usar a numeração anual.');
        return self::SUCCESS;
    }
}
