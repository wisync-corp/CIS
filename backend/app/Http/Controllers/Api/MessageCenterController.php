<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CENTRO DE MENSAGENS (frmMessageCenter do VBA).
 *
 * - Modelos com marcadores {campo}; os nomes dos campos do VBA mantêm-se ({Nome Completo},
 *   {Nr do Contracto}, {Data de Cobrança}, {Valor a Pagar}, {Dias}…) e há campos novos calculados.
 * - O CIS NÃO envia mensagens: prepara o texto e o número; o operador abre o WhatsApp/SMS do
 *   seu dispositivo e confirma o envio, ou a extensão «CIS WhatsApp» envia pelo WhatsApp Web do
 *   operador e devolve enviada/falhada. Tudo fica registado em cis_message_log.
 * - «Mandar para Todos»: grupos todos/activos/inactivos/pendentes/atrasados → fila de envio.
 */
final class MessageCenterController extends Controller
{
    private const OPEN=['Pendente','Pagamento Atrasado'];
    private const FIELDS=[
        'Nome Completo','Nome do Cliente','Primeiro Nome','Nº de Cliente','Contacto','Nr do Contracto','Quantia Creditada','% de Juro',
        'Data de Inicio','Data de Cobrança','Data Final','Valor a Pagar','Dias','-1*Dias','Valor em Dívida','Prestações Vencidas','Dias de Atraso',
    ];
    private const GROUPS=['todos','activos','inactivos','pendentes','atrasados'];

    private function admin(Request $r): void
    {
        abort_unless($r->user()?->role==='administrador',403,'O centro de mensagens é reservado ao administrador.');
    }

    private function guard(callable $f)
    {
        try { return $f(); }
        catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $code=(int)($e->errorInfo[1]??0);
            abort(503,$code===1146
                ?'Centro de mensagens ainda não instalado na base (MySQL 1146). Execute: bash deploy/install-message-center.sh'
                :'Centro de mensagens indisponível (MySQL '.$code.'). Detalhe no registo do servidor.');
        }
    }

    private static function money(mixed $v): string
    {
        return number_format(round((float)($v??0),2),2,',',' ');
    }

    private static function date(?string $v): string
    {
        $v=substr((string)$v,0,10);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$v,$m)?"$m[3]/$m[2]/$m[1]":'';
    }

    /** Número para WhatsApp/SMS: só dígitos, com 258 quando é um número moçambicano de 9 dígitos. */
    public static function phone(?string $c): ?string
    {
        $d=preg_replace('/\D+/','',(string)$c);
        if ($d==='') return null;
        $d=preg_replace('/^00/','',$d);
        if (strlen($d)===9 && preg_match('/^[82]/',$d)) $d='258'.$d;
        return strlen($d)>=9?$d:null;
    }

    public function campos()
    {
        return response()->json(['campos'=>array_map(fn($f)=>'{'.$f.'}',self::FIELDS)]);
    }

    // ---------------- Modelos ----------------

    public function modelos(Request $request)
    {
        $this->admin($request);
        return $this->guard(fn()=>response()->json([
            'modelos'=>DB::table('cis_message_templates')->orderBy('name')->get(['id','name','body','updated_at']),
            'campos'=>array_map(fn($f)=>'{'.$f.'}',self::FIELDS),
        ])->header('Cache-Control','no-store, private'));
    }

    private function templateData(Request $request,?int $ignore=null): array
    {
        $d=$request->validate([
            'name'=>['required','string','min:3','max:80'],
            'body'=>['required','string','min:3','max:2000'],
        ]);
        $d['name']=trim(preg_replace('/\s+/',' ',$d['name']));
        $dup=DB::table('cis_message_templates')->where('name',$d['name'])->when($ignore,fn($q)=>$q->where('id','<>',$ignore))->exists();
        abort_if($dup,422,'Já existe um modelo com este nome.');
        return $d;
    }

    public function criarModelo(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $d=$this->templateData($request);
            $id=DB::table('cis_message_templates')->insertGetId($d+['created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            return response()->json(['id'=>$id,'notice'=>'Modelo guardado com sucesso.'],201);
        });
    }

    public function actualizarModelo(Request $request,int $id)
    {
        $this->admin($request);
        return $this->guard(function () use ($request,$id) {
            abort_unless(DB::table('cis_message_templates')->where('id',$id)->exists(),404,'Modelo não encontrado.');
            $d=$this->templateData($request,$id);
            DB::table('cis_message_templates')->where('id',$id)->update($d+['updated_by'=>$request->user()->id,'updated_at'=>now()]);
            return response()->json(['id'=>$id,'notice'=>'Modelo guardado com sucesso.']);
        });
    }

    public function eliminarModelo(Request $request,int $id)
    {
        $this->admin($request);
        return $this->guard(function () use ($id) {
            abort_unless(DB::table('cis_message_templates')->where('id',$id)->delete()===1,404,'Modelo não encontrado.');
            return response()->json(['notice'=>'Modelo eliminado.']);
        });
    }

    // ---------------- Dados dos marcadores ----------------

    /** Clientes por ID (e por Numeração, como no join do VBA) — só colunas existentes. */
    private function clients($db,array $ids): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
        if (!$ids) return [];
        $have=array_flip($db->getSchemaBuilder()->getColumnListing('Clientes'));
        $cols=array_values(array_filter(['ID','Numeração','Nome Completo','Contacto','contacto_alternativo'],fn($c)=>isset($have[$c])));
        $out=[];
        foreach (array_chunk($ids,500) as $chunk) {
            foreach ($db->table('Clientes')->whereIn('ID',$chunk)->get($cols) as $c) $out[(int)$c->ID]=$c;
        }
        // O bot do VBA ligava `Nº de Cliente` a `Numeração`: usa-se como alternativa quando não há ID.
        $missing=array_values(array_diff($ids,array_keys($out)));
        if ($missing && isset($have['Numeração'])) {
            foreach (array_chunk($missing,500) as $chunk) {
                foreach ($db->table('Clientes')->whereIn('Numeração',array_map('strval',$chunk))->get($cols) as $c) $out[(int)$c->{'Numeração'}]??=$c;
            }
        }
        return $out;
    }

    /** Valores dos marcadores para um contrato (linhas Contractos do contrato + cliente). */
    private function values($rows,?object $client,string $today): array
    {
        $open=$rows->filter(fn($r)=>in_array($r->Estado,self::OPEN,true));
        $late=$open->filter(fn($r)=>substr((string)$r->{'Data de Cobrança'},0,10)<$today);
        // Prestação de referência (como o FindMaxOrdem do VBA): a vencida mais antiga, senão a próxima em aberto, senão a última.
        $ref=$late->sortBy('Data de Cobrança')->first()??$open->sortBy('Data de Cobrança')->first()??$rows->sortByDesc('Ordem')->first();
        $due=substr((string)$ref->{'Data de Cobrança'},0,10);
        $daysLate=$late->isNotEmpty()
            ?(int)\Carbon\CarbonImmutable::parse($due)->diffInDays(\Carbon\CarbonImmutable::parse($today)):0;
        $name=trim((string)($client?->{'Nome Completo'}??$ref->{'Nome do Cliente'}));
        $owed=$open->sum(fn($r)=>(float)$r->{'Valor a Pagar'}+(float)($r->{'Valor de Multa'}??0));
        return [
            'Nome Completo'=>$name,
            'Nome do Cliente'=>$name,
            'Primeiro Nome'=>explode(' ',$name)[0]??$name,
            'Nº de Cliente'=>(string)($client?->{'Numeração'}??$ref->{'Nº de Cliente'}),
            'Contacto'=>trim((string)($client?->Contacto??'')),
            'Nr do Contracto'=>(string)$ref->{'Nr do Contracto'},
            'Quantia Creditada'=>self::money($rows->max('Quantia Creditada')),
            '% de Juro'=>rtrim(rtrim(number_format((float)$ref->{'% de Juro'},2,',',''),'0'),','),
            'Data de Inicio'=>self::date($rows->min('Data de Inicio')),
            'Data de Cobrança'=>self::date($due),
            'Data Final'=>self::date($rows->max('Data Final')),
            'Valor a Pagar'=>self::money($ref->{'Valor a Pagar'}),
            'Dias'=>(string)($open->isNotEmpty()?(int)\Carbon\CarbonImmutable::parse($today)->diffInDays(\Carbon\CarbonImmutable::parse($due),false):(int)$ref->Dias),
            '-1*Dias'=>(string)(-1*($open->isNotEmpty()?(int)\Carbon\CarbonImmutable::parse($today)->diffInDays(\Carbon\CarbonImmutable::parse($due),false):(int)$ref->Dias)),
            'Valor em Dívida'=>self::money($owed),
            'Prestações Vencidas'=>(string)$late->count(),
            'Dias de Atraso'=>(string)$daysLate,
            '_situacao'=>$late->isNotEmpty()?'atrasado':($open->isNotEmpty()?'pendente':'finalizado'),
            '_cliente_id'=>(int)$ref->{'Nº de Cliente'},
            '_alternativo'=>trim((string)($client?->contacto_alternativo??'')),
        ];
    }

    private static function render(string $body,array $v): string
    {
        return preg_replace_callback('/\{([^{}]{1,40})\}/u',fn($m)=>array_key_exists($m[1],$v)&&!str_starts_with($m[1],'_')?$v[$m[1]]:$m[0],$body);
    }

    private function contractRows($db)
    {
        return $db->table('Contractos')->selectRaw(implode(',',array_map(fn($c)=>'`'.$c.'`',[
            'Ordem','Nr do Contracto','Nome do Cliente','Nº de Cliente','Quantia Creditada','% de Juro','Data de Inicio',
            'Data de Cobrança','Data Final','Valor a Pagar','Valor de Multa','Dias','Estado',
        ])));
    }

    private function body(Request $request): array
    {
        $d=$request->validate([
            'modelo_id'=>['nullable','integer','min:1'],
            'texto'=>['nullable','string','max:2000'],
        ]);
        if (isset($d['texto']) && trim($d['texto'])!=='') return [$d['texto'],$d['modelo_id']??null];
        abort_unless(isset($d['modelo_id']),422,'Escolha um modelo ou escreva a mensagem.');
        $b=DB::table('cis_message_templates')->where('id',$d['modelo_id'])->value('body');
        abort_unless($b!==null,404,'Modelo não encontrado.');
        return [$b,(int)$d['modelo_id']];
    }

    /** GET /cis/mensagens/preview?contrato=&modelo_id=|texto= */
    public function preview(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $numero=trim((string)$request->validate(['contrato'=>['required','string','max:155']])['contrato']);
            [$body]=$this->body($request);
            $db=DB::connection('mysql_legacy');
            $rows=$this->contractRows($db)->where('Nr do Contracto',$numero)->get();
            abort_if($rows->isEmpty(),404,'Contrato não encontrado.');
            $cid=(int)$rows->first()->{'Nº de Cliente'};
            $client=$this->clients($db,[$cid])[$cid]??null;
            $v=$this->values($rows,$client,now('Africa/Maputo')->toDateString());
            $alt=trim((string)($client?->contacto_alternativo??''));
            return response()->json([
                'contrato'=>$numero,'cliente'=>$v['Nome Completo'],'contacto'=>$v['Contacto']?:null,
                'telefone'=>self::phone($v['Contacto']),'situacao'=>$v['_situacao'],
                'contacto_alternativo'=>$alt!==''?$alt:null,'telefone_alternativo'=>self::phone($alt),
                'mensagem'=>self::render($body,$v),
                'valores'=>array_filter($v,fn($k)=>!str_starts_with($k,'_'),ARRAY_FILTER_USE_KEY),
            ])->header('Cache-Control','no-store, private');
        });
    }

    /** GET /cis/mensagens/destinatarios?grupo=&modelo_id=|texto= : fila de «Mandar para Todos». */
    public function destinatarios(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $g=$request->validate(['grupo'=>['required','in:'.implode(',',self::GROUPS)]])['grupo'];
            [$body]=$this->body($request);
            $db=DB::connection('mysql_legacy');
            $today=now('Africa/Maputo')->toDateString();
            $byContract=$this->contractRows($db)->orderBy('Ordem')->get()->groupBy('Nr do Contracto');
            $clients=$this->clients($db,$byContract->map(fn($r)=>$r->first()->{'Nº de Cliente'})->values()->all());
            $items=[];
            foreach ($byContract as $numero=>$rows) {
                $cid=(int)$rows->first()->{'Nº de Cliente'};
                $v=$this->values($rows,$clients[$cid]??null,$today);
                $items[]=['contrato'=>(string)$numero,'v'=>$v,'last'=>(string)$rows->min('Data de Inicio').'|'.$numero];
            }
            $items=collect($items);
            $picked=match ($g) {
                'pendentes'=>$items->filter(fn($i)=>$i['v']['_situacao']==='pendente'),
                'atrasados'=>$items->filter(fn($i)=>$i['v']['_situacao']==='atrasado'),
                // Clientes (uma mensagem por cliente, contrato mais recente do grupo).
                'activos'=>$items->filter(fn($i)=>$i['v']['_situacao']!=='finalizado')->groupBy(fn($i)=>$i['v']['_cliente_id']?:$i['contrato'])->map(fn($c)=>$c->sortByDesc('last')->first()),
                'inactivos'=>$items->groupBy(fn($i)=>$i['v']['_cliente_id']?:$i['contrato'])
                    ->filter(fn($c)=>$c->every(fn($i)=>$i['v']['_situacao']==='finalizado'))->map(fn($c)=>$c->sortByDesc('last')->first()),
                default=>$items->groupBy(fn($i)=>$i['v']['_cliente_id']?:$i['contrato'])->map(fn($c)=>$c->sortByDesc('last')->first()),
            };
            $list=$picked->values()->map(fn($i)=>[
                'contrato'=>$i['contrato'],'cliente'=>$i['v']['Nome Completo'],'cliente_id'=>$i['v']['_cliente_id']?:null,
                'contacto'=>$i['v']['Contacto']?:null,'telefone'=>self::phone($i['v']['Contacto']),
                'contacto_alternativo'=>$i['v']['_alternativo']?:null,'telefone_alternativo'=>self::phone($i['v']['_alternativo']),
                'situacao'=>$i['v']['_situacao'],'mensagem'=>self::render($body,$i['v']),
            ])->sortBy('cliente')->values();
            abort_if($list->count()>2000,422,'Grupo demasiado grande ('.$list->count().'); a fila aceita até 2000 destinatários.');
            return response()->json([
                'grupo'=>$g,'total'=>$list->count(),
                'com_contacto'=>$list->whereNotNull('telefone')->count(),
                'destinatarios'=>$list,
            ])->header('Cache-Control','no-store, private');
        });
    }

    /** POST /cis/mensagens/registo : regista que o operador abriu/copiu a mensagem para o cliente. */
    public function registo(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $d=$request->validate([
                'contrato'=>['nullable','string','max:155'],
                'cliente_id'=>['nullable','integer','min:1'],
                'telefone'=>['nullable','string','max:40'],
                'canal'=>['required','in:whatsapp,whatsapp_web,sms,copia'],
                'estado'=>['nullable','in:aberta,enviada,falhada'],
                'detalhe'=>['nullable','string','max:255'],
                'modelo_id'=>['nullable','integer','min:1'],
                'grupo'=>['nullable','in:'.implode(',',self::GROUPS)],
                'mensagem'=>['required','string','min:1','max:4000'],
            ]);
            $row=[
                'contract_number'=>$d['contrato']??null,'client_id'=>$d['cliente_id']??null,
                'phone'=>isset($d['telefone'])?self::phone($d['telefone']):null,'channel'=>$d['canal'],
                'template_id'=>$d['modelo_id']??null,'group'=>$d['grupo']??null,'body'=>$d['mensagem'],
                'sent_by'=>$request->user()->id,'created_at'=>now(),
            ];
            $hasStatus=\Illuminate\Support\Facades\Schema::hasColumn('cis_message_log','status');
            if ($hasStatus) $row+=['status'=>$d['estado']??($d['canal']==='copia'?null:'aberta'),'detail'=>$d['detalhe']??null];
            $id=DB::table('cis_message_log')->insertGetId($row);
            return response()->json(['id'=>$id,'estado_registado'=>$hasStatus],201);
        });
    }

    /** GET /cis/mensagens/registos : registo de mensagens (enviadas/falhadas/abertas) com filtros. */
    public function registos(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $f=$request->validate([
                'estado'=>['nullable','in:todos,enviada,falhada,aberta'],
                'canal'=>['nullable','in:todos,whatsapp_web,whatsapp,sms,copia'],
                'contrato'=>['nullable','string','max:155'],
                'inicio'=>['nullable','date_format:Y-m-d'],
                'fim'=>['nullable','date_format:Y-m-d'],
                'page'=>['nullable','integer','min:1'],
            ]);
            $hasStatus=\Illuminate\Support\Facades\Schema::hasColumn('cis_message_log','status');
            $q=DB::table('cis_message_log as l')->leftJoin('users as u','u.id','=','l.sent_by');
            if (($f['estado']??'todos')!=='todos') {
                abort_unless($hasStatus,503,'Falta actualizar o registo de mensagens: execute bash deploy/install-message-center.sh');
                $q->where('l.status',$f['estado']);
            }
            if (($f['canal']??'todos')!=='todos') $q->where('l.channel',$f['canal']);
            if (trim((string)($f['contrato']??''))!=='') $q->where('l.contract_number',trim($f['contrato']));
            if ($f['inicio']??null) $q->where('l.created_at','>=',$f['inicio'].' 00:00:00');
            if ($f['fim']??null) $q->where('l.created_at','<=',$f['fim'].' 23:59:59');
            $tot=(clone $q)->selectRaw($hasStatus
                ?"COUNT(*) n, SUM(l.status='enviada') enviadas, SUM(l.status='falhada') falhadas, SUM(l.status='aberta') abertas"
                :"COUNT(*) n, 0 enviadas, 0 falhadas, COUNT(*) abertas")->first();
            $page=max(1,(int)($f['page']??1));$per=30;
            $cols=['l.id','l.contract_number','l.client_id','l.phone','l.channel','l.body','l.created_at','l.group','u.name as por'];
            if ($hasStatus) { $cols[]='l.status'; $cols[]='l.detail'; }
            $rows=$q->orderByDesc('l.id')->forPage($page,$per)->get($cols);
            // Nome do cliente pelo contrato (legado, só leitura).
            $names=[];
            $nums=$rows->pluck('contract_number')->filter()->unique()->values()->all();
            if ($nums) {
                try {
                    $names=DB::connection('mysql_legacy')->table('Contractos')->whereIn('Nr do Contracto',$nums)
                        ->selectRaw('`Nr do Contracto` AS n, MAX(`Nome do Cliente`) AS c')->groupBy('Nr do Contracto')->pluck('c','n')->all();
                } catch (\Throwable $e) { report($e); }
            }
            return response()->json([
                'linhas'=>$rows->map(fn($r)=>[
                    'id'=>(int)$r->id,'data'=>(string)$r->created_at,'contrato'=>$r->contract_number,
                    'cliente'=>$names[$r->contract_number]??null,'telefone'=>$r->phone,'canal'=>$r->channel,
                    'estado'=>$r->status??'aberta','motivo'=>$r->detail??null,'grupo'=>$r->group,'por'=>$r->por,'mensagem'=>$r->body,
                ])->values(),
                'pagina'=>$page,'por_pagina'=>$per,'total_linhas'=>(int)$tot->n,
                'totais'=>['enviadas'=>(int)$tot->enviadas,'falhadas'=>(int)$tot->falhadas,'abertas'=>(int)$tot->abertas],
                'estado_disponivel'=>$hasStatus,
            ])->header('Cache-Control','no-store, private');
        });
    }

    /** GET /cis/mensagens/historico?contrato= : mensagens já preparadas para o contrato. */
    public function historico(Request $request)
    {
        $this->admin($request);
        return $this->guard(function () use ($request) {
            $n=trim((string)$request->validate(['contrato'=>['required','string','max:155']])['contrato']);
            $rows=DB::table('cis_message_log as l')->leftJoin('users as u','u.id','=','l.sent_by')
                ->where('l.contract_number',$n)->orderByDesc('l.id')->limit(50)
                ->get(['l.id','l.channel','l.status','l.detail','l.phone','l.body','l.created_at','u.name as por']);
            return response()->json(['mensagens'=>$rows])->header('Cache-Control','no-store, private');
        });
    }
}
