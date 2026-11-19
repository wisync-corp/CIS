<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
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
            ['id'=>'actividade','titulo'=>'Relatório periódico de actividade','periodo'=>'intervalo',
             'descricao'=>'Desembolsos, carteira, carteira em risco, recebimentos, renovações, clientes e Caixa.',
             'base_legal'=>'Decreto n.º 50/2024 (arts. 165 e 167 d)): monitorização e comunicações obrigatórias definidas pelo Banco de Moçambique.'],
            ['id'=>'crc','titulo'=>'Central de Registo de Crédito','periodo'=>'data',
             'descricao'=>'Uma linha por contrato com saldo ou movimento: identificação do cliente, montantes, saldo, atraso e situação.',
             'base_legal'=>'Aviso n.º 12/GBM/2024 (Regulamento da Central de Registo de Crédito): reporte em 24 horas após cada operação; modelo por Circular.'],
            ['id'=>'branqueamento','titulo'=>'Prevenção do branqueamento de capitais (anual)','periodo'=>'ano',
             'descricao'=>'Clientes acima do regime simplificado, maiores movimentos por cliente e clientes sem identificação completa.',
             'base_legal'=>'Aviso n.º 10/GBM/2024, Capítulo XIII (arts. 134–141) e art. 116 (dados anuais até 31 de Janeiro).'],
            ['id'=>'identificacao','titulo'=>'Qualidade dos dados de identificação','periodo'=>'nenhum',
             'descricao'=>'Clientes com contratos e dados de identificação em falta (BI, NUIT, contacto, morada).',
             'base_legal'=>'Aviso n.º 10/GBM/2024, art. 138 (identificação simplificada: nome, tipo e número do documento, NUIB).'],
        ];
    }

    private function admin(Request $r): void
    {
        abort_unless($r->user()?->role==='administrador',403,'Os relatórios regulatórios são reservados ao administrador.');
    }

    private static function m(float $v): string { return number_format(round($v,2),2,'.',''); }
    private static function d($v): string { return substr((string)$v,0,10); }
    private static function pct(float $a,float $b): string { return $b>0?number_format($a/$b*100,1,'.',''):'0.0'; }

    public function index(Request $request)
    {
        $this->admin($request);
        return response()->json(['relatorios'=>self::catalog()]);
    }

    public function show(Request $request,string $id)
    {
        $this->admin($request);
        $def=collect(self::catalog())->firstWhere('id',$id);
        abort_unless($def,404,'Relatório desconhecido.');
        $today=now('Africa/Maputo')->toDateString();
        $p=$request->validate([
            'inicio'=>['nullable','date_format:Y-m-d'],'fim'=>['nullable','date_format:Y-m-d'],
            'data'=>['nullable','date_format:Y-m-d'],'ano'=>['nullable','integer','min:2000','max:2100'],
        ]);
        try {
            $out=match ($id) {
                'actividade'=>$this->actividade($p['inicio']??CarbonImmutable::parse($today)->startOfMonth()->toDateString(),min($p['fim']??$today,$today)),
                'crc'=>$this->crc(min($p['data']??$today,$today)),
                'branqueamento'=>$this->branqueamento((int)($p['ano']??now('Africa/Maputo')->year)),
                'identificacao'=>$this->identificacao(),
            };
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            abort(503,'Relatório indisponível (MySQL '.((int)($e->errorInfo[1]??0)).'). Detalhe no registo do servidor.');
        }
        $doc=$def+$out+['emitido_em'=>now('Africa/Maputo')->format('Y-m-d H:i'),'emitido_por'=>$request->user()?->name];
        $doc['codigo_verificacao']=strtoupper(substr(hash('sha256',json_encode([$id,$out],JSON_UNESCAPED_UNICODE)),0,16));
        return response()->json($doc)->header('Cache-Control','no-store, private');
    }

    // ------------------------------------------------------------------ dados base

    private function rows()
    {
        $cols=['Ordem','Nr do Contracto','Nome do Cliente','Nº de Cliente','Quantia Creditada','% de Juro','Prazo/Duração','Nº de Prestações',
            'Data de Inicio','Data de Cobrança','Valor de Juros','Valor de Multa','Valor a Pagar','Valor Pago','Data de Pagamento','Estado'];
        return DB::connection('mysql_legacy')->table('Contractos')
            ->selectRaw(implode(',',array_map(fn($c)=>'`'.$c.'`',$cols)))->orderBy('Ordem')->get();
    }

    /** Clientes (só colunas existentes): ID → dados de identificação. */
    private function clients(): array
    {
        $db=DB::connection('mysql_legacy');
        $have=array_flip($db->getSchemaBuilder()->getColumnListing('Clientes'));
        $want=['ID','Numeração','Nome Completo','Nº de BI','tipo_documento','validade_documento','nuit','Contacto','Bairro','Cidade','Provincia','Rua','genero','Nacionalidade'];
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
        $cx=DB::connection('mysql_legacy')->table('Extracto')->whereBetween('Data da Operação',[$ini,$fim])
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
        // Maiores exposições na data de fim (por cliente).
        $top=$out->groupBy(fn($r)=>(int)$r->{'Nº de Cliente'}?:$r->{'Nome do Cliente'})
            ->map(fn($g)=>[$g->first()->{'Nome do Cliente'},$g->pluck('Nr do Contracto')->unique()->count(),self::m($g->sum(fn($r)=>self::principal($r))),self::pct($g->sum(fn($r)=>self::principal($r)),max(1,$cart))])
            ->sortByDesc(fn($x)=>(float)$x[2])->take(10)->values()->all();
        $avgRate=$disb->count()?$disb->avg('tx'):0;
        return [
            'intervalo'=>['inicio'=>$ini,'fim'=>$fim],
            'nota'=>'Carteira e carteira em risco reconstituídas à data '.CarbonImmutable::parse($fim)->format('d/m/Y').' (prestação iniciada e não paga até essa data). Capital = Valor a Pagar − Juros.',
            'resumo'=>[
                ['Contratos desembolsados',(string)$disb->count()],['Valor desembolsado',self::m($disb->sum('q')).' MT'],
                ['Carteira em curso',self::m($cart).' MT'],['Valor em dívida (com juros)',self::m($owed).' MT'],
                ['Carteira em risco > 30 dias',self::pct($parC($par30),$cart).'%'],['Recebido no período',self::m($tot).' MT'],
                ['Clientes activos',(string)$activos],['Clientes novos',(string)$novos],
            ],
            'seccoes'=>[
                ['titulo'=>'1. Desembolsos','colunas'=>['Indicador','Valor'],'linhas'=>[
                    ['Número de contratos desembolsados',(string)$disb->count()],
                    ['Valor desembolsado (MT)',self::m($disb->sum('q'))],
                    ['Montante médio por contrato (MT)',self::m($disb->count()?$disb->avg('q'):0)],
                    ['Taxa de juro média (%)',number_format($avgRate,2,'.','')],
                    ['Clientes novos (primeiro contrato no período)',(string)$novos],
                ]],
                ['titulo'=>'2. Carteira à data de '.CarbonImmutable::parse($fim)->format('d/m/Y'),'colunas'=>['Indicador','Valor'],'linhas'=>[
                    ['Contratos em curso',(string)$out->pluck('Nr do Contracto')->unique()->count()],
                    ['Clientes activos',(string)$activos],
                    ['Carteira em curso — capital (MT)',self::m($cart)],
                    ['Valor em dívida com juros (MT)',self::m($owed)],
                ]],
                ['titulo'=>'3. Carteira em risco (prestações em atraso)','colunas'=>['Atraso','Prestações','Contratos','Capital dos contratos (MT)','% da carteira'],'linhas'=>[
                    ['Qualquer atraso',(string)$par1->count(),(string)$par1->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par1)),self::pct($parC($par1),$cart)],
                    ['Mais de 30 dias',(string)$par30->count(),(string)$par30->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par30)),self::pct($parC($par30),$cart)],
                    ['Mais de 90 dias',(string)$par90->count(),(string)$par90->pluck('Nr do Contracto')->unique()->count(),self::m($parC($par90)),self::pct($parC($par90),$cart)],
                ]],
                ['titulo'=>'4. Recebimentos no período','colunas'=>['Componente','Valor (MT)'],'linhas'=>[
                    ['Capital (amortização)',self::m($cap)],['Juros',self::m($jur)],['Multas',self::m($mul)],['Total recebido',self::m($tot)],
                    ['Prestações pagas (n.º)',(string)$paid->count()],['Renovações (n.º)',(string)$ren->count()],['Juros de renovações',self::m($ren->sum(fn($r)=>(float)$r->{'Valor de Juros'}))],
                ]],
                ['titulo'=>'5. Distribuição dos desembolsos por montante','colunas'=>['Faixa (MT)','Contratos','Valor (MT)','% do valor'],'linhas'=>$dist],
                ['titulo'=>'6. Desembolsos por género','colunas'=>['Género','Contratos','Valor (MT)'],'linhas'=>$gen],
                ['titulo'=>'7. Caixa no período (sem transferências internas)','colunas'=>['Movimento','Valor (MT)'],'linhas'=>[
                    ['Entradas',self::m((float)($cx->e??0))],['Saídas',self::m((float)($cx->s??0))],['Saldo do período',self::m((float)($cx->e??0)-(float)($cx->s??0))],
                ]],
                ['titulo'=>'8. Maiores exposições (10 clientes)','colunas'=>['Cliente','Contratos','Capital em curso (MT)','% da carteira'],'linhas'=>$top],
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
                'colunas'=>['Contrato','Cliente','N.º cliente','Tipo documento','N.º documento','NUIT','Data desembolso','Prazo','Taxa (%)','Montante (MT)','Saldo capital (MT)','Valor vencido (MT)','Dias de atraso','Situação','Data liquidação','Dados em falta'],
                'linhas'=>$lines]],
        ];
    }

    // ------------------------------------------------------------------ 3) branqueamento

    private function branqueamento(int $ano): array
    {
        $rows=$this->rows();$cl=$this->clients();
        $ini="$ano-01-01";$fim="$ano-12-31";
        $per=[];
        foreach ($rows->groupBy('Nr do Contracto') as $c) {
            $first=$c->first();$key=(int)$first->{'Nº de Cliente'}?:(string)$first->{'Nome do Cliente'};
            $per[$key]??=['nome'=>(string)$first->{'Nome do Cliente'},'cid'=>(int)$first->{'Nº de Cliente'},'desemb'=>0.0,'receb'=>0.0,'meses'=>[],'contratos'=>0];
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
        $cols=['Cliente','N.º cliente','BI','NUIT','Contratos no ano','Desembolsado (MT)','Recebido (MT)','Movimento total (MT)','Maior mês (MT)','Fora do regime simplificado','Identificação'];
        return [
            'ano'=>$ano,
            'nota'=>'Regime simplificado (Aviso 10/GBM/2024, art. 137): actividade até 30 000 MT por mês e 240 000 MT por ano. Clientes acima destes limites exigem identificação completa e acompanhamento reforçado. Os dados anuais (art. 116) entregam-se até 31 de Janeiro do ano seguinte, em Excel (Anexo V).',
            'resumo'=>[['Clientes com movimento',(string)count($todos)],['Fora do regime simplificado',(string)count($acima)],['Sem BI registado',(string)$semId],
                ['Movimento total',self::m(array_sum(array_map(fn($r)=>(float)$r[7],$todos))).' MT']],
            'seccoes'=>[
                ['titulo'=>'1. Clientes fora do regime simplificado','colunas'=>$cols,'linhas'=>$acima],
                ['titulo'=>'2. Maiores movimentos por cliente (20)','colunas'=>$cols,'linhas'=>array_slice($todos,0,20)],
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
            if ($falta) $lines[]=[(string)$g->first()->{'Nome do Cliente'},is_int($k)&&$k?(string)$k:'',$active?'Sim':'Não',(string)$g->pluck('Nr do Contracto')->unique()->count(),implode(', ',$falta),$cli?'':'Cliente não encontrado no cadastro'];
        }
        usort($lines,fn($a,$b)=>[$b[2],$a[0]]<=>[$a[2],$b[0]]);
        return [
            'nota'=>'Identificação mínima (Aviso 10/GBM/2024, art. 138): nome completo, tipo e número do documento e NUIB. O NUIB ainda não existe no cadastro do CIS. Clientes com contratos activos aparecem primeiro.',
            'resumo'=>[['Clientes com contratos',(string)$total],['Com dados em falta',(string)count($lines)],['Sem BI',(string)$cnt['BI']],['Sem NUIT',(string)$cnt['NUIT']],['Sem contacto',(string)$cnt['Contacto']]],
            'seccoes'=>[['titulo'=>'Clientes com dados de identificação em falta','colunas'=>['Cliente','N.º cliente','Contrato activo','Contratos','Em falta','Observação'],'linhas'=>$lines]],
        ];
    }
}
