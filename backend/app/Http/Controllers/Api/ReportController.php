<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use App\Support\CompanySettings as CS;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RELATÓRIOS REGULATÓRIOS (Banco de Moçambique) — só leitura.
 *
 * Todas as definições estão AQUI (catálogo em self::catalog() e um método por relatório),
 * para ajustar facilmente quando o Banco de Moçambique confirmar os modelos oficiais:
 *  - actividade     : relatório periódico de actividade (Decreto 50/2024, art. 165 e 167 d)).
 *  - crc            : dados para a Central de Registo de Crédito (Aviso 12/GBM/2024).
 *  - branqueamento  : dados anuais de prevenção do branqueamento (Aviso 10/GBM/2024, cap. XIII).
 *  - identificacao  : qualidade dos dados de identificação dos clientes (Aviso 10/GBM/2024 art. 138).
 *
 * Cada relatório devolve: titulo, base_legal, nota, resumo[], seccoes[{titulo,colunas,linhas}].
 * Valores em MT com 2 casas. Regras de situação iguais às da Visão geral e do Extracto.
 */
final class ReportController extends Controller
{
    private const OPEN=['Pendente','Pagamento Atrasado'];
    private const PAID=['Pago','Pago em Atraso','Renovado'];
    // Regime simplificado de identificação (Aviso 10/GBM/2024, art. 137).
    private const SIMPLIFICADO_MES=30000.0;
    private const SIMPLIFICADO_ANO=240000.0;

    public static function catalog(): array
    {
        return [
            ['id'=>'actividade','grupo'=>'Regulatórios','titulo'=>'Relatório periódico de actividade','periodo'=>'intervalo',
             'descricao'=>'Desembolsos, carteira, carteira em risco, recebimentos, renovações, mutuários e Caixa.',
             'base_legal'=>'Decreto n.º 50/2024 (arts. 165 e 167 d)): monitorização e comunicações obrigatórias definidas pelo Banco de Moçambique.'],
            ['id'=>'crc','grupo'=>'Regulatórios','titulo'=>'Central de Registo de Crédito','periodo'=>'data',
             'descricao'=>'Uma linha por contrato com saldo ou movimento: identificação do mutuário, montantes, saldo, atraso e situação.',
             'base_legal'=>'Aviso n.º 12/GBM/2024 (Regulamento da Central de Registo de Crédito): reporte em 24 horas após cada operação; modelo por Circular.'],
            ['id'=>'branqueamento','grupo'=>'Regulatórios','titulo'=>'Prevenção do branqueamento de capitais (anual)','periodo'=>'ano',
             'descricao'=>'Mutuários acima do regime simplificado, maiores movimentos por mutuário e mutuários sem identificação completa.',
             'base_legal'=>'Aviso n.º 10/GBM/2024, Capítulo XIII (arts. 134–141) e art. 116 (dados anuais até 31 de Janeiro).'],
            ['id'=>'identificacao','grupo'=>'Regulatórios','titulo'=>'Qualidade dos dados de identificação','periodo'=>'nenhum',
             'descricao'=>'Mutuários com contratos e dados de identificação em falta (BI, NUIT, contacto, morada).',
             'base_legal'=>'Aviso n.º 10/GBM/2024, art. 138 (identificação simplificada: nome, tipo e número do documento, NUIB).'],
            // ---- Gestão (uso interno) ----
            ['id'=>'agenda','grupo'=>'Gestão','titulo'=>'Folha de cobrança','periodo'=>'futuro',
             'descricao'=>'Prestações a vencer no período, dia a dia, com contacto do mutuário; e prestações em atraso por cobrar.',
             'base_legal'=>'Relatório de gestão interna (planeamento das cobranças).'],
            ['id'=>'atrasos','grupo'=>'Gestão','titulo'=>'Antiguidade dos atrasos','periodo'=>'data',
             'descricao'=>'Valor vencido por escalões de dias e lista por contrato, com contacto e último pagamento.',
             'base_legal'=>'Relatório de gestão interna (acompanhamento do risco de crédito).'],
            ['id'=>'promessas','grupo'=>'Gestão','titulo'=>'Contactos e promessas de pagamento','periodo'=>'intervalo',
             'descricao'=>'Contactos de cobrança registados no período e promessas de pagamento: cumpridas, falhadas e pendentes, por quem registou.',
             'base_legal'=>'Gestão da cobrança: acompanhamento dos contactos e do cumprimento das promessas dos mutuários.'],
            ['id'=>'abates','grupo'=>'Gestão','titulo'=>'Créditos abatidos e recuperações','periodo'=>'intervalo',
             'descricao'=>'Contratos abatidos (write-off) no período, valor abatido, quanto já foi recuperado e saldo; recuperações recebidas no período.',
             'base_legal'=>'Gestão do risco de crédito: registo dos créditos considerados incobráveis e das recuperações posteriores.'],
            ['id'=>'carteira','grupo'=>'Desempenho da equipa','titulo'=>'Gestores de carteira','periodo'=>'intervalo',
             'descricao'=>'Por gestor de carteira (responsável pelos contratos): carteira, valor em atraso, PAR 30, a cobrar e cobrado no período e taxa de cobrança.',
             'base_legal'=>'Relatório de gestão interna (desempenho da equipa). Responsável atribuído em Definições › Carteiras ou na ficha do contrato.'],
            ['id'=>'agentes','grupo'=>'Desempenho da equipa','titulo'=>'Agentes','periodo'=>'intervalo',
             'descricao'=>'Por agente: mutuários cadastrados, novos no período, quantos chegaram a contrato, valor desembolsado e qualidade (PAR 30).',
             'base_legal'=>'Relatório de gestão interna (desempenho da equipa). Agente = quem cadastrou o mutuário no CIS ou a quem foi atribuído.'],
            ['id'=>'operadores','grupo'=>'Desempenho da equipa','titulo'=>'Gestores de crédito','periodo'=>'intervalo',
             'descricao'=>'Por gestor de crédito (utilizador que registou os contratos): desembolsos, recebido, carteira, atraso e incentivos.',
             'base_legal'=>'Relatório de gestão interna (desempenho da equipa).'],
            ['id'=>'evolucao','grupo'=>'Gestão','titulo'=>'Evolução mensal','periodo'=>'ano',
             'descricao'=>'Mês a mês: desembolsos, recebimentos, juros, carteira, PAR 30 e Caixa.',
             'base_legal'=>'Relatório de gestão interna (tendências e resultados).'],
            ['id'=>'caixa','grupo'=>'Gestão','titulo'=>'Caixa por conta','periodo'=>'intervalo',
             'descricao'=>'Entradas e saídas de cada conta no período, separadas por tipo de movimento.',
             'base_legal'=>'Relatório de gestão interna (tesouraria).'],
            ['id'=>'novocredito','grupo'=>'Gestão','titulo'=>'Mutuários para novo crédito','periodo'=>'nenhum',
             'descricao'=>'Score de crédito por pontualidade, com os limiares e limites das Configurações da empresa.',
             'base_legal'=>'Relatório de gestão interna (renovação e fidelização).'],
        ];
    }

    /** Utilizador e filtro de agência do pedido: aplicados a todos os relatórios. */
    private ?object $who=null;
    private mixed $ag=null;

    private function contratos($db=null)
    {
        $q=($db??DB::connection('mysql_legacy'))->table('Contractos');
        \App\Support\Branches::restrict($q,$this->who,$this->ag);
        return $q;
    }

    private function extracto($db=null)
    {
        $q=($db??DB::connection('mysql_legacy'))->table('Extracto');
        \App\Support\Branches::restrictExtracto($q,$this->who,$this->ag);
        return $q;
    }

    private function admin(Request $r): void
    {
        \App\Support\Permissions::require($r->user(),'relatorios');
    }

    private static function m(float $v): string { return number_format(round($v,2),2,'.',''); }
    private static function d($v): string { return substr((string)$v,0,10); }
    private static function pct(float $a,float $b): string { return $b>0?number_format($a/$b*100,1,'.',''):'0.0'; }

    /** Relatórios de desempenho da equipa: só com a permissão «desempenho» (administrador). */
    private static function teamReport(string $id): bool
    {
        return in_array($id,['operadores','carteira','agentes'],true);
    }

    public function index(Request $request)
    {
        $this->admin($request);
        $team=\App\Support\Permissions::can($request->user(),'desempenho');
        return response()->json(['relatorios'=>array_values(array_filter(self::catalog(),fn($d)=>$team||!self::teamReport($d['id'])))]);
    }

    public function show(Request $request,string $id)
    {
        $this->admin($request);
        $this->who=$request->user();$this->ag=$request->query('agencia');
        $def=collect(self::catalog())->firstWhere('id',$id);
        abort_unless($def,404,'Relatório desconhecido.');
        if (self::teamReport($id)) \App\Support\Permissions::require($request->user(),'desempenho');
        $today=now('Africa/Maputo')->toDateString();
        $p=$request->validate([
            'inicio'=>['nullable','date_format:Y-m-d'],'fim'=>['nullable','date_format:Y-m-d'],
            'data'=>['nullable','date_format:Y-m-d'],'ano'=>['nullable','integer','min:2000','max:2100'],
        ]);
        $ini=$p['inicio']??CarbonImmutable::parse($today)->startOfMonth()->toDateString();
        $fim=min($p['fim']??$today,$today);
        try {
            $out=match ($id) {
                'actividade'=>$this->actividade($p['inicio']??CarbonImmutable::parse($today)->startOfMonth()->toDateString(),min($p['fim']??$today,$today)),
                'crc'=>$this->crc(min($p['data']??$today,$today)),
                'branqueamento'=>$this->branqueamento((int)($p['ano']??now('Africa/Maputo')->year)),
                'identificacao'=>$this->identificacao(),
                'agenda'=>$this->agenda($p['inicio']??$today,$p['fim']??CarbonImmutable::parse($today)->addDays(30)->toDateString()),
                'atrasos'=>$this->atrasos(min($p['data']??$today,$today)),
                'operadores'=>$this->operadores($ini,$fim),
                'carteira'=>$this->carteira($ini,$fim),
                'abates'=>$this->abates($ini,$fim),
                'promessas'=>$this->promessas($ini,$fim),
                'agentes'=>$this->agentes($ini,$fim),
                'evolucao'=>$this->evolucao((int)($p['ano']??now('Africa/Maputo')->year)),
                'caixa'=>$this->caixa($ini,$fim),
                'novocredito'=>$this->novocredito(),
            };
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'Relatório indisponível (MySQL '.((int)($e->errorInfo[1]??0)).'). Detalhe no registo do servidor.');
        }
        $branch=\App\Support\Branches::scope($this->who,$this->ag);
        $doc=$def+$out+['agencia'=>$branch!==null?\App\Support\Branches::label($branch):null,'emitido_em'=>now('Africa/Maputo')->format('Y-m-d H:i'),'emitido_por'=>$request->user()?->name];
        $doc['codigo_verificacao']=strtoupper(substr(hash('sha256',json_encode([$id,$out],JSON_UNESCAPED_UNICODE)),0,16));
        return response()->json($doc)->header('Cache-Control','no-store, private');
    }

    // ------------------------------------------------------------------ dados base

    private function rows()
    {
        $cols=['Ordem','Nr do Contracto','Nome do Cliente','Nº de Cliente','Quantia Creditada','% de Juro','Prazo/Duração','Nº de Prestações',
            'Data de Inicio','Data de Cobrança','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Data de Pagamento','Estado','M. Pagamento','usuario'];
        return $this->contratos()
            ->selectRaw(implode(',',array_map(fn($c)=>'`'.$c.'`',$cols)))->orderBy('Ordem')->get();
    }

    /** Clientes (só colunas existentes): ID → dados de identificação. */
    private function clients(): array
    {
        $db=DB::connection('mysql_legacy');
        $have=array_flip($db->getSchemaBuilder()->getColumnListing('Clientes'));
        $want=['ID','Numeração','Nome Completo','Nº de BI','tipo_documento','validade_documento','nuit','Contacto','Bairro','Cidade','Provincia','Rua','genero','Nacionalidade','Estado Civil'];
        $cols=array_values(array_filter($want,fn($c)=>isset($have[$c])));
        $out=[];
        foreach ($db->table('Clientes')->get($cols) as $c) $out[(int)$c->ID]=$c;
        return $out;
    }

    private static function v(?object $o,string $k): string { return $o&&property_exists($o,$k)?trim((string)$o->$k):''; }

    /** Em dívida na data D: iniciada até D e não paga até D. */
    private static function outstandingAt(object $r,string $d): bool
    {
        $start=self::d($r->{'Data de Inicio'});
        if ($start==='' || $start>$d) return false;
        if (in_array($r->Estado,self::OPEN,true)) return true;
        $paid=self::d($r->{'Data de Pagamento'});
        return in_array($r->Estado,self::PAID,true) && $paid!=='' && $paid>$d;
    }

    private static function principal(object $r): float
    {
        return max(0,(float)$r->{'Valor a Pagar'}-(float)$r->{'Valor de Juros'});
    }

    // ------------------------------------------------------------------ 1) actividade

    private function actividade(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        $rows=$this->rows();
        $byC=$rows->groupBy('Nr do Contracto');
        // Desembolsos: contratos cuja 1.ª Data de Inicio cai no período.
        $disb=$byC->map(fn($c)=>['n'=>$c->first()->{'Nr do Contracto'},'cli'=>(int)$c->first()->{'Nº de Cliente'},'nome'=>$c->first()->{'Nome do Cliente'},
            'd'=>self::d($c->min('Data de Inicio')),'q'=>(float)$c->max('Quantia Creditada'),'tx'=>(float)$c->first()->{'% de Juro'}])
            ->filter(fn($c)=>$c['d']>=$ini && $c['d']<=$fim)->values();
        $firstByClient=$byC->map(fn($c)=>['cli'=>(int)$c->first()->{'Nº de Cliente'}?:$c->first()->{'Nome do Cliente'},'d'=>self::d($c->min('Data de Inicio'))])
            ->groupBy('cli')->map(fn($g)=>$g->min('d'));
        $novos=$firstByClient->filter(fn($d)=>$d>=$ini && $d<=$fim)->count();
        $inicio=CarbonImmutable::parse($ini);
        $duracao=(int)$inicio->diffInDays(CarbonImmutable::parse($fim))+1;
        $anteriorIni=$inicio->subDays($duracao)->toDateString();
        $anteriorFim=$inicio->subDay()->toDateString();
        $clientesPeriodo=$disb->map(fn($c)=>$c['cli']?:$c['nome'])->unique();
        $clientesAnteriores=$byC->filter(fn($c)=>($d=self::d($c->min('Data de Inicio')))>=$anteriorIni && $d<=$anteriorFim)
            ->map(fn($c)=>(int)$c->first()->{'Nº de Cliente'}?:$c->first()->{'Nome do Cliente'})->unique();
        $retidos=$clientesPeriodo->intersect($clientesAnteriores)->count();
        $refs=$this->reforcos($ini,$fim);
        // Carteira na data de fim.
        $out=$rows->filter(fn($r)=>self::outstandingAt($r,$fim));
        $cart=$out->sum(fn($r)=>self::principal($r));
        $owed=$out->sum(fn($r)=>(float)$r->{'Valor a Pagar'});
        $late=fn(int $days)=>$out->filter(fn($r)=>($due=self::d($r->{'Data de Cobrança'}))!=='' && CarbonImmutable::parse($due)->addDays($days)->toDateString()<$fim);
        $par1=$late(0);$par30=$late(30);$par90=$late(90);
        $parC=fn($set)=>$set->pluck('Nr do Contracto')->unique()->pipe(fn($n)=>$out->whereIn('Nr do Contracto',$n->all())->sum(fn($r)=>self::principal($r)));
        // Recebimentos no período (por Data de Pagamento).
        $paid=$rows->filter(fn($r)=>in_array($r->Estado,self::PAID,true) && ($pd=self::d($r->{'Data de Pagamento'}))>=$ini && $pd<=$fim);
        $jur=$paid->sum(fn($r)=>(float)$r->{'Valor de Juros'});$mul=$paid->sum(fn($r)=>(float)($r->{'Valor de Multa'}??0));
        $tot=$paid->sum(fn($r)=>(float)$r->{'Valor Pago'});$cap=max(0,$tot-$jur-$mul);
        $ren=$paid->where('Estado','Renovado');
        $activos=$out->map(fn($r)=>(int)$r->{'Nº de Cliente'}?:$r->{'Nome do Cliente'})->unique()->count();
        // Caixa (Extracto), excluindo transferências internas.
        $cx=$this->extracto()->whereBetween('Data da Operação',[$ini,$fim])
            ->whereRaw("NOT (`Descrição` LIKE '[TRANSFER %' OR `Descrição` LIKE '[ESTORNO TRANSFER %')")
            ->selectRaw("ROUND(SUM(CASE WHEN `Tipo de Movimento`='Entradas' THEN Valor ELSE 0 END),2) e, ROUND(SUM(CASE WHEN `Tipo de Movimento`<>'Entradas' THEN Valor ELSE 0 END),2) s")->first();
        // Distribuição dos desembolsos por montante.
        $bands=[['Até 5 000',0,5000],['5 001 – 25 000',5000.01,25000],['25 001 – 100 000',25000.01,100000],['Acima de 100 000',100000.01,INF]];
        $dist=array_map(function ($b) use ($disb) {
            $s=$disb->filter(fn($c)=>$c['q']>=$b[1] && $c['q']<=$b[2]);
            return [$b[0],$s->count(),self::m($s->sum('q')),self::pct($s->sum('q'),max(1,$disb->sum('q')))];
        },$bands);
        // Género (se existir no cadastro).
        $cl=$this->clients();
        $gen=$disb->groupBy(fn($c)=>($g=self::v($cl[$c['cli']]??null,'genero'))!==''?ucfirst(mb_strtolower($g)):'Não indicado')
            ->map(fn($g,$k)=>[$k,$g->count(),self::m($g->sum('q'))])->values()->all();
        $civil=$disb->groupBy(fn($c)=>$c['cli']?:$c['nome'])->map(fn($g)=>self::v($cl[$g->first()['cli']]??null,'Estado Civil')?:'Não indicado')
            ->countBy()->sortKeys()->map(fn($n,$estado)=>[$estado,$n])->values()->all();
        // Maiores exposições na data de fim (por cliente).
        $top=$out->groupBy(fn($r)=>(int)$r->{'Nº de Cliente'}?:$r->{'Nome do Cliente'})
            ->map(fn($g)=>[$g->first()->{'Nome do Cliente'},$g->pluck('Nr do Contracto')->unique()->count(),self::m($g->sum(fn($r)=>self::principal($r))),self::pct($g->sum(fn($r)=>self::principal($r)),max(1,$cart))])
            ->sortByDesc(fn($x)=>(float)$x[2])->take(10)->values()->all();
        $avgRate=$disb->count()?$disb->avg('tx'):0;
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'estado_civil_dashboard'=>$civil,
            'mutuarios_dashboard'=>[
                'com_desembolso'=>$clientesPeriodo->count(),
                'retencao'=>$clientesAnteriores->isEmpty()?null:self::pct($retidos,$clientesAnteriores->count()),
                'aquisicao'=>$clientesPeriodo->isEmpty()?null:self::pct($novos,$clientesPeriodo->count()),
            ],
            'nota'=>'Carteira e carteira em risco reconstituídas à data '.CarbonImmutable::parse($fim)->format('d/m/Y').' (prestação iniciada e não paga até essa data). Capital = Valor a Pagar − Juros.',
            'resumo'=>[
                ['Contratos desembolsados',(string)$disb->count()],['Valor desembolsado',self::m($disb->sum('q')+$refs->sum('v')).' MT'],
                ['Carteira em curso',self::m($cart).' MT'],['Saldo em dívida (com juros)',self::m($owed).' MT'],
                ['Carteira em risco a 30 dias (PAR 30)',self::pct($parC($par30),$cart).'%'],['Recebido no período',self::m($tot).' MT'],
                ['Mutuários activos',(string)$activos],['Mutuários novos',(string)$novos],
            ],
            'seccoes'=>[
                ['id'=>'desembolsos','titulo'=>'1. Desembolsos','colunas'=>['Indicador','Valor'],'linhas'=>[
                    ['Número de contratos desembolsados',(string)$disb->count()],
                    ['Valor desembolsado em contratos novos (MT)',self::m($disb->sum('q'))],
                    ['Reforços de crédito (n.º / MT)',$refs->count().' / '.self::m($refs->sum('v'))],
                    ['Total emprestado no período (MT)',self::m($disb->sum('q')+$refs->sum('v'))],
                    ['Montante médio por contrato (MT)',self::m($disb->count()?$disb->avg('q'):0)],
                    ['Taxa de juro média (% por período)',number_format($avgRate,2,'.','')],
                    ['Mutuários novos (primeiro contrato no período)',(string)$novos],
                ]],
                ['id'=>'carteira','titulo'=>'2. Carteira à data de '.CarbonImmutable::parse($fim)->format('d/m/Y'),'colunas'=>['Indicador','Valor'],'linhas'=>[
                    ['Contratos em curso',(string)$out->pluck('Nr do Contracto')->unique()->count()],
                    ['Mutuários activos',(string)$activos],
                    ['Carteira em curso — capital (MT)',self::m($cart)],
                    ['Saldo em dívida com juros (MT)',self::m($owed)],
                ]],
                ['id'=>'risco','titulo'=>'3. Carteira em risco (PAR)','tom'=>'risco','colunas'=>['Atraso','Prestações','Contratos','Capital dos contratos (MT)','% da carteira'],'linhas'=>[
                    ['Qualquer atraso',(string)$par1->count(),(string)$par1->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par1)),self::pct($parC($par1),$cart)],
                    ['Mais de 30 dias (PAR 30)',(string)$par30->count(),(string)$par30->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par30)),self::pct($parC($par30),$cart)],
                    ['Mais de 90 dias (PAR 90)',(string)$par90->count(),(string)$par90->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par90)),self::pct($parC($par90),$cart)],
                ]],
                ['id'=>'recebimentos','titulo'=>'4. Recebimentos no período','colunas'=>['Componente','Valor (MT)'],'linhas'=>[
                    ['Capital (amortização)',self::m($cap)],['Juros',self::m($jur)],['Juros de mora',self::m($mul)],['Total recebido',self::m($tot)],
                    ['Prestações pagas (n.º)',(string)$paid->count()],['Renovações (n.º)',(string)$ren->count()],['Juros de renovações',self::m($ren->sum(fn($r)=>(float)$r->{'Valor de Juros'}))],
                ]],
                ['id'=>'faixas','titulo'=>'5. Distribuição dos desembolsos por montante','colunas'=>['Faixa (MT)','Contratos','Valor (MT)','% do valor'],'linhas'=>$dist,'grafico'=>['Valor (MT)']],
                ['id'=>'genero','titulo'=>'6. Desembolsos por género','colunas'=>['Género','Contratos','Valor (MT)'],'linhas'=>$gen],
                ['id'=>'caixa','titulo'=>'7. Caixa no período (sem transferências internas)','colunas'=>['Movimento','Valor (MT)'],'linhas'=>[
                    ['Entradas',self::m((float)($cx->e??0))],['Saídas',self::m((float)($cx->s??0))],['Saldo do período',self::m((float)($cx->e??0)-(float)($cx->s??0))],
                ]],
                ['id'=>'exposicao','titulo'=>'8. Maiores exposições (10 mutuários)','colunas'=>['Mutuário','Contratos','Capital em curso (MT)','% da carteira'],'linhas'=>$top],
            ],
        ];
    }

    // ------------------------------------------------------------------ 2) CRC

    private function crc(string $d): array
    {
        $rows=$this->rows();$cl=$this->clients();
        $lines=[];$missing=0;$sum=0.0;$late=0;
        foreach ($rows->groupBy('Nr do Contracto') as $n=>$c) {
            $start=self::d($c->min('Data de Inicio'));
            if ($start==='' || $start>$d) continue;
            $out=$c->filter(fn($r)=>self::outstandingAt($r,$d));
            $lastPay=$c->filter(fn($r)=>in_array($r->Estado,self::PAID,true))->max('Data de Pagamento');
            if ($out->isEmpty() && !($lastPay && self::d($lastPay)>=CarbonImmutable::parse($d)->subDays(30)->toDateString())) continue; // só com saldo ou liquidados nos últimos 30 dias
            $first=$c->first();$cid=(int)$first->{'Nº de Cliente'};$cli=$cl[$cid]??null;
            $due=$out->filter(fn($r)=>($x=self::d($r->{'Data de Cobrança'}))!=='' && $x<$d);
            $days=$due->isEmpty()?0:(int)CarbonImmutable::parse(self::d($due->min('Data de Cobrança')))->diffInDays(CarbonImmutable::parse($d));
            $bi=self::v($cli,'Nº de BI');$nuit=self::v($cli,'nuit');
            $falta=array_values(array_filter([$bi===''?'BI':null,$nuit===''?'NUIT':null,self::v($cli,'Contacto')===''?'Contacto':null]));
            if ($falta) $missing++;
            $saldo=$out->sum(fn($r)=>self::principal($r));$sum+=$saldo;if ($days>0) $late++;
            $lines[]=[(string)$n,self::v($cli,'Nome Completo')?:(string)$first->{'Nome do Cliente'},$cid?(string)$cid:'',
                self::v($cli,'tipo_documento')?:($bi!==''?'BI':''),$bi,$nuit,$start,(string)$first->{'Prazo/Duração'},
                number_format((float)$first->{'% de Juro'},2,'.',''),self::m((float)$c->max('Quantia Creditada')),self::m($saldo),
                self::m($due->sum(fn($r)=>(float)$r->{'Valor a Pagar'}+(float)($r->{'Valor de Multa'}??0))),(string)$days,
                $out->isEmpty()?'Liquidado':($days>90?'Incumprimento (>90 dias)':($days>0?'Em atraso':'Regular')),
                $out->isEmpty()?self::d($lastPay):'',implode(', ',$falta)];
        }
        usort($lines,fn($a,$b)=>strcmp($a[1],$b[1]));
        return [
            'data_referencia'=>$d,
            'nota'=>'Modelo provisório: o formato oficial é definido por Circular do Banco de Moçambique (Aviso 12/GBM/2024, art. 11). O reporte deve ser feito até 24 horas após cada operação; este ficheiro dá a situação de todos os contratos à data indicada. Contratos liquidados nos últimos 30 dias incluídos.',
            'resumo'=>[['Contratos reportáveis',(string)count($lines)],['Saldo total (capital)',self::m($sum).' MT'],['Contratos em atraso',(string)$late],['Com identificação em falta',(string)$missing]],
            'seccoes'=>[['titulo'=>'Operações de crédito à data de '.CarbonImmutable::parse($d)->format('d/m/Y'),
                'colunas'=>['Contrato','Mutuário','N.º mutuário','Tipo documento','N.º documento','NUIT','Data desembolso','Prazo','Taxa (%)','Montante (MT)','Saldo capital (MT)','Valor vencido (MT)','Dias de atraso','Situação','Data liquidação','Dados em falta'],
                'linhas'=>$lines]],
        ];
    }

    // ------------------------------------------------------------------ 3) branqueamento

    private function branqueamento(int $ano): array
    {
        $rows=$this->rows();$cl=$this->clients();
        $ini="$ano-01-01";$fim="$ano-12-31";
        $per=[];
        $refBy=$this->reforcos($ini,$fim)->groupBy('c');
        foreach ($rows->groupBy('Nr do Contracto') as $num=>$c) {
            $first=$c->first();$key=(int)$first->{'Nº de Cliente'}?:(string)$first->{'Nome do Cliente'};
            $per[$key]??=['nome'=>(string)$first->{'Nome do Cliente'},'cid'=>(int)$first->{'Nº de Cliente'},'desemb'=>0.0,'receb'=>0.0,'meses'=>[],'contratos'=>0];
            foreach ($refBy[(string)$num]??[] as $rf) { $per[$key]['desemb']+=$rf['v'];$per[$key]['meses'][substr($rf['d'],0,7)]=($per[$key]['meses'][substr($rf['d'],0,7)]??0)+$rf['v']; }
            $d=self::d($c->min('Data de Inicio'));
            if ($d>=$ini && $d<=$fim) { $q=(float)$c->max('Quantia Creditada');$per[$key]['desemb']+=$q;$per[$key]['meses'][substr($d,0,7)]=($per[$key]['meses'][substr($d,0,7)]??0)+$q;$per[$key]['contratos']++; }
            foreach ($c as $r) {
                $pd=self::d($r->{'Data de Pagamento'});
                if (in_array($r->Estado,self::PAID,true) && $pd>=$ini && $pd<=$fim) { $v=(float)$r->{'Valor Pago'};$per[$key]['receb']+=$v;$per[$key]['meses'][substr($pd,0,7)]=($per[$key]['meses'][substr($pd,0,7)]??0)+$v; }
            }
        }
        $acima=[];$todos=[];$semId=0;
        foreach ($per as $p) {
            $tot=$p['desemb']+$p['receb'];if ($tot<=0) continue;
            $maxM=$p['meses']?max($p['meses']):0;$cli=$cl[$p['cid']]??null;
            $idOk=self::v($cli,'Nº de BI')!=='';if (!$idOk) $semId++;
            $row=[$p['nome'],$p['cid']?(string)$p['cid']:'',self::v($cli,'Nº de BI'),self::v($cli,'nuit'),(string)$p['contratos'],self::m($p['desemb']),self::m($p['receb']),self::m($tot),self::m($maxM),
                $maxM>self::SIMPLIFICADO_MES||$tot>self::SIMPLIFICADO_ANO?'Sim':'Não',$idOk?'Completa':'Em falta'];
            $todos[]=$row;
            if ($row[9]==='Sim') $acima[]=$row;
        }
        usort($todos,fn($a,$b)=>(float)$b[7]<=>(float)$a[7]);usort($acima,fn($a,$b)=>(float)$b[7]<=>(float)$a[7]);
        $cols=['Mutuário','N.º mutuário','BI','NUIT','Contratos no ano','Desembolsado (MT)','Recebido (MT)','Movimento total (MT)','Maior mês (MT)','Fora do regime simplificado','Identificação'];
        return [
            'ano'=>$ano,
            'nota'=>'Regime simplificado (Aviso 10/GBM/2024, art. 137): actividade até 30 000 MT por mês e 240 000 MT por ano. Mutuários acima destes limites exigem identificação completa e acompanhamento reforçado. Os dados anuais (art. 116) entregam-se até 31 de Janeiro do ano seguinte, em Excel (Anexo V).',
            'resumo'=>[['Mutuários com movimento',(string)count($todos)],['Fora do regime simplificado',(string)count($acima)],['Sem BI registado',(string)$semId],
                ['Movimento total',self::m(array_sum(array_map(fn($r)=>(float)$r[7],$todos))).' MT']],
            'seccoes'=>[
                ['titulo'=>'1. Mutuários fora do regime simplificado','colunas'=>$cols,'linhas'=>$acima],
                ['titulo'=>'2. Maiores movimentos por mutuário (20)','colunas'=>$cols,'linhas'=>array_slice($todos,0,20)],
            ],
        ];
    }

    // ------------------------------------------------------------------ 4) identificação

    private function identificacao(): array
    {
        $rows=$this->rows();$cl=$this->clients();$today=now('Africa/Maputo')->toDateString();
        $lines=[];$cnt=['BI'=>0,'NUIT'=>0,'Contacto'=>0,'Morada'=>0];$total=0;
        foreach ($rows->groupBy(fn($r)=>(int)$r->{'Nº de Cliente'}?:(string)$r->{'Nome do Cliente'}) as $k=>$g) {
            $total++;$cli=is_int($k)?($cl[$k]??null):null;
            $active=$g->contains(fn($r)=>in_array($r->Estado,self::OPEN,true));
            $morada=trim(implode(' ',[self::v($cli,'Rua'),self::v($cli,'Bairro'),self::v($cli,'Cidade')]));
            $falta=[];
            foreach (['BI'=>self::v($cli,'Nº de BI'),'NUIT'=>self::v($cli,'nuit'),'Contacto'=>self::v($cli,'Contacto'),'Morada'=>$morada] as $f=>$v) if ($v==='') { $falta[]=$f;$cnt[$f]++; }
            $val=self::v($cli,'validade_documento');
            if ($val!=='' && self::d($val)<$today) $falta[]='Documento caducado';
            if ($falta) $lines[]=[(string)$g->first()->{'Nome do Cliente'},is_int($k)&&$k?(string)$k:'',$active?'Sim':'Não',(string)$g->pluck('Nr do Contracto')->unique()->count(),implode(', ',$falta),$cli?'':'Mutuário não encontrado no cadastro'];
        }
        usort($lines,fn($a,$b)=>[$b[2],$a[0]]<=>[$a[2],$b[0]]);
        return [
            'nota'=>'Identificação mínima (Aviso 10/GBM/2024, art. 138): nome completo, tipo e número do documento e NUIB. O NUIB ainda não existe no cadastro do CIS. Mutuários com contratos activos aparecem primeiro.',
            'resumo'=>[['Mutuários com contratos',(string)$total],['Com dados em falta',(string)count($lines)],['Sem BI',(string)$cnt['BI']],['Sem NUIT',(string)$cnt['NUIT']],['Sem contacto',(string)$cnt['Contacto']]],
            'seccoes'=>[['titulo'=>'Mutuários com dados de identificação em falta','colunas'=>['Mutuário','N.º mutuário','Contrato activo','Contratos','Em falta','Observação'],'linhas'=>$lines]],
        ];
    }
    // ================================================================== GESTÃO

    private static function owed(object $r): float { return (float)$r->{'Valor a Pagar'}+(float)($r->{'Valor de Multa'}??0); }
    private static function contact(?object $cli): string { return self::v($cli,'Contacto'); }

    /** Folha de cobrança: prestações em aberto com vencimento no período (pode ser futuro). */
    private function agenda(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        abort_if(CarbonImmutable::parse($ini)->diffInDays(CarbonImmutable::parse($fim))>366,422,'Escolha um período até um ano.');
        $today=now('Africa/Maputo')->toDateString();
        $rows=$this->rows();$cl=$this->clients();
        $open=$rows->filter(fn($r)=>in_array($r->Estado,self::OPEN,true));
        $line=fn($r)=>[self::d($r->{'Data de Cobrança'}),(string)$r->{'Nome do Cliente'},self::contact($cl[(int)$r->{'Nº de Cliente'}]??null),(string)$r->{'Nr do Contracto'},
            (string)($r->{'M. Pagamento'}??''),self::m(self::owed($r))];
        $due=$open->filter(fn($r)=>($d=self::d($r->{'Data de Cobrança'}))>=$ini && $d<=$fim)->sortBy(fn($r)=>self::d($r->{'Data de Cobrança'}).$r->{'Nome do Cliente'});
        $late=$open->filter(fn($r)=>($d=self::d($r->{'Data de Cobrança'}))!=='' && $d<$today && $d<$ini)->sortBy(fn($r)=>self::d($r->{'Data de Cobrança'}));
        $weeks=$due->groupBy(fn($r)=>CarbonImmutable::parse(self::d($r->{'Data de Cobrança'}))->startOfWeek()->toDateString())
            ->map(fn($g,$w)=>['Semana de '.CarbonImmutable::parse($w)->format('d/m'),(string)$g->count(),self::m($g->sum(fn($r)=>self::owed($r)))])->values()->all();
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Valor = valor da prestação + juros de mora já lançados. As prestações em atraso por cobrar são as que venceram antes do início do período e não foram pagas.',
            'resumo'=>[['Prestações a vencer',(string)$due->count()],['Valor a cobrar',self::m($due->sum(fn($r)=>self::owed($r))).' MT'],
                ['Mutuários',(string)$due->pluck('Nº de Cliente')->unique()->count()],['Em atraso por cobrar',(string)$late->count()],['Valor vencido',self::m($late->sum(fn($r)=>self::owed($r))).' MT']],
            'seccoes'=>[
                ['titulo'=>'1. Previsão por semana','colunas'=>['Semana','Prestações','Valor (MT)'],'linhas'=>$weeks,'grafico'=>['Valor (MT)']],
                ['titulo'=>'2. Prestações a vencer','colunas'=>['Vencimento','Mutuário','Contacto','Contrato','Forma de pagamento','Valor (MT)'],'linhas'=>$due->map($line)->values()->all()],
                ['titulo'=>'3. Prestações em atraso por cobrar (antes do período)','colunas'=>['Vencimento','Mutuário','Contacto','Contrato','Forma de pagamento','Valor (MT)'],'linhas'=>$late->map($line)->values()->all()],
            ],
        ];
    }

    /** Antiguidade dos atrasos à data D. */
    private function atrasos(string $d): array
    {
        $rows=$this->rows();$cl=$this->clients();
        $bands=[['1 – 7 dias',1,7],['8 – 30 dias',8,30],['31 – 60 dias',31,60],['61 – 90 dias',61,90],['Mais de 90 dias (PAR 90)',91,PHP_INT_MAX]];
        $lines=[];$agg=array_fill(0,5,['p'=>0,'c'=>0,'v'=>0.0]);$total=0.0;
        foreach ($rows->groupBy('Nr do Contracto') as $n=>$c) {
            $over=$c->filter(fn($r)=>self::outstandingAt($r,$d) && ($x=self::d($r->{'Data de Cobrança'}))!=='' && $x<$d);
            if ($over->isEmpty()) continue;
            $days=(int)CarbonImmutable::parse(self::d($over->min('Data de Cobrança')))->diffInDays(CarbonImmutable::parse($d));
            $bi=0;foreach ($bands as $i=>$b) if ($days>=$b[1] && $days<=$b[2]) $bi=$i;
            $val=$over->sum(fn($r)=>self::owed($r));$total+=$val;
            $agg[$bi]['p']+=$over->count();$agg[$bi]['c']++;$agg[$bi]['v']+=$val;
            $last=$c->filter(fn($r)=>in_array($r->Estado,self::PAID,true) && self::d($r->{'Data de Pagamento'})<=$d)->max('Data de Pagamento');
            $first=$c->first();
            $lines[]=[(string)$first->{'Nome do Cliente'},self::contact($cl[(int)$first->{'Nº de Cliente'}]??null),(string)$n,(string)$over->count(),(string)$days,self::m($val),$bands[$bi][0],$last?self::d($last):'',(string)($first->usuario??'')];
        }
        usort($lines,fn($a,$b)=>(int)$b[4]<=>(int)$a[4]);
        $tab=[];foreach ($bands as $i=>$b) $tab[]=[$b[0],(string)$agg[$i]['p'],(string)$agg[$i]['c'],self::m($agg[$i]['v']),self::pct($agg[$i]['v'],$total)];
        return [
            'data_referencia'=>$d,
            'nota'=>'Dias de atraso contados desde a prestação em atraso mais antiga do contrato. Valor = valor da prestação + juros de mora já lançados.',
            'resumo'=>[['Contratos em atraso',(string)count($lines)],['Valor vencido',self::m($total).' MT'],['Mais de 30 dias (PAR 30)',self::m($agg[2]['v']+$agg[3]['v']+$agg[4]['v']).' MT'],['Mais de 90 dias (PAR 90)',self::m($agg[4]['v']).' MT']],
            'seccoes'=>[
                ['titulo'=>'1. Por faixa de atraso','colunas'=>['Faixa','Prestações','Contratos','Valor vencido (MT)','% do vencido'],'linhas'=>$tab,'grafico'=>['Valor vencido (MT)'],'tom'=>'risco'],
                ['titulo'=>'2. Contratos em atraso (mais antigos primeiro)','colunas'=>['Mutuário','Contacto','Contrato','Prestações em atraso','Dias de atraso','Valor vencido (MT)','Faixa','Último pagamento','Gestor de crédito'],'linhas'=>$lines],
            ],
        ];
    }

    /** Desempenho por gestor de crédito (utilizador que criou a primeira linha do contrato). */
    private function operadores(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        $rows=$this->rows();$per=[];
        $ckey=fn($r)=>(int)$r->{'Nº de Cliente'}?:(string)$r->{'Nome do Cliente'};
        $firstStart=[];foreach ($rows as $r) { $k=$ckey($r);$d=self::d($r->{'Data de Inicio'});if ($d!=='' && (!isset($firstStart[$k])||$d<$firstStart[$k])) $firstStart[$k]=$d; }
        foreach ($rows->groupBy('Nr do Contracto') as $c) {
            $first=$c->sortBy('Ordem')->first();$op=trim((string)($first->usuario??''))?:'(sem gestor)';
            $per[$op]??=['n'=>0,'v'=>0.0,'rec'=>0.0,'jur'=>0.0,'cart'=>0.0,'atr'=>0.0,'cli'=>[],'ativos'=>0,'novos'=>0,'ren'=>0];
            $start=self::d($c->min('Data de Inicio'));
            if ($start>=$ini && $start<=$fim) { $per[$op]['n']++; $per[$op]['v']+=(float)$c->max('Quantia Creditada'); if (($firstStart[$ckey($first)]??'')===$start) $per[$op]['novos']++; }
            foreach ($c as $r) if (in_array($r->Estado,self::PAID,true) && ($pd=self::d($r->{'Data de Pagamento'}))>=$ini && $pd<=$fim) {
                $per[$op]['rec']+=(float)$r->{'Valor Pago'};$per[$op]['jur']+=(float)$r->{'Valor de Juros'}+(float)($r->{'Valor de Multa'}??0);
                if ($r->Estado==='Renovado') $per[$op]['ren']++;
            }
            $out=$c->filter(fn($r)=>self::outstandingAt($r,$fim));
            if ($out->isNotEmpty()) {
                $per[$op]['ativos']++;$per[$op]['cli'][$ckey($first)]=1;
                $cap=$out->sum(fn($r)=>self::principal($r));$per[$op]['cart']+=$cap;
                $late30=$out->contains(fn($r)=>($x=self::d($r->{'Data de Cobrança'}))!=='' && CarbonImmutable::parse($x)->addDays(30)->toDateString()<$fim);
                if ($late30) $per[$op]['atr']+=$cap;
            }
        }
        // Reforços: contam para quem os registou.
        foreach ($this->reforcos($ini,$fim) as $rf) {
            $op=$rf['u']!==''?$rf['u']:'(sem gestor)';
            $per[$op]??=['n'=>0,'v'=>0.0,'rec'=>0.0,'jur'=>0.0,'cart'=>0.0,'atr'=>0.0,'cli'=>[],'ativos'=>0,'novos'=>0,'ren'=>0];
            $per[$op]['v']+=$rf['v'];
        }
        $iN=CS::num('incentivo_cliente_novo');$iR=CS::num('incentivo_renovacao');$iC=CS::num('incentivo_taxa_capital');$iJ=CS::num('incentivo_taxa_juros');
        $inc=fn($x)=>round($x['novos']*$iN+$x['ren']*$iR+$x['cart']*$iC/100+$x['jur']*$iJ/100,2);
        $lines=[];foreach ($per as $op=>$x) $lines[]=[$op,(string)$x['n'],self::m($x['v']),self::m($x['rec']),self::m($x['jur']),(string)$x['ativos'],(string)count($x['cli']),self::m($x['cart']),self::pct($x['atr'],$x['cart']),(string)$x['novos'],(string)$x['ren'],self::m($inc($x))];
        usort($lines,fn($a,$b)=>(float)$b[7]<=>(float)$a[7]);
        $configured=$iN>0||$iR>0||$iC>0||$iJ>0;
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Gestor de crédito = utilizador que registou o contrato (campo «usuario» da primeira prestação). Carteira e atraso à data final do período. '
                .($configured?sprintf('Incentivo = mutuários novos × %s + renovações × %s + %s%% da carteira + %s%% dos juros e juros de mora recebidos (Definições › Incentivos).',self::m($iN),self::m($iR),rtrim(rtrim(number_format($iC,4,'.',''),'0'),'.'),rtrim(rtrim(number_format($iJ,4,'.',''),'0'),'.'))
                    :'Incentivos por configurar (Definições › Incentivos).'),
            'resumo'=>[['Gestores de crédito',(string)count($lines)],['Desembolsado no período',self::m(array_sum(array_column($per,'v'))).' MT'],['Recebido no período',self::m(array_sum(array_column($per,'rec'))).' MT'],['Carteira em curso',self::m(array_sum(array_column($per,'cart'))).' MT'],['Incentivos estimados',self::m(array_sum(array_map($inc,$per))).' MT']],
            'seccoes'=>[['titulo'=>'Desempenho por gestor de crédito','colunas'=>['Gestor de crédito','Contratos desembolsados','Desembolsado (MT)','Recebido (MT)','Juros e mora (MT)','Contratos em curso','Mutuários activos','Carteira (MT)','PAR 30 (%)','Mutuários novos','Renovações','Incentivo estimado (MT)'],'linhas'=>$lines]],
        ];
    }

    /** Reforços de crédito (saída «Aumento de Crédito» na Caixa) num intervalo, na agência/carteira do pedido. */
    private function reforcos(string $ini,string $fim)
    {
        return $this->extracto()->where('Tipo de Movimento','Saídas')->where('Descrição','like','%Aumento de Crédito%')
            ->whereBetween('Data da Operação',[$ini,$fim])->get(['Contracto','Valor','usuario','Data da Operação as d'])
            ->map(fn($m)=>['c'=>(string)$m->Contracto,'v'=>(float)$m->Valor,'u'=>trim((string)$m->usuario),'d'=>self::d($m->d)]);
    }

    /** Contactos de cobrança e promessas de pagamento. */
    private function promessas(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        abort_unless(\App\Http\Controllers\Api\CollectionContactController::ready(),503,'Contactos e promessas ainda não instalados.');
        $nums=$this->rows()->pluck('Nr do Contracto')->unique()->map(fn($n)=>(string)$n)->values()->all();
        \App\Http\Controllers\Api\CollectionContactController::evaluate($nums);
        $c=DB::table('cis_collection_contacts')->whereIn('contract_number',$nums?:['__none__'])
            ->whereBetween('created_at',[$ini.' 00:00:00',$fim.' 23:59:59'])->get();
        $names=self::userNames();$per=[];
        foreach ($c as $x) {
            $k=$names[(int)$x->user_id]??'—';
            $per[$k]??=['n'=>0,'p'=>0,'ok'=>0,'ko'=>0,'pend'=>0,'v'=>0.0,'pago'=>0.0];
            $per[$k]['n']++;
            if ($x->promessa_estado) {
                $per[$k]['p']++;$per[$k]['v']+=(float)$x->promessa_valor;$per[$k]['pago']+=(float)($x->promessa_pago??0);
                if ($x->promessa_estado==='cumprida') $per[$k]['ok']++;
                elseif ($x->promessa_estado==='falhada') $per[$k]['ko']++;
                elseif ($x->promessa_estado==='pendente') $per[$k]['pend']++;
            }
        }
        $lines=[];foreach ($per as $k=>$x) $lines[]=[$k,(string)$x['n'],(string)$x['p'],(string)$x['ok'],(string)$x['ko'],(string)$x['pend'],self::pct($x['ok'],$x['ok']+$x['ko']),self::m($x['v']),self::m($x['pago'])];
        usort($lines,fn($a,$b)=>(int)$b[1]<=>(int)$a[1]);
        $client=$this->rows()->groupBy('Nr do Contracto')->map(fn($g)=>(string)$g->first()->{'Nome do Cliente'});
        $failed=$c->where('promessa_estado','falhada')->sortByDesc('promessa_data')->map(fn($x)=>[self::d($x->promessa_data),(string)$x->contract_number,$client[$x->contract_number]??'',
            self::m((float)$x->promessa_valor),self::m((float)($x->promessa_pago??0)),$names[(int)$x->user_id]??'—'])->values()->all();
        $ok=$c->where('promessa_estado','cumprida')->count();$ko=$c->where('promessa_estado','falhada')->count();
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Promessa cumprida = entradas no Extracto do contrato, desde o contacto até à data prometida, iguais ou superiores ao valor prometido. Taxa de cumprimento = cumpridas ÷ (cumpridas + falhadas).',
            'resumo'=>[['Contactos registados',(string)$c->count()],['Promessas',(string)$c->whereNotNull('promessa_estado')->count()],['Cumpridas',(string)$ok],['Falhadas',(string)$ko],
                ['Taxa de cumprimento',self::pct($ok,$ok+$ko).' %']],
            'seccoes'=>[
                ['titulo'=>'Por quem registou','colunas'=>['Utilizador','Contactos','Promessas','Cumpridas','Falhadas','Pendentes','Cumprimento (%)','Prometido (MT)','Recebido (MT)'],'linhas'=>$lines],
                ['titulo'=>'Promessas falhadas','colunas'=>['Data prometida','Contrato','Mutuário','Prometido (MT)','Recebido (MT)','Registado por'],'linhas'=>$failed],
            ],
        ];
    }

    /** Abates de crédito (histórico imutável) e recuperações. */
    private function abates(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        $db=DB::connection('mysql_legacy');
        abort_unless($db->getSchemaBuilder()->hasTable('cis_contractos_historico'),503,'Histórico de contratos não instalado.');
        $rows=$this->rows();
        $byC=$rows->groupBy('Nr do Contracto');
        $names=self::userNames();
        $hist=$db->table('cis_contractos_historico')->whereIn('operation',['abate_credito','recuperacao_credito','anulacao_abate'])
            ->whereIn('contract_number',$byC->keys()->map(fn($k)=>(string)$k)->all()?:['__none__'])->orderBy('id')
            ->get(['id','contract_number','operation','amount','reason','operation_key','performed_by','performed_at']);
        $events=[];$recs=[];
        foreach ($hist->groupBy('contract_number') as $num=>$h) {
            $cur=null;
            foreach ($h->groupBy('operation_key')->sortBy(fn($g)=>$g->min('id')) as $g) {
                $op=$g->first()->operation;
                if ($op==='abate_credito') {
                    $date=preg_match('#Abate em (\d{2})/(\d{2})/(\d{4})#u',(string)$g->first()->reason,$m)?"$m[3]-$m[2]-$m[1]":substr((string)$g->first()->performed_at,0,10);
                    $events[]=['c'=>(string)$num,'d'=>$date,'v'=>(float)$g->sum(fn($x)=>(float)($x->amount??0)),'rec'=>0.0,'anulado'=>false,
                        'mot'=>preg_replace('#^Abate em [^:]*: #u','',(string)$g->first()->reason),'por'=>$names[(int)$g->first()->performed_by]??'—'];
                    $cur=count($events)-1;
                } elseif ($op==='anulacao_abate' && $cur!==null) {
                    $events[$cur]['anulado']=true;$cur=null;
                } elseif ($op==='recuperacao_credito') {
                    $v=(float)$g->sum(fn($x)=>(float)($x->amount??0));
                    if ($cur!==null) $events[$cur]['rec']+=$v;
                    $recs[]=['c'=>(string)$num,'d'=>substr((string)$g->first()->performed_at,0,10),'v'=>$v,'por'=>$names[(int)$g->first()->performed_by]??'—'];
                }
            }
        }
        // Datas das recuperações: a do lançamento no Extracto quando existe.
        $exDates=$this->extracto($db)->where('Tipo de Movimento','Entradas')->where('Descrição','like','%Recuperação de Crédito%')
            ->get(['Contracto','Valor','Data da Operação as d']);
        foreach ($recs as &$r) {
            $hit=$exDates->first(fn($e)=>(string)$e->Contracto===$r['c'] && abs((float)$e->Valor-$r['v'])<0.005);
            if ($hit) $r['d']=self::d($hit->d);
        }
        unset($r);
        $inP=array_values(array_filter($events,fn($e)=>$e['d']>=$ini && $e['d']<=$fim));
        $recP=array_values(array_filter($recs,fn($r)=>$r['d']>=$ini && $r['d']<=$fim));
        $client=fn($c)=>(string)($byC[$c]??collect())->first()?->{'Nome do Cliente'};
        $l1=array_map(fn($e)=>[$e['d'],$e['c'],$client($e['c']),self::m($e['v']),self::m($e['rec']),self::m($e['anulado']?0:max(0,$e['v']-$e['rec'])),
            $e['anulado']?'Anulado':($e['rec']>=$e['v']-0.005?'Recuperado':($e['rec']>0?'Recuperação parcial':'Abatido')),$e['mot'],$e['por']],$inP);
        $l2=array_map(fn($r)=>[$r['d'],$r['c'],$client($r['c']),self::m($r['v']),$r['por']],$recP);
        $valid=array_filter($inP,fn($e)=>!$e['anulado']);
        $tv=array_sum(array_column($valid,'v'));$tr=array_sum(array_column($valid,'rec'));
        $allValid=array_filter($events,fn($e)=>!$e['anulado']);
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Abate = saldo em dívida retirado da carteira activa por ser considerado incobrável (sem movimento de Caixa). Recuperação = valor recebido depois do abate (entrada na Caixa). Abates anulados não contam nos totais.',
            'resumo'=>[['Contratos abatidos no período',(string)count($valid)],['Valor abatido no período',self::m($tv).' MT'],
                ['Recuperado desses abates',self::m($tr).' MT ('.self::pct($tr,$tv).' %)'],['Recuperações recebidas no período',self::m(array_sum(array_column($recP,'v'))).' MT'],
                ['Saldo abatido por recuperar (total)',self::m(array_sum(array_map(fn($e)=>max(0,$e['v']-$e['rec']),$allValid))).' MT']],
            'seccoes'=>[
                ['titulo'=>'Abates no período','colunas'=>['Data','Contrato','Mutuário','Abatido (MT)','Recuperado (MT)','Saldo (MT)','Situação','Motivo','Registado por'],'linhas'=>$l1],
                ['titulo'=>'Recuperações recebidas no período','colunas'=>['Data','Contrato','Mutuário','Valor (MT)','Registado por'],'linhas'=>$l2],
            ],
        ];
    }

    /** Nomes dos utilizadores do CIS. */
    private static function userNames(): array
    {
        return DB::table('users')->pluck('name','id')->map(fn($n)=>(string)$n)->all();
    }

    /** Desempenho por gestor de carteira (responsável atribuído a cada contrato). */
    private function carteira(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        abort_unless(\App\Support\Portfolio::ready(),503,'Carteiras ainda não instaladas. Na VPS execute: bash deploy/install-user-management.sh');
        $owners=DB::table('cis_contract_owner')->pluck('user_id','contrato')->map(fn($v)=>(int)$v)->all();
        $names=self::userNames();
        $per=[];
        foreach ($this->rows()->groupBy('Nr do Contracto') as $num=>$c) {
            $uid=$owners[(string)$num]??0;
            $k=$uid?($names[$uid]??'Utilizador #'.$uid):'(sem gestor de carteira)';
            $per[$k]??=['n'=>0,'act'=>0,'cli'=>[],'cart'=>0.0,'venc'=>0.0,'atr30'=>0.0,'devido'=>0.0,'devido_pago'=>0.0,'cobrado'=>0.0,'sem'=>$uid===0];
            $x=&$per[$k];$x['n']++;
            $out=$c->filter(fn($r)=>self::outstandingAt($r,$fim));
            if ($out->isNotEmpty()) {
                $x['act']++;$x['cli'][(int)$c->first()->{'Nº de Cliente'}?:(string)$c->first()->{'Nome do Cliente'}]=1;
                $cap=$out->sum(fn($r)=>self::principal($r));$x['cart']+=$cap;
                $x['venc']+=$out->filter(fn($r)=>($d=self::d($r->{'Data de Cobrança'}))!=='' && $d<$fim)->sum(fn($r)=>(float)$r->{'Valor a Pagar'});
                if ($out->contains(fn($r)=>($d=self::d($r->{'Data de Cobrança'}))!=='' && CarbonImmutable::parse($d)->addDays(30)->toDateString()<$fim)) $x['atr30']+=$cap;
            }
            foreach ($c as $r) {
                $due=self::d($r->{'Data de Cobrança'});$pd=self::d($r->{'Data de Pagamento'});$paid=in_array($r->Estado,self::PAID,true);
                if ($due>=$ini && $due<=$fim && $r->Estado!=='Renovado') {
                    $x['devido']+=(float)$r->{'Valor a Pagar'};
                    if ($paid && $pd!=='' && $pd<=$fim) $x['devido_pago']+=(float)$r->{'Valor a Pagar'};
                }
                if ($paid && $pd>=$ini && $pd<=$fim) $x['cobrado']+=(float)$r->{'Valor Pago'};
            }
            unset($x);
        }
        $lines=[];
        foreach ($per as $k=>$x) $lines[]=[$k,(string)$x['n'],(string)$x['act'],(string)count($x['cli']),self::m($x['cart']),self::m($x['venc']),self::pct($x['atr30'],$x['cart']),self::m($x['devido']),self::m($x['cobrado']),self::pct($x['devido_pago'],$x['devido'])];
        usort($lines,fn($a,$b)=>[str_starts_with($a[0],'(')?1:0,-(float)$a[4]]<=>[str_starts_with($b[0],'(')?1:0,-(float)$b[4]]);
        $sem=array_values(array_filter($per,fn($x)=>$x['sem']));
        $tot=fn($f)=>array_sum(array_column($per,$f));
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Gestor de carteira = responsável atribuído ao contrato. Carteira (capital em dívida), valor em atraso e PAR 30 à data final. '
                .'«A cobrar» = prestações com vencimento no período; taxa de cobrança = parte dessas prestações já paga até à data final. «Cobrado» = tudo o que foi pago no período nos contratos da carteira.',
            'resumo'=>[['Gestores de carteira',(string)count(array_filter($per,fn($x)=>!$x['sem']))],['Carteira em curso',self::m($tot('cart')).' MT'],['Em atraso',self::m($tot('venc')).' MT'],
                ['Cobrado no período',self::m($tot('cobrado')).' MT'],['Taxa de cobrança',self::pct($tot('devido_pago'),$tot('devido')).' %'],
                ['Contratos em curso sem gestor',(string)($sem[0]['act']??0)]],
            'seccoes'=>[['titulo'=>'Desempenho por gestor de carteira','colunas'=>['Gestor de carteira','Contratos','Em curso','Mutuários activos','Carteira (MT)','Em atraso (MT)','PAR 30 (%)','A cobrar no período (MT)','Cobrado no período (MT)','Taxa de cobrança (%)'],'linhas'=>$lines]],
        ];
    }

    /** Desempenho por agente (quem cadastrou ou a quem foi atribuído o mutuário). */
    private function agentes(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        abort_unless(\App\Support\Portfolio::ready(),503,'Carteiras ainda não instaladas. Na VPS execute: bash deploy/install-user-management.sh');
        $own=DB::table('cis_client_owner')->get(['client_id','user_id','created_at']);
        $names=self::userNames();
        $byClient=[];$per=[];
        foreach ($own as $o) {
            $byClient[(int)$o->client_id]=(int)$o->user_id;
            $k=$names[(int)$o->user_id]??'Utilizador #'.$o->user_id;
            $per[$k]??=['cli'=>0,'novos'=>0,'com'=>[],'n'=>0,'v'=>0.0,'cart'=>0.0,'atr30'=>0.0];
            $per[$k]['cli']++;
            $d=substr((string)$o->created_at,0,10);
            if ($d>=$ini && $d<=$fim) $per[$k]['novos']++;
        }
        $refAg=$this->reforcos($ini,$fim)->groupBy('c')->map(fn($g)=>$g->sum('v'))->all();
        foreach ($this->rows()->groupBy('Nr do Contracto') as $c) {
            $cid=(int)$c->first()->{'Nº de Cliente'};
            if (!isset($byClient[$cid])) continue;
            $k=$names[$byClient[$cid]]??'Utilizador #'.$byClient[$cid];
            $x=&$per[$k];$x['com'][$cid]=1;
            $start=self::d($c->min('Data de Inicio'));
            if ($start>=$ini && $start<=$fim) { $x['n']++;$x['v']+=(float)$c->max('Quantia Creditada'); }
            $x['v']+=(float)($refAg[(string)$c->first()->{'Nr do Contracto'}]??0);
            $out=$c->filter(fn($r)=>self::outstandingAt($r,$fim));
            if ($out->isNotEmpty()) {
                $cap=$out->sum(fn($r)=>self::principal($r));$x['cart']+=$cap;
                if ($out->contains(fn($r)=>($d=self::d($r->{'Data de Cobrança'}))!=='' && CarbonImmutable::parse($d)->addDays(30)->toDateString()<$fim)) $x['atr30']+=$cap;
            }
            unset($x);
        }
        $lines=[];
        foreach ($per as $k=>$x) $lines[]=[$k,(string)$x['cli'],(string)$x['novos'],(string)count($x['com']),self::pct(count($x['com']),$x['cli']),(string)$x['n'],self::m($x['v']),self::m($x['cart']),self::pct($x['atr30'],$x['cart'])];
        usort($lines,fn($a,$b)=>(float)$b[6]<=>(float)$a[6] ?: (int)$b[1]<=>(int)$a[1]);
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Agente = utilizador que cadastrou o mutuário no CIS (ou a quem foi atribuído na ficha). Mutuários do sistema antigo só contam depois de atribuídos. Carteira e PAR 30 à data final.',
            'resumo'=>[['Agentes',(string)count($per)],['Mutuários das carteiras',(string)array_sum(array_column($per,'cli'))],['Novos no período',(string)array_sum(array_column($per,'novos'))],
                ['Desembolsado no período',self::m(array_sum(array_column($per,'v'))).' MT']],
            'seccoes'=>[['titulo'=>'Desempenho por agente','colunas'=>['Agente','Mutuários','Novos no período','Com contrato','Conversão (%)','Contratos desembolsados','Desembolsado (MT)','Carteira (MT)','PAR 30 (%)'],'linhas'=>$lines]],
        ];
    }

    /** Evolução mensal no ano (até ao mês actual). */
    private function evolucao(int $ano): array
    {
        $rows=$this->rows();$today=now('Africa/Maputo')->toDateString();
        $starts=$rows->groupBy('Nr do Contracto')->map(fn($c)=>['d'=>self::d($c->min('Data de Inicio')),'q'=>(float)$c->max('Quantia Creditada')]);
        $cx=$this->extracto()->whereYear('Data da Operação',$ano)
            ->whereRaw("NOT (`Descrição` LIKE '[TRANSFER %' OR `Descrição` LIKE '[ESTORNO TRANSFER %')")
            ->selectRaw("MONTH(`Data da Operação`) m, ROUND(SUM(CASE WHEN `Tipo de Movimento`='Entradas' THEN Valor ELSE 0 END),2) e, ROUND(SUM(CASE WHEN `Tipo de Movimento`<>'Entradas' THEN Valor ELSE 0 END),2) s")
            ->groupBy('m')->get()->keyBy('m');
        $refY=$this->reforcos("$ano-01-01","$ano-12-31");
        $names=['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
        $lines=[];$tot=['n'=>0,'v'=>0.0,'rec'=>0.0,'jur'=>0.0,'mul'=>0.0];
        for ($m=1;$m<=12;$m++) {
            $a=sprintf('%04d-%02d-01',$ano,$m);if ($a>$today) break;
            $z=min(CarbonImmutable::parse($a)->endOfMonth()->toDateString(),$today);
            $dis=$starts->filter(fn($c)=>$c['d']>=$a && $c['d']<=$z);
            $refM=$refY->filter(fn($x)=>$x['d']>=$a && $x['d']<=$z)->sum('v');
            $paid=$rows->filter(fn($r)=>in_array($r->Estado,self::PAID,true) && ($pd=self::d($r->{'Data de Pagamento'}))>=$a && $pd<=$z);
            $rec=$paid->sum(fn($r)=>(float)$r->{'Valor Pago'});$jur=$paid->sum(fn($r)=>(float)$r->{'Valor de Juros'});$mul=$paid->sum(fn($r)=>(float)($r->{'Valor de Multa'}??0));
            $out=$rows->filter(fn($r)=>self::outstandingAt($r,$z));$cart=$out->sum(fn($r)=>self::principal($r));
            $n30=$out->filter(fn($r)=>($x=self::d($r->{'Data de Cobrança'}))!=='' && CarbonImmutable::parse($x)->addDays(30)->toDateString()<$z)->pluck('Nr do Contracto')->unique();
            $par=$out->whereIn('Nr do Contracto',$n30->all())->sum(fn($r)=>self::principal($r));
            $c=$cx[$m]??null;
            $lines[]=[$names[$m-1],(string)$dis->count(),self::m($dis->sum('q')+$refM),self::m($rec),self::m($jur),self::m($mul),self::m($cart),self::pct($par,$cart),self::m((float)($c->e??0)),self::m((float)($c->s??0))];
            $tot['n']+=$dis->count();$tot['v']+=$dis->sum('q')+$refM;$tot['rec']+=$rec;$tot['jur']+=$jur;$tot['mul']+=$mul;
        }
        $lines[]=['Total do ano',(string)$tot['n'],self::m($tot['v']),self::m($tot['rec']),self::m($tot['jur']),self::m($tot['mul']),'','','',''];
        return [
            'ano'=>$ano,
            'nota'=>'Carteira e carteira em risco (> 30 dias) no fim de cada mês. Caixa sem transferências internas.',
            'resumo'=>[['Contratos desembolsados',(string)$tot['n']],['Valor desembolsado',self::m($tot['v']).' MT'],['Recebido',self::m($tot['rec']).' MT'],['Juros recebidos',self::m($tot['jur']).' MT'],['Juros de mora recebidos',self::m($tot['mul']).' MT']],
            'seccoes'=>[['id'=>'mensal','titulo'=>'Evolução mensal de '.$ano,'colunas'=>['Mês','Contratos','Desembolsado (MT)','Recebido (MT)','Juros (MT)','Juros de mora (MT)','Carteira no fim (MT)','PAR 30 (%)','Entradas Caixa (MT)','Saídas Caixa (MT)'],'linhas'=>$lines,'grafico'=>['Desembolsado (MT)','Recebido (MT)'],'total_final'=>true]],
        ];
    }

    /** Caixa por conta e por tipo de movimento. */
    private function caixa(string $ini,string $fim): array
    {
        abort_if($fim<$ini,422,'A data final tem de ser igual ou posterior à inicial.');
        $db=DB::connection('mysql_legacy');
        $hasAcc=$db->getSchemaBuilder()->hasColumn('Extracto','account_id');
        $sel=['Tipo de Movimento','Descrição','Valor'];if ($hasAcc) $sel[]='account_id';
        $mov=$this->extracto($db)->whereBetween('Data da Operação',[$ini,$fim])->get($sel);
        $names=[];
        if ($hasAcc) { try { $names=DB::table('cis_cash_accounts')->get(['id','code','name'])->keyBy('id')->map(fn($a)=>$a->code.' · '.$a->name)->all(); } catch (\Throwable $e) { report($e); } }
        $type=function ($m) {
            $d=(string)$m->{'Descrição'};
            if (str_starts_with($d,'[TRANSFER') || str_starts_with($d,'[ESTORNO TRANSFER')) return 'Transferências internas';
            if (str_contains($d,'[CAIXA:COBRANCA]') || stripos($d,'Receita de Juros')!==false || stripos($d,'Pagamento')!==false || stripos($d,'Recuperação de Crédito')!==false) return 'Cobranças';
            if (str_contains($d,'[CAIXA:DESEMBOLSO]') || stripos($d,'Desembolso')!==false || stripos($d,'Aumento de Crédito')!==false) return 'Desembolsos';
            return 'Outros';
        };
        $acc=[];$byType=[];
        foreach ($mov as $m) {
            $a=$hasAcc&&$m->account_id?($names[$m->account_id]??'Conta '.$m->account_id):'Sem conta (histórico)';
            $in=$m->{'Tipo de Movimento'}==='Entradas';$v=(float)$m->Valor;$t=$type($m);
            $acc[$a]??=['e'=>0.0,'s'=>0.0,'n'=>0];$acc[$a][$in?'e':'s']+=$v;$acc[$a]['n']++;
            $byType[$t]??=['e'=>0.0,'s'=>0.0,'n'=>0];$byType[$t][$in?'e':'s']+=$v;$byType[$t]['n']++;
        }
        $l1=[];foreach ($acc as $a=>$x) $l1[]=[$a,(string)$x['n'],self::m($x['e']),self::m($x['s']),self::m($x['e']-$x['s'])];
        $l2=[];foreach ($byType as $t=>$x) $l2[]=[$t,(string)$x['n'],self::m($x['e']),self::m($x['s']),self::m($x['e']-$x['s'])];
        usort($l1,fn($a,$b)=>strcmp($a[0],$b[0]));
        $E=array_sum(array_column($acc,'e'));$S=array_sum(array_column($acc,'s'));
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Tipo de movimento deduzido da descrição do Extracto. As transferências internas entram e saem entre contas (o total do CIS não muda).',
            'resumo'=>[['Movimentos',(string)count($mov)],['Entradas',self::m($E).' MT'],['Saídas',self::m($S).' MT'],['Saldo do período',self::m($E-$S).' MT']],
            'seccoes'=>[
                ['titulo'=>'1. Por conta','colunas'=>['Conta','Movimentos','Entradas (MT)','Saídas (MT)','Saldo do período (MT)'],'linhas'=>$l1],
                ['titulo'=>'2. Por tipo de movimento','colunas'=>['Tipo','Movimentos','Entradas (MT)','Saídas (MT)','Saldo do período (MT)'],'linhas'=>$l2],
            ],
        ];
    }

    /** Clientes que liquidaram todos os contratos: candidatos a novo crédito. */
    private function novocredito(): array
    {
        $rows=$this->rows();$cl=$this->clients();$lines=[];$today=now('Africa/Maputo')->toDateString();
        $min=(int)CS::get('score_minimo');$low=(int)CS::get('score_baixo_risco');$mid=(int)CS::get('score_risco_medio');
        $maxAct=max(1,(int)CS::get('max_creditos_activos'));$maxLate=(int)CS::get('max_creditos_atraso');
        foreach ($rows->groupBy(fn($r)=>(int)$r->{'Nº de Cliente'}?:(string)$r->{'Nome do Cliente'}) as $k=>$g) {
            $open=$g->filter(fn($r)=>in_array($r->Estado,self::OPEN,true));
            $openC=$open->pluck('Nr do Contracto')->unique()->count();
            $lateC=$open->filter(fn($r)=>($x=self::d($r->{'Data de Cobrança'}))!=='' && $x<$today)->pluck('Nr do Contracto')->unique()->count();
            // Pode pedir um novo crédito se, com ele, não ultrapassar o máximo de créditos activos.
            if ($openC>=$maxAct || $lateC>$maxLate) continue;
            $paid=$g->filter(fn($r)=>in_array($r->Estado,self::PAID,true));if ($paid->isEmpty()) continue;
            $late=$paid->where('Estado','Pago em Atraso')->count();$n=$paid->count();
            $score=(int)round(100*($n-$late)/$n)-($lateC>0?20:0);$score=max(0,$score);
            $class=$score>=$low?'Baixo risco':($score>=$mid?'Risco médio':'Alto risco');
            $cli=is_int($k)?($cl[$k]??null):null;
            $lines[]=[(string)$g->first()->{'Nome do Cliente'},self::contact($cli),(string)$g->pluck('Nr do Contracto')->unique()->count(),self::m($g->groupBy('Nr do Contracto')->sum(fn($c)=>(float)$c->max('Quantia Creditada'))),
                (string)$n,(string)$late,self::d($paid->max('Data de Pagamento')),(string)$score,$class,$score>=$min?'Sim':'Não',(string)$openC];
        }
        $ord=['Baixo risco','Risco médio','Alto risco'];
        usort($lines,fn($a,$b)=>[array_search($a[8],$ord),-(int)$a[7],$b[6]]<=>[array_search($b[8],$ord),-(int)$b[7],$a[6]]);
        $cnt=array_count_values(array_column($lines,8));$ok=count(array_filter($lines,fn($l)=>$l[9]==='Sim'));
        return [
            'nota'=>"Score = % de prestações pagas sem atraso (−20 se tiver crédito activo em atraso). Baixo risco ≥ $low; risco médio ≥ $mid; aprovável com score ≥ $min. "
                ."Entram mutuários com menos de $maxAct crédito(s) activo(s) e no máximo $maxLate em atraso (Definições › Credit scoring). A decisão final é do gestor de crédito.",
            'resumo'=>[['Mutuários avaliados',(string)count($lines)],['Aprováveis (score ≥ '.$min.')',(string)$ok],['Baixo risco',(string)($cnt['Baixo risco']??0)],['Risco médio',(string)($cnt['Risco médio']??0)],['Alto risco',(string)($cnt['Alto risco']??0)]],
            'seccoes'=>[['titulo'=>$maxAct===1?'Mutuários sem contratos em curso':'Mutuários que podem receber novo crédito','colunas'=>['Mutuário','Contacto','Contratos','Capital desembolsado (MT)','Prestações pagas','Pagas em atraso','Última liquidação','Score','Classificação','Aprovável','Créditos activos'],'linhas'=>$lines]],
        ];
    }
}
