<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashAccountLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indicadores da VISÃO GERAL — só leitura, calculados no servidor.
 *
 * Carteira desembolsada (filtrada pelo período): soma da Quantia Creditada dos contratos
 * cuja primeira Data de Inicio cai no período; um valor por contrato. As prestações desse
 * período somam o Valor a Pagar das linhas desses contratos (excepto 'Renovado').
 * Carteira em curso (Contractos, por prestação, situação de hoje):
 *  - por receber  = Estado 'Pendente' ou 'Pagamento Atrasado';
 *  - recebidas    = Estado 'Pago', 'Pago em Atraso' ou 'Renovado' (juros de renovação);
 *  - capital por prestação = Valor a Pagar − Valor de Juros (as linhas criadas pela
 *    renovação no VBA não preenchem Capital/Amortização; esta regra serve VBA e CIS);
 *  - capital recuperado = Valor Pago − Juros − Multa (nunca negativo).
 * Caixa: saldo das contas activas com a MESMA regra da página Caixa.
 * Entradas/saídas do período: Extracto, excluindo transferências internas entre contas.
 *
 * Período (query ?mes=1..12|todos&ano=AAAA|todos; por defeito mês e ano actuais):
 * aplica-se aos recebimentos (Data de Pagamento) e aos movimentos da Caixa
 * (Data da Operação), à carteira desembolsada (1.ª Data de Inicio) e às prestações em
 * aberto por Data de Cobrança: vencidas (< hoje) e pendentes (>= hoje). Saldos = hoje.
 */
final class OverviewController extends Controller
{
    public function __invoke(Request $request)
    {
        $tz='Africa/Maputo';
        $today=now($tz)->toDateString();
        $q=$request->validate([
            'mes'=>['nullable','regex:/^(todos|[1-9]|1[0-2])$/'],
            'ano'=>['nullable','regex:/^(todos|(19|20)\d{2})$/'],
        ]);
        $mes=$q['mes']??(string)now($tz)->month;
        $ano=$q['ano']??(string)now($tz)->year;
        $legacy=DB::connection('mysql_legacy');
        $user=$request->user();$ag=$request->query('agencia');
        // Agência e carteira do utilizador (o administrador pode filtrar por agência).
        $contracts=function () use ($legacy,$user,$ag) { $t=$legacy->table('Contractos'); \App\Support\Branches::restrict($t,$user,$ag); return $t; };
        $branch=\App\Support\Branches::scope($user,$ag);
        $ownOnly=\App\Support\Portfolio::kind($user)!==null;
        // Condição de período sobre uma coluna de data (parâmetros vinculados).
        $period=function (string $col) use ($mes,$ano): array {
            $sql=[];$b=[];
            if ($ano!=='todos') { $sql[]="YEAR(`$col`) = ?"; $b[]=(int)$ano; }
            if ($mes!=='todos') { $sql[]="MONTH(`$col`) = ?"; $b[]=(int)$mes; }
            return [$sql?implode(' AND ',$sql):'1=1',$b];
        };
        [$pp,$pb]=$period('Data de Pagamento');

        $pending="`Estado` IN ('Pendente','Pagamento Atrasado')";
        $paid="`Estado` IN ('Pago','Pago em Atraso','Renovado')";
        $principal="GREATEST(0, COALESCE(`Valor a Pagar`,0) - COALESCE(`Valor de Juros`,0))";
        $recovered="GREATEST(0, COALESCE(`Valor Pago`,0) - COALESCE(`Valor de Juros`,0) - COALESCE(`Valor de Multa`,0))";
        $p=$contracts()->selectRaw("
            ROUND(SUM(CASE WHEN $pending THEN $principal ELSE 0 END),2) AS capital_carteira,
            ROUND(SUM(CASE WHEN $pending THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS a_receber,
            SUM(CASE WHEN $pending THEN 1 ELSE 0 END) AS prestacoes_por_receber,
            SUM(CASE WHEN $pending AND `Data de Cobrança` < ? THEN 1 ELSE 0 END) AS vencidas,
            ROUND(SUM(CASE WHEN $pending AND `Data de Cobrança` < ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS vencidas_valor,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor de Juros`,0) ELSE 0 END),2) AS juros,
            ROUND(SUM(CASE WHEN $paid AND $pp THEN COALESCE(`Valor de Juros`,0) ELSE 0 END),2) AS juros_mes,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor de Multa`,0) ELSE 0 END),2) AS multas,
            ROUND(SUM(CASE WHEN $paid AND $pp THEN COALESCE(`Valor de Multa`,0) ELSE 0 END),2) AS multas_mes,
            ROUND(SUM(CASE WHEN $paid THEN $recovered ELSE 0 END),2) AS capital_recuperado,
            ROUND(SUM(CASE WHEN $paid AND $pp THEN $recovered ELSE 0 END),2) AS capital_recuperado_mes,
            ROUND(SUM(CASE WHEN $paid THEN COALESCE(`Valor Pago`,0) ELSE 0 END),2) AS recebido,
            ROUND(SUM(CASE WHEN $paid AND $pp THEN COALESCE(`Valor Pago`,0) ELSE 0 END),2) AS recebido_mes,
            COUNT(DISTINCT CASE WHEN $pending THEN `Nr do Contracto` END) AS contratos_activos,
            COUNT(DISTINCT CASE WHEN $pending AND `Data de Cobrança` < ? THEN `Nr do Contracto` END) AS contratos_em_atraso
        ",array_merge([$today,$today],$pb,$pb,$pb,$pb,[$today]))->first();

        // Lista negra: o crédito destes mutuários sai da carteira corrente e é mostrado à parte.
        $black=\App\Support\ClientRegistry::blacklistedIds();
        $bl=null;
        if ($black) {
            $bl=$contracts()->whereRaw($pending)->whereIn('Nº de Cliente',$black)->selectRaw("
                ROUND(SUM($principal),2) AS capital, ROUND(SUM(COALESCE(`Valor a Pagar`,0)),2) AS valor, COUNT(*) AS prestacoes,
                SUM(CASE WHEN `Data de Cobrança` < ? THEN 1 ELSE 0 END) AS vencidas,
                ROUND(SUM(CASE WHEN `Data de Cobrança` < ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS vencidas_valor,
                COUNT(DISTINCT `Nr do Contracto`) AS contratos,
                COUNT(DISTINCT CASE WHEN `Data de Cobrança` < ? THEN `Nr do Contracto` END) AS contratos_atraso,
                COUNT(DISTINCT `Nº de Cliente`) AS mutuarios",[$today,$today,$today])->first();
            foreach (['capital_carteira'=>'capital','a_receber'=>'valor','prestacoes_por_receber'=>'prestacoes','vencidas'=>'vencidas',
                'vencidas_valor'=>'vencidas_valor','contratos_activos'=>'contratos','contratos_em_atraso'=>'contratos_atraso'] as $k=>$b) {
                $p->$k=max(0,(float)$p->$k-(float)($bl->$b??0));
            }
        }

        // Prestações em aberto com VENCIMENTO no período: vencidas (< hoje) e pendentes (>= hoje).
        [$dc,$dcb]=$period('Data de Cobrança');
        $due=$contracts()->when($black,fn($q)=>$q->whereNotIn('Nº de Cliente',$black))->whereRaw($pending)->whereRaw($dc,$dcb)->selectRaw("
            SUM(CASE WHEN `Data de Cobrança` < ? THEN 1 ELSE 0 END) AS v_n,
            ROUND(SUM(CASE WHEN `Data de Cobrança` < ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS v_valor,
            COUNT(DISTINCT CASE WHEN `Data de Cobrança` < ? THEN `Nr do Contracto` END) AS v_contratos,
            SUM(CASE WHEN `Data de Cobrança` >= ? THEN 1 ELSE 0 END) AS p_n,
            ROUND(SUM(CASE WHEN `Data de Cobrança` >= ? THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END),2) AS p_valor,
            COUNT(DISTINCT CASE WHEN `Data de Cobrança` >= ? THEN `Nr do Contracto` END) AS p_contratos
        ",[$today,$today,$today,$today,$today,$today])->first();

        // Carteira desembolsada no período: um valor por contrato (Quantia Creditada),
        // data do desembolso = primeira Data de Inicio do contrato (renovações não contam).
        $per=$legacy->query()->fromSub(
            $contracts()->selectRaw("`Nr do Contracto` AS nr, MIN(`Data de Inicio`) AS d, MAX(`Quantia Creditada`) AS q, SUM(CASE WHEN `Estado`<>'Renovado' THEN COALESCE(`Valor a Pagar`,0) ELSE 0 END) AS pr")
                ->groupBy('Nr do Contracto'),'t');
        [$dp,$db]=$period('d');
        $disb=$per->whereRaw($dp,$db)->where('d','<=',$today)
            ->selectRaw('COUNT(*) AS n, ROUND(SUM(COALESCE(q,0)),2) AS total, ROUND(SUM(COALESCE(pr,0)),2) AS prest')->first();
        // Reforços de crédito no período (saída «Aumento de Crédito» na Caixa): também são dinheiro emprestado.
        [$rp,$rb]=$period('Data da Operação');
        $rq=DB::table('Extracto');\App\Support\Branches::restrictExtracto($rq,$user,$ag);
        $ref=$rq->where('Tipo de Movimento','Saídas')->where('Descrição','like','%Aumento de Crédito%')->whereRaw($rp,$rb)
            ->where('Data da Operação','<=',$today)->selectRaw('COUNT(*) AS n, ROUND(SUM(COALESCE(Valor,0)),2) AS total')->first();

        // Caixa: saldos das contas activas (mesma regra da página Caixa).
        $cash=['disponivel'=>false,'saldo_total'=>null,'contas'=>[],'entradas_mes'=>null,'saidas_mes'=>null,'desembolsos_mes'=>null];
        if (!$ownOnly && Schema::hasTable('cis_cash_accounts') && Schema::hasColumn('Extracto','account_id')) {
            $total=0;$ok=true;
            $accQ=DB::table('cis_cash_accounts')->where('status','activa')->orderBy('code');
            if ($branch!==null) $accQ->whereIn('id',\App\Support\Branches::accountsOf($branch)?:[0]);
            foreach ($accQ->get() as $a) {
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
            $mq=DB::table('Extracto');\App\Support\Branches::restrictExtracto($mq,$user,$ag);
            $m=$mq->selectRaw("
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Entradas' AND NOT $internal THEN Valor ELSE 0 END),2) AS entradas,
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Saídas' AND NOT $internal THEN Valor ELSE 0 END),2) AS saidas,
                ROUND(SUM(CASE WHEN `Tipo de Movimento`='Saídas' AND `Descrição` LIKE '%Desembolso%' THEN Valor ELSE 0 END),2) AS desembolsos
            ")->whereRaw($period('Data da Operação')[0],$period('Data da Operação')[1])->where('Data da Operação','<=',$today)->first();
            $cash['entradas_mes']=$this->money($m->entradas);
            $cash['saidas_mes']=$this->money($m->saidas);
            $cash['desembolsos_mes']=$this->money($m->desembolsos);
        }

        return response()->json([
            'actualizado_em'=>now($tz)->format('Y-m-d H:i'),
            'mes'=>now($tz)->format('Y-m'),
            'periodo'=>['mes'=>$mes,'ano'=>$ano,'anos'=>$this->years($legacy)],
            'agencia'=>$branch!==null?['id'=>$branch,'nome'=>\App\Support\Branches::label($branch)]:null,
            'carteira_propria'=>$ownOnly,
            'carteira'=>[
                'desembolsado_periodo'=>$this->money((float)($disb->total??0)+(float)($ref->total??0)),
                'reforcos_periodo'=>['n'=>(int)($ref->n??0),'total'=>$this->money($ref->total??0)],
                'contratos_desembolsados_periodo'=>(int)($disb->n??0),
                'prestacoes_desembolsadas_periodo'=>$this->money($disb->prest??0),
                'capital_em_carteira'=>$this->money($p->capital_carteira),
                'valor_a_receber'=>$this->money($p->a_receber),
                'prestacoes_por_receber'=>(int)$p->prestacoes_por_receber,
                'contratos_activos'=>(int)$p->contratos_activos,
                'prestacoes_vencidas'=>(int)$p->vencidas,
                'valor_vencido'=>$this->money($p->vencidas_valor),
                'contratos_em_atraso'=>(int)$p->contratos_em_atraso,
                'vencidas_periodo'=>(int)($due->v_n??0),
                'valor_vencido_periodo'=>$this->money($due->v_valor??0),
                'contratos_vencidos_periodo'=>(int)($due->v_contratos??0),
                'pendentes_periodo'=>(int)($due->p_n??0),
                'valor_pendente_periodo'=>$this->money($due->p_valor??0),
                'contratos_pendentes_periodo'=>(int)($due->p_contratos??0),
            ],
            'recebimentos'=>[
                'juros'=>$this->money($p->juros),'juros_mes'=>$this->money($p->juros_mes),
                'multas'=>$this->money($p->multas),'multas_mes'=>$this->money($p->multas_mes),
                'capital_recuperado'=>$this->money($p->capital_recuperado),
                'capital_recuperado_mes'=>$this->money($p->capital_recuperado_mes),
                'total_recebido'=>$this->money($p->recebido),'recebido_mes'=>$this->money($p->recebido_mes),
            ],
            'caixa'=>$cash,
            'lista_negra'=>['mutuarios'=>(int)($bl->mutuarios??0),'contratos'=>(int)($bl->contratos??0),'prestacoes'=>(int)($bl->prestacoes??0),
                'capital'=>$this->money($bl->capital??0),'valor'=>$this->money($bl->valor??0),'dias_criterio'=>\App\Support\ClientRegistry::overdueLimit()],
            'nota'=>'Valores do registo de prestações (Contractos) e do Extracto. Pagamentos parciais do VBA contam os juros da linha paga.',
        ])->header('Cache-Control','no-store, private');
    }

    /** Anos com pagamentos ou movimentos, mais o ano actual (para o filtro). */
    private function years($legacy): array
    {
        $y=$legacy->table('Contractos')->whereNotNull('Data de Pagamento')
            ->selectRaw('DISTINCT YEAR(`Data de Pagamento`) AS y')->pluck('y')->all();
        try {
            $y=array_merge($y,DB::table('Extracto')->whereNotNull('Data da Operação')
                ->selectRaw('DISTINCT YEAR(`Data da Operação`) AS y')->pluck('y')->all());
        } catch (\Throwable) {}
        $y[]=(int)now('Africa/Maputo')->year;
        $y=array_values(array_unique(array_filter(array_map('intval',$y),fn($v)=>$v>=1990&&$v<=2100)));
        rsort($y);
        return $y;
    }

    private function money(mixed $v): string
    {
        return number_format((float)($v??0),2,'.','');
    }
}
