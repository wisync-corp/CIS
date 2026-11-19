<?php

namespace App\Services;

use App\Support\CompanySettings as CS;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração anual dos recibos: PREFIXO-ANO-SEQUÊNCIA (ex.: REC-2026-0001), recomeça em 1 em cada ano
 * pela data do pagamento. Um recibo = uma entrada no Extracto ligada a um contrato (E:<Nr>) ou uma
 * prestação paga sem movimento no Extracto (P:<Ordem>, pagamentos do sistema antigo).
 * Depois de atribuído, o número não muda.
 */
final class ReceiptNumbering
{
    public const PAID = ['Pago', 'Pago em Atraso', 'Renovado'];

    public static function prefix(): string
    {
        $p = strtoupper(trim(CS::get('recibo_prefixo')));
        return preg_match('/^[A-Z0-9]{1,10}$/', $p) ? $p : 'REC';
    }

    public static function format(int $year, int $seq): string
    {
        return self::prefix() . '-' . $year . '-' . str_pad((string)$seq, ContractNumbering::digits(), '0', STR_PAD_LEFT);
    }

    public static function installed(): bool
    {
        try { return Schema::hasTable('cis_receipts'); } catch (\Throwable) { return false; }
    }

    /** Recebimentos (todos ou só de um contrato), com chave estável, data e valor. */
    public static function candidates(ConnectionInterface $db, ?string $contrato = null): array
    {
        $out = []; $covered = [];
        $q = $db->table('Extracto')->where('Tipo de Movimento', 'Entradas')->where('Valor', '>', 0)
            ->whereNotNull('Contracto')->where('Contracto', '<>', '');
        if ($contrato !== null) $q->where('Contracto', $contrato);
        foreach ($q->get(['Nr', 'Contracto', 'Parcela', 'Data da Operação', 'Valor']) as $e) {
            $covered[$e->Contracto . '|' . trim((string)$e->Parcela)] = true;
            $out[] = ['chave' => 'E:' . $e->Nr, 'contrato' => (string)$e->Contracto, 'data' => self::d($e->{'Data da Operação'}), 'valor' => (float)$e->Valor];
        }
        $q = $db->table('Contractos')->whereIn('Estado', self::PAID)->where('Valor Pago', '>', 0);
        if ($contrato !== null) $q->where('Nr do Contracto', $contrato);
        foreach ($q->get(['Ordem', 'Nr do Contracto', 'Nº da Parcela', 'Data de Pagamento', 'Valor Pago']) as $r) {
            if (isset($covered[$r->{'Nr do Contracto'} . '|' . trim((string)$r->{'Nº da Parcela'})])) continue;
            $out[] = ['chave' => 'P:' . $r->Ordem, 'contrato' => (string)$r->{'Nr do Contracto'}, 'data' => self::d($r->{'Data de Pagamento'}), 'valor' => (float)$r->{'Valor Pago'}];
        }
        return $out;
    }

    private static function d($v): ?string
    {
        $s = substr((string)($v ?? ''), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && (int)substr($s, 0, 4) >= 1990 ? $s : null;
    }

    /**
     * Atribui número aos recebimentos que ainda não têm, por ordem cronológica, continuando a sequência de cada ano.
     * Na primeira vez numera todo o histórico (desde o primeiro ano). @return array<string,string> chave => número
     */
    public static function ensure(ConnectionInterface $legacy, ?string $contrato = null): array
    {
        if (!self::installed()) return [];
        $w = DB::connection();
        $lock = $w->selectOne('SELECT GET_LOCK(?, 15) AS acquired', ['cis_receipt_numbers']);
        if ((int)($lock->acquired ?? 0) !== 1) return self::numbers($contrato === null ? null : self::candidates($legacy, $contrato));
        try {
            $full = CS::get('recibos_numerados') !== '1';
            $items = self::candidates($legacy, $full ? null : $contrato);
            $have = $w->table('cis_receipts')->whereIn('origem_chave', array_column($items, 'chave'))->pluck('numero', 'origem_chave')->all();
            $todo = array_values(array_filter($items, fn($i) => !isset($have[$i['chave']]) && $i['data'] !== null));
            usort($todo, fn($a, $b) => [$a['data'], self::sortKey($a['chave'])] <=> [$b['data'], self::sortKey($b['chave'])]);
            $w->transaction(function () use ($w, $todo, &$have) {
                $next = [];
                $now = now('Africa/Maputo');
                foreach ($todo as $i) {
                    $y = (int)substr($i['data'], 0, 4);
                    $next[$y] ??= (int)$w->table('cis_receipts')->where('ano', $y)->max('sequencia');
                    $seq = ++$next[$y]; $num = self::format($y, $seq);
                    $w->table('cis_receipts')->insert(['origem_chave' => $i['chave'], 'ano' => $y, 'sequencia' => $seq, 'numero' => $num,
                        'contrato_original' => $i['contrato'], 'data' => $i['data'], 'valor' => round($i['valor'], 2), 'created_at' => $now, 'updated_at' => $now]);
                    $have[$i['chave']] = $num;
                }
            });
            if ($full) {
                $w->table('cis_company_settings')->updateOrInsert(['chave' => 'recibos_numerados'],
                    ['valor' => '1', 'updated_by' => 'sistema', 'updated_at' => now('Africa/Maputo'), 'created_at' => now('Africa/Maputo')]);
                CS::forget();
            }
            return $have;
        } finally {
            $w->select('SELECT RELEASE_LOCK(?)', ['cis_receipt_numbers']);
        }
    }

    private static function sortKey(string $k): string
    {
        // Mesma data: entradas do Extracto pela ordem do movimento; pagamentos antigos pela ordem da prestação.
        [$t, $n] = explode(':', $k, 2);
        return ($t === 'P' ? '0' : '1') . str_pad($n, 12, '0', STR_PAD_LEFT);
    }

    private static function numbers(?array $items): array
    {
        if (!$items) return [];
        return DB::table('cis_receipts')->whereIn('origem_chave', array_column($items, 'chave'))->pluck('numero', 'origem_chave')->all();
    }
}
