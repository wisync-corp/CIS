<?php

namespace App\Http\Controllers\Api;

use App\Services\ContractNumbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos de pagamento de um contrato. Cada entrada no Extracto do contrato é um recibo;
 * prestações pagas sem entrada no Extracto (ex.: lançadas no sistema antigo) também têm recibo.
 * Número anual REC-ANO-SEQUÊNCIA (ReceiptNumbering), pela data do pagamento.
 */
class ContractReceiptController
{
    private const PAID = ['Pago', 'Pago em Atraso', 'Renovado'];

    public function index(Request $request, string $numero): JsonResponse
    {
        $u = $request->user();
        abort_unless($u && $u->active && $u->role === 'administrador', 403);
        abort_if(trim($numero) === '' || mb_strlen($numero) > 155, 422, 'Número de contrato inválido.');
        $numero = ContractNumbering::resolveIfMissing(trim($numero));
        $db = DB::connection('mysql_legacy');
        $cols = array_flip(Schema::connection('mysql_legacy')->getColumnListing('Contractos'));
        $want = ['Ordem', 'Nº da Parcela', 'Nome do Cliente', 'Nº de Cliente', 'Data de Cobrança', 'Data de Pagamento', 'Valor a Pagar',
            'Valor Pago', 'Valor de Juros', 'Valor de Multa', 'Amortização', 'Capital', 'Estado', 'Forma de Pagamento', 'Situação de Pagamento'];
        $sel = implode(',', array_map(fn($c) => '`' . $c . '`', array_values(array_filter($want, fn($c) => isset($cols[$c])))));
        $rows = $db->table('Contractos')->where('Nr do Contracto', $numero)->selectRaw($sel)->orderBy('Nº da Parcela')->orderBy('Ordem')->get();
        abort_if($rows->isEmpty(), 404, 'Contrato não encontrado.');
        $first = $rows->first();
        $byParcela = $rows->groupBy(fn($r) => (string)$r->{'Nº da Parcela'});

        $ext = collect();
        try {
            $ecols = array_flip(Schema::connection('mysql_legacy')->getColumnListing('Extracto'));
            $esel = array_values(array_filter(['Nr', 'Parcela', 'Data da Operação', 'Descrição', 'Valor', 'account_id', 'Tipo de Movimento'], fn($c) => isset($ecols[$c])));
            $ext = $db->table('Extracto')->where('Contracto', $numero)->where('Tipo de Movimento', 'Entradas')
                ->selectRaw(implode(',', array_map(fn($c) => '`' . $c . '`', $esel)))->orderBy('Data da Operação')->orderBy('Nr')->get();
        } catch (\Throwable) {}
        $accounts = [];
        try { if (Schema::hasTable('cis_cash_accounts')) $accounts = DB::table('cis_cash_accounts')->pluck('code', 'id')->all(); } catch (\Throwable) {}

        $d = fn($v) => $v ? substr((string)$v, 0, 10) : null;
        $m = fn($v) => number_format((float)($v ?? 0), 2, '.', '');
        $out = []; $covered = [];
        $perParcela = $ext->filter(fn($e) => (float)$e->Valor > 0)->countBy(fn($e) => trim((string)($e->Parcela ?? '')));
        foreach ($ext as $e) {
            if ((float)$e->Valor <= 0) continue;
            $p = trim((string)($e->Parcela ?? ''));
            $inst = $byParcela[$p] ?? collect();
            $paidRow = $inst->first(fn($r) => in_array($r->Estado, self::PAID, true)) ?? $inst->first();
            $single = ($perParcela[$p] ?? 0) === 1; // decomposição só quando há um único recebimento na prestação
            if ($p !== '') $covered[$p] = true;
            $desc = (string)($e->{'Descrição'} ?? '');
            $out[] = [
                'chave' => 'E:' . $e->Nr, 'numero' => 'REC-' . $e->Nr, 'origem' => 'extracto', 'extracto_nr' => (int)$e->Nr,
                'data' => $d($e->{'Data da Operação'}), 'prestacao' => $p !== '' ? $p : null, 'valor' => $m($e->Valor),
                'capital' => $paidRow && $single && $paidRow->Estado !== 'Renovado' ? $m($paidRow->{'Amortização'} ?? 0) : null, 'juros' => $paidRow && $single ? $m($paidRow->{'Valor de Juros'} ?? 0) : null,
                'juros_mora' => $paidRow && $single ? $m($paidRow->{'Valor de Multa'} ?? 0) : null, 'vencimento' => $paidRow ? $d($paidRow->{'Data de Cobrança'}) : null,
                'estado' => $paidRow?->Estado, 'forma_pagamento' => $paidRow?->{'Forma de Pagamento'} ?? null,
                'conta' => isset($e->account_id) ? ($accounts[$e->account_id] ?? null) : null,
                'referencia' => preg_match('/\bREF:\s*([^|]+)/u', $desc, $mm) ? trim($mm[1]) : null,
                'descricao' => trim(preg_replace('/^\[[^\]]+\]\s*/u', '', $desc)),
            ];
        }
        foreach ($rows as $r) {
            $p = (string)$r->{'Nº da Parcela'};
            if (!in_array($r->Estado, self::PAID, true) || isset($covered[$p]) || (float)($r->{'Valor Pago'} ?? 0) <= 0) continue;
            $out[] = [
                'chave' => 'P:' . $r->Ordem, 'numero' => 'REC-P' . $r->Ordem, 'origem' => 'prestacao', 'extracto_nr' => null,
                'data' => $d($r->{'Data de Pagamento'}), 'prestacao' => $p, 'valor' => $m($r->{'Valor Pago'}),
                'capital' => $m($r->{'Amortização'} ?? 0), 'juros' => $m($r->{'Valor de Juros'} ?? 0), 'juros_mora' => $m($r->{'Valor de Multa'} ?? 0),
                'vencimento' => $d($r->{'Data de Cobrança'}), 'estado' => $r->Estado, 'forma_pagamento' => $r->{'Forma de Pagamento'} ?? null,
                'conta' => null, 'referencia' => null, 'descricao' => 'Pagamento da prestação ' . $p,
            ];
        }
        // Numeração anual (REC-ANO-SEQUÊNCIA), fixa depois de atribuída.
        $nums = [];
        try { $nums = \App\Services\ReceiptNumbering::ensure($db, $numero); } catch (\Throwable) {}
        foreach ($out as &$o) { if (isset($nums[$o['chave']])) $o['numero'] = $nums[$o['chave']]; unset($o['chave']); }
        unset($o);
        usort($out, fn($a, $b) => [$a['data'] ?? '', $a['numero']] <=> [$b['data'] ?? '', $b['numero']]);
        $total = array_sum(array_map(fn($r) => (float)$r['valor'], $out));
        $owed = $rows->filter(fn($r) => in_array($r->Estado, ['Pendente', 'Pagamento Atrasado'], true))->sum(fn($r) => (float)($r->{'Valor a Pagar'} ?? 0) + (float)($r->{'Valor de Multa'} ?? 0));
        return response()->json([
            'contrato' => $numero,
            'mutuario' => ['nome' => (string)($first->{'Nome do Cliente'} ?? ''), 'numero' => $first->{'Nº de Cliente'} ?? null],
            'recibos' => $out, 'total_recebido' => $m($total), 'saldo_em_divida' => $m($owed),
            'emitido_em' => now('Africa/Maputo')->format('Y-m-d H:i'), 'emitido_por' => $u->name ?? null,
        ])->header('Cache-Control', 'no-store, private');
    }
}
