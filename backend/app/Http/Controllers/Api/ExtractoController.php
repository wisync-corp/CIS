<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EXTRACTO — só leitura (equivalente ao formulário «Extracto de Movimentos» do VBA).
 *
 * Geral    : movimentos do Extracto (Entradas/Saídas) por Data da Operação, cliente,
 *            contrato e conta da Caixa. Totais: Entradas, Saídas, Disponível.
 * Clientes : prestações (Contractos) por Data de Cobrança, cliente, contrato e estado.
 *            Totais como no VBA (Em aberto, Vencido, Pago e respectivos juros), com duas
 *            correcções: «Vencido» soma o valor em dívida (o VBA somava Valor Pago, que é 0)
 *            e uma prestação em aberto com vencimento passado conta como vencida mesmo que
 *            o Estado ainda diga «Pendente».
 * Evidência: extracto completo de um cliente/contrato para entregar ao cliente, com
 *            código de verificação (SHA-256 do conteúdo).
 */
final class ExtractoController extends Controller
{
    private const OPEN=['Pendente','Pagamento Atrasado'];
    private const PAID=['Pago','Pago em Atraso','Renovado'];

    /** Erros de base de dados com causa legível (código MySQL) em vez de «Server Error». */
    private function guard(callable $f)
    {
        try { return $f(); }
        catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $code=(int)($e->errorInfo[1]??0);
            $why=match ($code) {
                1142,1143=>'o utilizador de leitura não tem permissão sobre uma tabela/coluna',
                1054=>'coluna inexistente nesta base',
                1146=>'tabela inexistente nesta base',
                default=>'erro de base de dados',
            };
            abort(503,'Extracto indisponível: '.$why.' (MySQL '.$code.'). Detalhe no registo do servidor.');
        }
    }

    private function today(): string
    {
        return now('Africa/Maputo')->toDateString();
    }

    private function filters(Request $request): array
    {
        $f=$request->validate([
            'inicio'=>['nullable','date_format:Y-m-d'],
            'fim'=>['nullable','date_format:Y-m-d'],
            'cliente'=>['nullable','string','max:255'],
            'contrato'=>['nullable','string','max:155'],
            'conta_id'=>['nullable','integer','min:1'],
            'tipo'=>['nullable','in:todos,entradas,saidas'],
            'estado'=>['nullable','in:todos,aberta,vencida,paga'],
            // Período da Visão geral (mês/ano, «todos» = sem restrição) e data de referência.
            'mes'=>['nullable','regex:/^(todos|[1-9]|1[0-2])$/'],
            'ano'=>['nullable','regex:/^(todos|(19|20)\d{2})$/'],
            'base'=>['nullable','in:cobranca,pagamento,desembolso'],
            'page'=>['nullable','integer','min:1'],
            'por_pagina'=>['nullable','integer','min:10','max:200'],
        ]);
        abort_if(!empty($f['inicio']) && !empty($f['fim']) && $f['fim']<$f['inicio'],422,
            'A data final tem de ser igual ou posterior à data inicial.');
        foreach (['cliente','contrato'] as $k) $f[$k]=trim((string)($f[$k]??''));
        if ($f['contrato']!=='') $f['contrato']=\App\Services\ContractNumbering::resolveIfMissing($f['contrato']);
        return $f;
    }

    private static function money(mixed $v): string
    {
        return number_format(round((float)($v??0),2),2,'.','');
    }

    /** Descrição como o VBA a mostrava (desembolso + contrato; juros + contrato/cliente/parcela). */
    private static function describe(object $r): string
    {
        $d=trim((string)$r->{'Descrição'});
        if (in_array($d,['Desembolso de Empréstimo','Aumento de Crédito'],true)) return $d.' - '.($r->Contracto??'');
        if ($d==='Receita de Juros') return $d.' - '.($r->Contracto??'').' - '.($r->Cliente??'').' - '.($r->Parcela??'');
        return $d;
    }

    /** GET /cis/extractos/geral */
    public function geral(Request $request)
    {
        return $this->guard(fn()=>$this->geralImpl($request));
    }

    private function geralImpl(Request $request)
    {
        // Movimentos da Caixa: mesmo acesso que a página Caixa (só administrador).
        abort_unless($request->user()?->role==='administrador',403,'O extracto geral (movimentos da Caixa) é reservado ao administrador.');
        $f=$this->filters($request);
        $db=DB::connection('mysql_legacy');
        [$q,$hasAccount]=$this->geralQuery($db,$f);
        $t=$this->geralTotals($q);

        $per=(int)($f['por_pagina']??50);
        $page=max(1,(int)($f['page']??1));
        $rows=$this->geralRows($q,$hasAccount,fn($qq)=>$qq->forPage($page,$per));
        return response()->json([
            'linhas'=>$rows,
            'pagina'=>$page,'por_pagina'=>$per,'total_linhas'=>$t['n'],
            'totais'=>$t['totais'],
        ])->header('Cache-Control','no-store, private');
    }

    private function geralQuery($db,array $f): array
    {
        $hasAccount=$db->getSchemaBuilder()->hasColumn('Extracto','account_id');
        $q=$db->table('Extracto as e');
        if ($f['inicio']??null) $q->where('e.Data da Operação','>=',$f['inicio']);
        if ($f['fim']??null) $q->where('e.Data da Operação','<=',$f['fim']);
        if ($f['cliente']!=='') $q->where('e.Cliente',$f['cliente']);
        if ($f['contrato']!=='') $q->where('e.Contracto',$f['contrato']);
        if (($f['conta_id']??null) && $hasAccount) $q->where('e.account_id',(int)$f['conta_id']);
        if (($f['tipo']??'todos')==='entradas') $q->where('e.Tipo de Movimento','Entradas');
        if (($f['tipo']??'todos')==='saidas') $q->where('e.Tipo de Movimento','<>','Entradas');
        return [$q,$hasAccount];
    }

    private function geralTotals($q): array
    {
        $t=(clone $q)->selectRaw("
            COUNT(*) AS n,
            ROUND(SUM(CASE WHEN `Tipo de Movimento`='Entradas' THEN Valor ELSE 0 END),2) AS entradas,
            ROUND(SUM(CASE WHEN `Tipo de Movimento`<>'Entradas' THEN Valor ELSE 0 END),2) AS saidas
        ")->first();
        $e=(float)$t->entradas;$s=(float)$t->saidas;
        return ['n'=>(int)$t->n,'totais'=>['entradas'=>self::money($e),'saidas'=>self::money($s),'disponivel'=>self::money($e-$s)]];
    }

    private function geralRows($q,bool $hasAccount,callable $limit,bool $public=false)
    {
        $q=clone $q;
        // Só colunas que existem no Extracto desta base (o legado varia entre instalações).
        $have=array_flip($q->getConnection()->getSchemaBuilder()->getColumnListing('Extracto'));
        $cols=[];
        foreach (['Nr','Contracto','Cliente','Parcela','Tipo de Movimento','Descrição','Data da Operação','Valor','usuario','account_id'] as $c) {
            if (isset($have[$c])) $cols[]='e.'.$c;
        }
        $q->orderByDesc('e.Data da Operação')->orderByDesc('e.Nr');
        $limit($q);
        $rows=$q->get($cols);
        // Códigos das contas pela ligação principal: o utilizador de leitura do legado
        // não tem (nem deve ter) acesso às tabelas cis_cash_*.
        $codes=[];
        if ($hasAccount) {
            $ids=$rows->pluck('account_id')->filter()->unique()->values()->all();
            if ($ids) {
                try { $codes=DB::table('cis_cash_accounts')->whereIn('id',$ids)->pluck('code','id')->all(); }
                catch (\Throwable $e) { report($e); }
            }
        }
        return $rows->map(fn($r)=>[
                'nr'=>(int)$r->Nr,'codigo'=>'OP-'.$r->Nr,
                'descricao'=>$public?self::publicDescription($r):self::describe($r),
                'data'=>substr((string)$r->{'Data da Operação'},0,10),
                'tipo'=>$r->{'Tipo de Movimento'}==='Entradas'?'entrada':'saida',
                'valor'=>self::money($r->Valor),
                'contrato'=>$r->Contracto??null,'cliente'=>$r->Cliente??null,'parcela'=>$r->Parcela??null,
                'conta'=>isset($r->account_id)&&$r->account_id!==null?($codes[$r->account_id]??null):null,
                'usuario'=>$r->usuario??null,
            ])->values()->all();
    }

    /** GET /cis/extractos/geral-evidencia : extracto geral imprimível (filtros actuais, até 5000 linhas). */
    public function geralEvidencia(Request $request)
    {
        return $this->guard(fn()=>$this->geralEvidenciaImpl($request));
    }

    private function geralEvidenciaImpl(Request $request)
    {
        abort_unless($request->user()?->role==='administrador',403,'O extracto geral (movimentos da Caixa) é reservado ao administrador.');
        $f=$this->filters($request);
        $db=DB::connection('mysql_legacy');
        [$q,$hasAccount]=$this->geralQuery($db,$f);
        $t=$this->geralTotals($q);
        abort_if($t['n']===0,404,'Sem movimentos para os filtros escolhidos.');
        abort_if($t['n']>5000,422,'Demasiados movimentos ('.$t['n'].') para um único documento; reduza o período (máximo 5000).');
        $rows=$this->geralRows($q,$hasAccount,fn($qq)=>$qq->limit(5000),true);
        $conta=null;
        if (($f['conta_id']??null) && $hasAccount) {
            $a=DB::table('cis_cash_accounts')->where('id',(int)$f['conta_id'])->first(['code','name']);
            $conta=$a?$a->code.' · '.$a->name:null;
        }
        $doc=[
            'titulo'=>'Extracto geral de movimentos',
            'emitido_em'=>now('Africa/Maputo')->format('Y-m-d H:i'),
            'emitido_por'=>$request->user()?->name,
            'filtros'=>['inicio'=>$f['inicio']??null,'fim'=>$f['fim']??null,'cliente'=>$f['cliente']?:null,
                'contrato'=>$f['contrato']?:null,'conta'=>$conta,'tipo'=>$f['tipo']??'todos'],
            'linhas'=>array_map(fn($r)=>array_diff_key($r,['usuario'=>1,'nr'=>1]),$rows),
            'total_linhas'=>$t['n'],
            'totais'=>$t['totais'],
        ];
        $doc['codigo_verificacao']=strtoupper(substr(hash('sha256',json_encode($doc,JSON_UNESCAPED_UNICODE)),0,16));
        return response()->json($doc)->header('Cache-Control','no-store, private');
    }

    /** Classificação de uma prestação para o extracto de clientes. */
    private static function situation(object $r,string $today): string
    {
        if (in_array($r->Estado,self::PAID,true)) return 'paga';
        if (in_array($r->Estado,self::OPEN,true)) {
            $due=substr((string)$r->{'Data de Cobrança'},0,10);
            // Mesma regra da Visão geral: vencida = vencimento antes de hoje.
            if ($due==='') return $r->Estado==='Pagamento Atrasado'?'vencida':'aberta';
            return $due<$today?'vencida':'aberta';
        }
        return 'outra';
    }

    private static function stateLabel(object $r,string $sit): string
    {
        return match (true) {
            $r->Estado==='Renovado'=>'Renovada',
            $sit==='paga'=>match ((string)$r->Estado) { 'Pago'=>'Paga', 'Pago em Atraso'=>'Paga com atraso', default=>(string)$r->Estado },
            $sit==='aberta'=>'Em curso',
            $sit==='vencida'=>'Em atraso',
            default=>(string)$r->Estado,
        };
    }

    /** Valor em dívida de uma prestação em aberto: Valor a Pagar (+ multa já lançada). */
    private static function owed(object $r): float
    {
        return (float)$r->{'Valor a Pagar'}+(float)($r->{'Valor de Multa'}??0);
    }

    private function clientRows($db,array $f)
    {
        $q=$db->table('Contractos');
        $base=$f['base']??'cobranca';
        $mes=$f['mes']??'todos';$ano=$f['ano']??'todos';
        if ($base==='desembolso') {
            // Contratos cuja PRIMEIRA Data de Inicio (desembolso) cai no período; todas as suas prestações.
            $sub=$db->table('Contractos')->select('Nr do Contracto')->groupBy('Nr do Contracto')
                ->havingRaw('MIN(`Data de Inicio`) <= ?',[$this->today()]);
            if ($f['inicio']??null) $sub->havingRaw('MIN(`Data de Inicio`) >= ?',[$f['inicio']]);
            if ($f['fim']??null) $sub->havingRaw('MIN(`Data de Inicio`) <= ?',[$f['fim']]);
            if ($ano!=='todos') $sub->havingRaw('YEAR(MIN(`Data de Inicio`)) = ?',[(int)$ano]);
            if ($mes!=='todos') $sub->havingRaw('MONTH(MIN(`Data de Inicio`)) = ?',[(int)$mes]);
            $q->whereIn('Nr do Contracto',$sub);
        } else {
            $col=$base==='pagamento'?'Data de Pagamento':'Data de Cobrança';
            if ($f['inicio']??null) $q->where($col,'>=',$f['inicio']);
            if ($f['fim']??null) $q->where($col,'<=',$f['fim']);
            if ($ano!=='todos') $q->whereRaw('YEAR(`'.$col.'`) = ?',[(int)$ano]);
            if ($mes!=='todos') $q->whereRaw('MONTH(`'.$col.'`) = ?',[(int)$mes]);
        }
        if ($f['cliente']!=='') $q->where('Nome do Cliente',$f['cliente']);
        if ($f['contrato']!=='') $q->where('Nr do Contracto',$f['contrato']);
        // `M. Pagamento` tem um ponto: selecção em SQL explícito (o Laravel lê-o como tabela.coluna).
        $cols=['Ordem','Nº','Nr do Contracto','Nome do Cliente','Nº de Cliente','Nº da Parcela','Data de Inicio',
            'Data de Cobrança','Dias','M. Pagamento','Quantia Creditada','Valor de Juros','Valor de Multa',
            'Valor a Pagar','Valor Pago','Data de Pagamento','Estado'];
        return $q->selectRaw(implode(',',array_map(fn($c)=>'`'.$c.'`',$cols)))
            ->orderBy('Data de Cobrança')->orderBy('Nr do Contracto')->orderBy('Nº da Parcela')->get();
    }

    private function summarise($rows,string $today,?string $estado): array
    {
        $tot=['aberto'=>0.0,'vencido'=>0.0,'pago'=>0.0,'juros_aberto'=>0.0,'juros_vencido'=>0.0,'juros_pago'=>0.0,
            'n_aberto'=>0,'n_vencido'=>0,'n_pago'=>0];
        $out=[];
        foreach ($rows as $r) {
            $sit=self::situation($r,$today);
            if ($estado && $estado!=='todos' && $sit!==$estado) continue;
            $juros=(float)$r->{'Valor de Juros'};$multa=(float)($r->{'Valor de Multa'}??0);
            if ($sit==='aberta') { $tot['aberto']+=self::owed($r); $tot['juros_aberto']+=$juros; $tot['n_aberto']++; }
            if ($sit==='vencida') { $tot['vencido']+=self::owed($r); $tot['juros_vencido']+=$juros+$multa; $tot['n_vencido']++; }
            if ($sit==='paga') { $tot['pago']+=(float)$r->{'Valor Pago'}; $tot['juros_pago']+=$juros+$multa; $tot['n_pago']++; }
            $due=substr((string)$r->{'Data de Cobrança'},0,10);
            $out[]=[
                'ordem'=>(int)$r->Ordem,'codigo'=>'OP-'.$r->Ordem,
                'contrato'=>$r->{'Nr do Contracto'},'cliente'=>$r->{'Nome do Cliente'},'cliente_id'=>$r->{'Nº de Cliente'},
                'parcela'=>$r->{'Nº da Parcela'},
                'inicio'=>substr((string)$r->{'Data de Inicio'},0,10),'vencimento'=>$due,
                'dias'=>in_array($sit,['aberta','vencida'],true)&&$due!==''
                    ? (int)\Carbon\CarbonImmutable::parse($today)->diffInDays(\Carbon\CarbonImmutable::parse($due),false)
                    : (int)$r->Dias,
                'forma_pagamento'=>$r->{'M. Pagamento'},
                'capital'=>self::money($r->{'Quantia Creditada'}),
                'juros'=>self::money($juros),'multa'=>self::money($multa),
                'valor'=>self::money(in_array($sit,['aberta','vencida'],true)?self::owed($r):$r->{'Valor Pago'}),
                'data_pagamento'=>$r->{'Data de Pagamento'}?substr((string)$r->{'Data de Pagamento'},0,10):null,
                'situacao'=>$sit,'estado'=>self::stateLabel($r,$sit),
            ];
        }
        foreach ($tot as $k=>$v) if (!str_starts_with($k,'n_')) $tot[$k]=self::money($v);
        return [$out,$tot];
    }

    /** GET /cis/extractos/clientes */
    public function clientes(Request $request)
    {
        return $this->guard(fn()=>$this->clientesImpl($request));
    }

    private function clientesImpl(Request $request)
    {
        $f=$this->filters($request);
        $db=DB::connection('mysql_legacy');
        [$all,$tot]=$this->summarise($this->clientRows($db,$f),$this->today(),$f['estado']??'todos');
        $per=(int)($f['por_pagina']??50);
        $page=max(1,(int)($f['page']??1));
        return response()->json([
            'linhas'=>array_slice($all,($page-1)*$per,$per),
            'pagina'=>$page,'por_pagina'=>$per,'total_linhas'=>count($all),
            'totais'=>$tot,
        ])->header('Cache-Control','no-store, private');
    }

    /** GET /cis/extractos/contratos?cliente= : contratos de um cliente (como o combo do VBA). */
    public function contratos(Request $request)
    {
        return $this->guard(fn()=>$this->contratosImpl($request));
    }

    private function contratosImpl(Request $request)
    {
        $d=$request->validate(['cliente'=>['nullable','string','max:255'],'termo'=>['nullable','string','max:100']]);
        $db=DB::connection('mysql_legacy');
        if (trim((string)($d['cliente']??''))!=='') {
            $list=$db->table('Contractos')->where('Nome do Cliente',trim($d['cliente']))
                ->distinct()->orderBy('Nr do Contracto')->pluck('Nr do Contracto');
            return response()->json(['contratos'=>$list->values()]);
        }
        $term=trim((string)($d['termo']??''));
        abort_if(mb_strlen($term)<2,422,'Escreva pelo menos 2 letras do nome do mutuário.');
        $names=$db->table('Contractos')->where('Nome do Cliente','like','%'.addcslashes($term,'%_\\').'%')
            ->selectRaw('`Nome do Cliente` AS nome, MAX(`Nº de Cliente`) AS id, COUNT(DISTINCT `Nr do Contracto`) AS contratos')
            ->groupBy('Nome do Cliente')->orderBy('Nome do Cliente')->limit(15)->get();
        return response()->json(['clientes'=>$names]);
    }

    /** GET /cis/extractos/evidencia?cliente=&contrato=&inicio=&fim= : extracto para entregar ao cliente. */
    public function evidencia(Request $request)
    {
        return $this->guard(fn()=>$this->evidenciaImpl($request));
    }

    private function evidenciaImpl(Request $request)
    {
        $f=$this->filters($request);
        abort_if($f['cliente']==='' && $f['contrato']==='',422,'Escolha o mutuário ou o contrato para gerar o extracto.');
        $db=DB::connection('mysql_legacy');
        $today=$this->today();
        // Evidência completa: todas as prestações (sem filtro de estado) no intervalo escolhido.
        [$rows,$tot]=$this->summarise($this->clientRows($db,$f),$today,'todos');
        abort_if(!$rows,404,'Sem prestações para este mutuário/contrato no período escolhido.');

        $names=array_values(array_unique(array_map(fn($r)=>$r['cliente'],$rows)));
        $ids=array_values(array_unique(array_filter(array_map(fn($r)=>$r['cliente_id'],$rows))));
        $client=['nome'=>implode(' / ',$names),'id'=>count($ids)===1?$ids[0]:null];
        if (count($ids)===1) {
            $c=$db->table('Clientes')->where('ID',$ids[0])->first();
            if ($c) {
                $pick=fn(string $k)=>property_exists($c,$k)&&trim((string)$c->$k)!==''?trim((string)$c->$k):null;
                $client=array_merge($client,array_filter([
                    'numeracao'=>$pick('Numeração'),'bi'=>$pick('Nº de BI'),'contacto'=>$pick('Contacto'),
                    'morada'=>implode(', ',array_filter([$pick('Rua'),$pick('Bairro'),$pick('Cidade'),$pick('Provincia')]))?:null,
                ]));
            }
        }

        // Contratos: resumo por contrato.
        $contracts=[];
        foreach ($rows as $r) {
            $k=$r['contrato'];
            $contracts[$k]??=['contrato'=>$k,'capital'=>$r['capital'],'inicio'=>$r['inicio'],'prestacoes'=>0,'em_divida'=>0.0,'pago'=>0.0];
            $contracts[$k]['prestacoes']++;
            if (in_array($r['situacao'],['aberta','vencida'],true)) $contracts[$k]['em_divida']+=(float)$r['valor'];
            if ($r['situacao']==='paga') $contracts[$k]['pago']+=(float)$r['valor'];
            if ($r['inicio'] && $r['inicio']<$contracts[$k]['inicio']) $contracts[$k]['inicio']=$r['inicio'];
        }
        $contracts=array_map(function ($c) {
            $c['em_divida']=self::money($c['em_divida']);$c['pago']=self::money($c['pago']);
            $c['situacao']=(float)$c['em_divida']>0?'Em curso':'Liquidado';
            return $c;
        },array_values($contracts));

        // Movimentos financeiros do(s) contrato(s): recibos e desembolsos registados.
        $nums=array_column($contracts,'contrato');
        $mq=$db->table('Extracto')->whereIn('Contracto',$nums);
        if ($f['inicio']??null) $mq->where('Data da Operação','>=',$f['inicio']);
        if ($f['fim']??null) $mq->where('Data da Operação','<=',$f['fim']);
        $movements=$mq->orderBy('Data da Operação')->orderBy('Nr')
            ->get(['Nr','Contracto','Cliente','Parcela','Tipo de Movimento','Descrição','Data da Operação','Valor'])
            ->map(fn($m)=>[
                'codigo'=>'OP-'.$m->Nr,'data'=>substr((string)$m->{'Data da Operação'},0,10),
                'descricao'=>self::publicDescription($m),
                'contrato'=>$m->Contracto,'parcela'=>$m->Parcela,
                'tipo'=>$m->{'Tipo de Movimento'}==='Entradas'?'Pagamento recebido':'Desembolso/saída',
                'valor'=>self::money($m->Valor),
            ])->values()->all();

        $user=$request->user();
        $doc=[
            'titulo'=>'Extracto de conta do mutuário',
            'emitido_em'=>now('Africa/Maputo')->format('Y-m-d H:i'),
            'emitido_por'=>$user?->name,
            'periodo'=>['inicio'=>$f['inicio']??null,'fim'=>$f['fim']??null],
            'cliente'=>$client,
            'contratos'=>$contracts,
            'prestacoes'=>array_map(fn($r)=>array_diff_key($r,['cliente'=>1,'cliente_id'=>1,'situacao'=>1]),$rows),
            'movimentos'=>$movements,
            'totais'=>$tot,
        ];
        $doc['codigo_verificacao']=strtoupper(substr(hash('sha256',json_encode($doc,JSON_UNESCAPED_UNICODE)),0,16));
        return response()->json($doc)->header('Cache-Control','no-store, private');
    }

    /** GET /cis/extractos/informacao?contrato= : painel «Informação» (formulário VBA Informação). */
    public function informacao(Request $request)
    {
        return $this->guard(fn()=>$this->informacaoImpl($request));
    }

    private function informacaoImpl(Request $request)
    {
        $d=$request->validate(['contrato'=>['required','string','max:155']]);
        $numero=\App\Services\ContractNumbering::resolveIfMissing(trim($d['contrato']));
        $db=DB::connection('mysql_legacy');
        $today=$this->today();
        $rows=$this->clientRows($db,['cliente'=>'','contrato'=>$numero]);
        abort_if($rows->isEmpty(),404,'Contrato não encontrado.');
        $first=$rows->first();
        $sit=['aberta'=>0,'vencida'=>0,'paga'=>0];$owed=0.0;$paid=0.0;$next=null;$maxLate=0;
        foreach ($rows as $r) {
            $s=self::situation($r,$today);
            if (isset($sit[$s])) $sit[$s]++;
            if ($s==='aberta'||$s==='vencida') {
                $owed+=self::owed($r);
                $due=substr((string)$r->{'Data de Cobrança'},0,10);
                if ($due!=='' && ($next===null || $due<$next)) $next=$due;
                if ($s==='vencida' && $due!=='') $maxLate=max($maxLate,(int)\Carbon\CarbonImmutable::parse($due)->diffInDays(\Carbon\CarbonImmutable::parse($today)));
            }
            if ($s==='paga') $paid+=(float)$r->{'Valor Pago'};
        }
        $situacao=$sit['vencida']>0?'Pagamento atrasado':(($sit['aberta']>0)?'Pendente':'Finalizado');
        $final=$db->table('Contractos')->where('Nr do Contracto',$numero)->max('Data Final');
        $clientId=(int)($first->{'Nº de Cliente'}??0);
        $client=['id'=>$clientId?:null,'codigo'=>$clientId?str_pad((string)$clientId,3,'0',STR_PAD_LEFT):null,
            'nome'=>$first->{'Nome do Cliente'},'contacto'=>null];
        if ($clientId) {
            $c=$db->table('Clientes')->where('ID',$clientId)->first();
            if ($c) {
                if (property_exists($c,'Nome Completo') && trim((string)$c->{'Nome Completo'})!=='') $client['nome']=trim((string)$c->{'Nome Completo'});
                if (property_exists($c,'Contacto') && trim((string)$c->Contacto)!=='') $client['contacto']=trim((string)$c->Contacto);
            }
        }
        return response()->json([
            'contrato'=>$numero,
            'data_inicio'=>substr((string)$rows->min('Data de Inicio'),0,10)?:null,
            'data_final'=>$final?substr((string)$final,0,10):null,
            'quantia_creditada'=>self::money($rows->max('Quantia Creditada')),
            'forma_pagamento'=>$first->{'M. Pagamento'},
            'situacao'=>$situacao,
            'prestacoes'=>['total'=>$rows->count(),'pagas'=>$sit['paga'],'em_aberto'=>$sit['aberta'],'vencidas'=>$sit['vencida']],
            'em_divida'=>self::money($owed),'pago'=>self::money($paid),
            'proximo_vencimento'=>$next,'dias_atraso'=>$maxLate,
            'cliente'=>$client,
        ])->header('Cache-Control','no-store, private');
    }

    /** Descrição sem marcas internas ([CAIXA:…], REF:, chaves) para o documento do cliente. */
    private static function publicDescription(object $m): string
    {
        $d=preg_replace('/^\[[A-Z:_ -]+\]\s*/u','',trim((string)$m->{'Descrição'}));
        $d=trim(preg_replace('/\s*\|\s*REF:.*$/u','',$d));
        return $d!==''?$d:($m->{'Tipo de Movimento'}==='Entradas'?'Pagamento':'Saída');
    }
}
