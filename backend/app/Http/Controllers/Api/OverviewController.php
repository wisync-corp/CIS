<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashAccountLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indicadores da VISÃO GERAL — só leitura, calculados no servidor.
 *
 * Carteira (Contractos, por prestação):
 *  - por receber  = Estado 'Pendente' ou 'Pagamento Atrasado';
 *  - recebidas    = Estado 'Pago', 'Pago em Atraso' ou 'Renovado' (juros de renovação);
 *  - capital por prestação = Valor a Pagar − Valor de Juros (as linhas criadas pela
 *    renovação no VBA não preenchem Capital/Amortização; esta regra serve VBA e CIS);
 *  - capital recuperado = Valor Pago − Juros − Multa (nunca negativo).
 * Caixa: saldo das contas activas com a MESMA regra da página Caixa.
 * Entradas/saídas do mês: Extracto, excluindo transferências internas entre contas.
 */
final class OverviewController extends Controller
{
    public function __invoke()
    {
        $tz='Africa/Maputo';
        $today=now($tz)->toDateString();
        $monthStart=now($tz)->startOfMonth()->toDateString();
        $legacy=DB::connection('mysql_legacy');

        $pending="`Estado` IN ('Pendente','Pagamento Atrasado')";
        $paid="`Estado` IN ('Pago','Pago em Atraso','Renovado')";
        $principal="GREATEST(0, COALESCE(`Valor a Pagar`,0) - COALESCE(`Valor de Juros`,0))";
        $recovered="GREATEST(0, COALESCE(`Valor Pago`,0) - COALESCE(`Valor de Juros`,0) - COALESCE(`Valor de Multa`,0))";
        $p=$legacy->table('Contractos')->selectRaw("
            ROUND(SUM(CASE WHEN $pending THEN $principal ELSE 0 END),2) AS capital_carteira,
            ROUND(SUM(CASE WHEN $pending THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS a_receber,
            SUM(CASE WHEN $pending THEN 1 ELSE 0 END) AS prestacoes_por_receber,
            SUM(CASE WHEN $pending AND `Data de Cobrança` < ? THEN 1 ELSE 0 END) AS vencidas,
            ROUND(SUM(CASE WHEN $pending AND `Data de Cobrança` < ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS vencidas_valor,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor de Juros`,0) ELSE 0 END),2) AS juros,
            ROUND(SUM(CASE WHEN $paid AND `Data de Pagamento` >= ? THEN COALESCE(`Valor de Juros`,0) ELSE 0 END),2) AS juros_mes,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor de Multa`,0) ELSE 0 END),2) AS multas,
            ROUND(SUM(CASE WHEN $paid AND `Data de Pagamento` >= ? THEN COALESCE(`Valor de Multa`,0) ELSE 0 END),2) AS multas_mes,
            ROUND(SUM(CASE WHEN $paid THEN $recovered ELSE 0 END),2) AS capital_recuperado,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor Pago`,0) ELSE 0 END),2) AS recebido,
            ROUND(SUM(CASE WHEN $paid AND `Data de Pagamento` >= ? THEN COALESCE(`Valor Pago`,0) ELSE 0 END),2) AS recebido_mes,
            COUNT(DISTINCT CASE WHEN $pending THEN `Nr do Contracto` END) AS contratos_activos,
            COUNT(DISTINCT CASE WHEN $pending AND `Data de Cobrança` < ? THEN `Nr do Contracto` END) AS contratos_em_atraso
        ",[$today,$today,$monthStart,$monthStart,$monthStart,$today])->first();

        // Caixa: saldos das contas activas (mesma regra da página Caixa).
        $cash=['disponivel'=>false,'saldo_total'=>null,'contas'=>[],'entradas_mes'=>null,'saidas_mes'=>null,'desembolsos_mes'=>null];
        if (Schema::hasTable('cis_cash_accounts') && Schema::hasColumn('Extracto','account_id')) {
            $total=0;$ok=true;
            foreach (DB::table('cis_cash_accounts')->where('status','activa')->orderBy('code')->get() as $a) {
                try {
                    $b=CashAccountLedger::balance(DB::connection(),$a);
                    $total+=$b;
                    $cash['contas'][]=['code'=>$a->code,'name'=>$a->name,'saldo'=>CashAccountLedger::amount($b)];
                } catch (\Throwable $e) {
                    $ok=false;
                    $cash['contas'][]=['code'=>$a->code,'name'=>$a->name,'saldo'=>null];
                }
            }
            $cash['disponivel']=$ok && count($cash['contas'])>0;
            $cash['saldo_total']=$ok?CashAccountLedger::amount($total):null;
            $internal="(`Descrição` LIKE '[TRANSFER %' OR `Descrição` LIKE '[ESTORNO TRANSFER %')";
            $m=DB::table('Extracto')->selectRaw("
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Entradas' AND NOT $internal THEN Valor ELSE 0 END),2) AS entradas,
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Saídas' AND NOT $internal THEN Valor ELSE 0 END),2) AS saidas,
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Saídas' AND `Descrição` LIKE '%Desembolso%' THEN Valor ELSE 0 END),2) AS desembolsos
            ")->where('Data da Operação','>=',$monthStart)->where('Data da Operação','<=',$today)->first();
            $cash['entradas_mes']=$this->money($m->entradas);
            $cash['saidas_mes']=$this->money($m->saidas);
            $cash['desembolsos_mes']=$this->money($m->desembolsos);
        }

        return response()->json([
            'actualizado_em'=>now($tz)->format('Y-m-d H:i'),
            'mes'=>now($tz)->format('Y-m'),
            'carteira'=>[
                'capital_em_carteira'=>$this->money($p->capital_carteira),
                'valor_a_receber'=>$this->money($p->a_receber),
                'prestacoes_por_receber'=>(int)$p->prestacoes_por_receber,
                'contratos_activos'=>(int)$p->contratos_activos,
                'prestacoes_vencidas'=>(int)$p->vencidas,
                'valor_vencido'=>$this->money($p->vencidas_valor),
                'contratos_em_atraso'=>(int)$p->contratos_em_atraso,
            ],
            'recebimentos'=>[
                'juros'=>$this->money($p->juros),'juros_mes'=>$this->money($p->juros_mes),
                'multas'=>$this->money($p->multas),'multas_mes'=>$this->money($p->multas_mes),
                'capital_recuperado'=>$this->money($p->capital_recuperado),
                'total_recebido'=>$this->money($p->recebido),'recebido_mes'=>$this->money($p->recebido_mes),
            ],
            'caixa'=>$cash,
            'nota'=>'Valores do registo de prestações (Contractos) e do Extracto. Pagamentos parciais do VBA contam os juros da linha paga.',
        ])->header('Cache-Control','no-store, private');
    }

    private function money(mixed $v): string
    {
        return number_format((float)($v??0),2,'.','');
    }
}
