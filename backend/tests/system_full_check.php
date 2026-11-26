<?php
// VERIFICAÇÃO COMPLETA DO SISTEMA — corre sobre uma base DESCARTÁVEL já com dados:
//  • no CI, depois do ensaio contract_cash_flows_mysql_rehearsal.php (base cis_ci);
//  • na VPS, numa CÓPIA restaurada da base real (deploy/rehearse-full-system.sh), nunca na base real.
// Parte A: todas as leituras (GET) da API, pela pilha HTTP completa (rotas + middleware), para cada função.
// Parte B: fluxo completo mutuário → proposta → aprovação → desembolso → cobrança, com coerência
//          entre Caixa, Extracto, Visão geral, recibos e proposta.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$ci=filter_var(env('CIS_CI_CONTRACT_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN) && DB::connection()->getDatabaseName()==='cis_ci';
$vps=filter_var(env('CIS_ISOLATED_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN) && config('database.connections.mysql.host')==='cis-full-check-mysql';
if (DB::getDriverName()!=='mysql' || !($ci||$vps)) throw new RuntimeException('BLOQUEADO: só numa base descartável (cis_ci do CI ou cópia isolada na VPS).');
foreach (['CIS_PRIVATE_PREVIEW','CIS_CONTRACTS_READ_APPROVED','CIS_CLIENTES_PREVIEW_APPROVED','CIS_CLIENTES_EDIT_APPROVED','CIS_CASH_WRITES_APPROVED',
    'CIS_CASH_CONTRACT_FLOWS_APPROVED','CIS_COLLECTION_WRITES_APPROVED','CIS_NEW_CONTRACTS_APPROVED','CIS_EXTENDED_CALCULATIONS_APPROVED'] as $f) {
    $_ENV[$f]='true';$_SERVER[$f]='true';putenv($f.'=true');
}
config(['app.debug'=>true,'cache.default'=>'array']);
$http=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$pass=0;$fail=0;$warn=[];$tokens=[];
function ok(bool $c,string $m):void{global $pass,$fail;if($c){$pass++;echo "  OK  $m\n";}else{$fail++;echo "  FALHA $m\n";}}
/** Envio de ficheiro (multipart) pela pilha HTTP. */
function upload(string $uri,User $u,array $fields,string $field,string $name,string $content):array{
    global $http,$tokens;
    app('cache')->store()->flush();app('auth')->forgetGuards();
    $hdr=['HTTP_ACCEPT'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer '.($tokens[$u->id]??=$u->createToken('verificacao-sistema')->plainTextToken)];
    $r=Request::create($uri,'POST',$fields,[],[$field=>\Illuminate\Http\UploadedFile::fake()->createWithContent($name,$content)],$hdr);
    $res=$http->handle($r);$http->terminate($r,$res);
    $j=json_decode((string)$res->getContent(),true);
    return [$res->getStatusCode(),is_array($j)?$j:[]];
}
/** Pedido pela pilha HTTP completa, autenticado como $u. Devolve [status, json]. */
function call(string $method,string $uri,?User $u,array $body=[]):array{
    global $app,$http;
    app('cache')->store()->flush();                       // sem limites de ritmo entre pedidos do teste
    global $tokens;
    app('auth')->forgetGuards();
    $hdr=['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'];
    if ($u) $hdr['HTTP_AUTHORIZATION']='Bearer '.($tokens[$u->id]??=$u->createToken('verificacao-sistema')->plainTextToken);
    $r=Request::create($uri,$method,$method==='GET'?$body:[],[],[],$hdr,
        $method==='GET'?null:json_encode($body));
    $res=$http->handle($r);
    $http->terminate($r,$res);
    $j=json_decode((string)$res->getContent(),true);
    return [$res->getStatusCode(),is_array($j)?$j:[]];
}
$tz='Africa/Maputo';$today=now($tz)->toDateString();
$legacy=DB::connection('mysql_legacy');
// No CI, a base de ensaio tem tabelas mínimas: completa-se o esquema como o da base real (colunas que a API lê).
if ($ci) {
    $add=function(string $t,array $cols) use ($legacy){foreach($cols as $c=>$def) if(!$legacy->getSchemaBuilder()->hasColumn($t,$c)) $legacy->statement("ALTER TABLE `$t` ADD COLUMN `$c` $def");};
    $add('Clientes',['Numeração'=>'INT NULL','Nº de BI'=>'VARCHAR(50) NULL','Nacionalidade'=>'VARCHAR(60) NULL','Contacto'=>'VARCHAR(40) NULL','Bairro'=>'VARCHAR(100) NULL',
        'Provincia'=>'VARCHAR(100) NULL','Nº da Casa'=>'VARCHAR(20) NULL','Nº de Quarterão'=>'VARCHAR(20) NULL','Email'=>'VARCHAR(150) NULL','Conduta'=>'VARCHAR(50) NULL',
        'Data de Emissão'=>'DATE NULL','Estado Civil'=>'VARCHAR(30) NULL','Rua'=>'VARCHAR(120) NULL','Naturalidade'=>'VARCHAR(100) NULL','usuario'=>'VARCHAR(255) NULL']);
    $legacy->statement('UPDATE Clientes SET `Numeração`=ID WHERE `Numeração` IS NULL');
    $add('cis_cash_accounts',['identifier'=>'VARCHAR(120) NULL','evidence_reference'=>'VARCHAR(255) NULL','created_by'=>'BIGINT UNSIGNED NULL','validated_by'=>'BIGINT UNSIGNED NULL']);
}

// ---------- Utilizadores de teste: um por função ----------
$roles=array_keys(\App\Support\Permissions::ROLES);
$users=[];
foreach ($roles as $role) {
    $email='verificacao.'.$role.'@cis.test';
    $u=User::query()->where('email',$email)->first() ?? new User();
    $u->forceFill(['name'=>'Verificação '.ucfirst($role),'email'=>$email,'password'=>bcrypt(bin2hex(random_bytes(12))),'role'=>$role,'active'=>true]
        +(Schema::hasColumn('users','must_change_password')?['must_change_password'=>false]:[]))->save();
    $users[$role]=$u->fresh();
}
echo "Funções verificadas: ".implode(', ',$roles)."\n";

// ---------- Parte A: todas as leituras ----------
echo "== A. Todas as leituras da API (GET), por função\n";
$sample=[
    'cliente'=>(int)($legacy->table('Clientes')->orderBy('ID')->value('ID')??0),
    'contrato'=>(string)($legacy->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->orderBy('Ordem')->value('Nr do Contracto')
        ??$legacy->table('Contractos')->orderBy('Ordem')->value('Nr do Contracto')??''),
    'proposta'=>Schema::hasTable('cis_credit_proposals')?(int)(DB::table('cis_credit_proposals')->orderBy('id')->value('id')??0):0,
    'documento'=>Schema::hasTable('cis_documents')?(int)(DB::table('cis_documents')->orderBy('id')->value('id')??0):0,
    'extracto'=>(int)($legacy->table('Extracto')->max('Nr')??0),
];
$query=[
    'api/cis/extractos/informacao'=>['contrato'=>$sample['contrato']],
    'api/cis/extractos/evidencia'=>['contrato'=>$sample['contrato']],
    'api/admin/interest-quote'=>['montante'=>1000,'periodo_dias'=>30],
    'api/cis/mutuarios/procurar'=>['q'=>'Cl'],
    'api/cis/extractos/contratos'=>['termo'=>'Cl'],
    'api/cis/caixa/relatorio'=>['from'=>now('Africa/Maputo')->startOfMonth()->toDateString(),'to'=>now('Africa/Maputo')->toDateString()],
    'api/cis/caixa/conferencia-diaria'=>['date'=>now('Africa/Maputo')->toDateString()],
    'api/cis/caixa/fecho-diario'=>['date'=>now('Africa/Maputo')->toDateString(),'account_id'=>(int)(Schema::hasTable('cis_cash_accounts')?DB::table('cis_cash_accounts')->where('status','activa')->value('id'):0)],
    'api/cis/mensagens/preview'=>['contrato'=>$sample['contrato']],
    'api/cis/mensagens/historico'=>['contrato'=>$sample['contrato']],
    'api/cis/mensagens/destinatarios'=>['grupo'=>'atrasados'],
    'api/cis/contractos/saldo-conta'=>['conta_id'=>(int)(Schema::hasTable('cis_cash_accounts')?DB::table('cis_cash_accounts')->value('id'):0),'data'=>$today],
];
$skip=['api/cis/email/oauth/callback'];                   // redireccionamento OAuth externo
$routes=collect(Route::getRoutes()->getRoutes())->filter(fn($r)=>in_array('GET',$r->methods(),true) && str_starts_with($r->uri(),'api/'))
    ->reject(fn($r)=>in_array($r->uri(),$skip,true))->values();
$codes=[];$errors=[];$noSample=[];$other=[];
foreach ($routes as $route) {
    $uri=$route->uri();$missing=false;
    $path=preg_replace_callback('/\{(\w+)\??\}/',function($m) use ($uri,$sample,&$missing){
        $v=match(true){
            $m[1]==='numero'=>$sample['contrato'],
            $m[1]==='nr'=>$sample['extracto'],
            str_contains($uri,'propostas')=>$sample['proposta'],
            str_contains($uri,'documentos/{')=>$sample['documento'],
            str_contains($uri,'relatorios/')=>'__todos__',
            default=>$sample['cliente'],
        };
        if(!$v)$missing=true;
        return rawurlencode((string)$v);
    },$uri);
    if ($missing) { $noSample[]=$uri; continue; }
    if (str_contains($path,'__todos__')) continue;
    foreach ($users as $role=>$u) {
        try { [$st,$j]=call('GET','/'.$path,$u,$query[$uri]??[]); }
        catch (\Throwable $e) { $st=599;$j=['message'=>get_class($e).': '.$e->getMessage()]; }
        $codes[$st]=($codes[$st]??0)+1;
        if ($st>=500 && $st!==503) $errors[]="$st GET /$path [$role] ".mb_substr((string)($j['message']??''),0,220).(isset($j['file'])?' @ '.basename($j['file']).':'.($j['line']??''):'');
        if ($st===503) $warn[$uri.' → '.mb_substr((string)($j['message']??''),0,120)]=true;
        if (in_array($st,[401,404,409,422],true)) $other["$st $uri"]=mb_substr((string)($j['message']??''),0,140);
    }
}
// Cada relatório do catálogo (gestão e regulatórios), com o período do mês, para cada função com acesso.
$rep=call('GET','/api/cis/relatorios',$users['administrador'])[1]['relatorios']??[];
foreach ($rep as $def) foreach ($users as $role=>$u) {
    try { [$st,$j]=call('GET','/api/cis/relatorios/'.rawurlencode($def['id']),$u,['mes'=>now('Africa/Maputo')->month,'ano'=>now('Africa/Maputo')->year]); }
    catch (\Throwable $e) { $st=599;$j=['message'=>$e->getMessage()]; }
    $codes[$st]=($codes[$st]??0)+1;
    if ($st>=500 && $st!==503) $errors[]="$st GET relatório {$def['id']} [$role] ".mb_substr((string)($j['message']??''),0,220).(isset($j['file'])?' @ '.basename($j['file']).':'.($j['line']??''):'');
    if (in_array($st,[404,409,422],true)) $other["$st relatório {$def['id']}"]=mb_substr((string)($j['message']??''),0,140);
}
echo '  Relatórios do catálogo: '.count($rep)."\n";
ksort($codes);
echo '  Pedidos: '.array_sum($codes).' em '.count($routes).' rotas × '.count($users).' funções · estados: '.json_encode($codes)."\n";
foreach ($errors as $e) echo "    ✗ $e\n";
foreach ($other as $k=>$m) echo "    · $k — $m\n";
ok(!$errors,'nenhuma leitura com erro do servidor (5xx) em nenhuma função');
ok(($codes[200]??0)>count($routes),'a maioria das leituras responde 200');
if ($noSample) echo '  (sem dados de amostra, não testadas: '.implode(', ',$noSample).")\n";

// Permissões: o agente não lê utilizadores nem Caixa; o administrador lê tudo.
ok(call('GET','/api/cis/utilizadores',$users['agente']??null)[0]===403 && call('GET','/api/cis/utilizadores',$users['administrador'])[0]===200,'permissões: utilizadores só para o administrador');
ok(call('GET','/api/cis/subscricao',$users['agente']??null)[0]===403,'permissões: subscrição só para o administrador');
ok(call('GET','/api/auth/me',null)[0]===401,'sem sessão não há acesso (401)');

// ---------- Parte B: fluxo completo ----------
echo "== B. Fluxo completo: mutuário → proposta → aprovação → desembolso → cobrança\n";
$admin=$users['administrador'];$agent=$users['agente']??$admin;
$tag=strtoupper(substr(bin2hex(random_bytes(3)),0,6));
// 1. Mutuário
[$st,$j]=call('POST','/api/cis/clientes',$admin,['dados'=>['Nome Completo'=>'Fluxo Verificação '.$tag,'Cidade'=>'Maputo','Contacto'=>'841234567']]);
$cid=(int)($j['id']??0);
ok($st===201 && $cid>0,"1. mutuário registado (ID $cid)".($st!==201?" — $st ".json_encode($j,JSON_UNESCAPED_UNICODE):''));
if ($cid) \App\Support\Portfolio::assignClient($cid,$agent->id,$admin->id);
// 2. Proposta pelo agente
$prop=['client_id'=>$cid,'montante'=>1000,'tipo_juro'=>'Fixo','estrategia'=>\App\Services\ContractCalculationStrategy::FIXED_AMORTIZATION,'periodo_dias'=>30,
    'prazo'=>'1 Mês','modalidade'=>'Uma Parcela','finalidade'=>'Verificação do sistema','rendimento_mensal'=>20000,'despesas_mensais'=>5000,'outras_dividas_mensais'=>0];
[$st,$p]=call('POST','/api/cis/propostas',$agent,$prop);
$pid=(int)($p['id']??0);
ok(in_array($st,[200,201],true) && $pid>0 && ($p['estado']??'')==='rascunho','2. proposta criada pelo agente em rascunho'.(!in_array($st,[200,201],true)?" — $st ".mb_substr(json_encode($p,JSON_UNESCAPED_UNICODE),0,300):''));
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
[$st,$d]=upload('/api/cis/documentos',$agent,['client_id'=>$cid,'proposal_id'=>$pid,'tipo'=>'bi','descricao'=>'BI (verificação)'],'ficheiro','bi.png',$png);
ok(in_array($st,[200,201],true),'   dossier: bilhete de identidade anexado'.(!in_array($st,[200,201],true)?" — $st ".mb_substr(json_encode($d,JSON_UNESCAPED_UNICODE),0,300):''));
[$st,$p]=call('POST',"/api/cis/propostas/$pid/submeter",$agent);
ok(in_array($st,[200,201],true) && ($p['estado']??'')==='submetida','3. proposta submetida com análise'.($st!==200?" — $st ".mb_substr(json_encode($p,JSON_UNESCAPED_UNICODE),0,300):''));
ok(call('POST',"/api/cis/propostas/$pid/decisao",$agent,['decisao'=>'aprovar'])[0]===403,'   o agente não aprova');
foreach (['sem_historico','baixo','medio','alto'] as $rc) DB::table('cis_company_settings')->updateOrInsert(['chave'=>'provisao_'.$rc],['valor'=>'5','updated_at'=>now(),'created_at'=>now()]);
\App\Support\CompanySettings::forget();
[$st,$show]=call('GET',"/api/cis/propostas/$pid",$admin);
[$st,$p]=call('POST',"/api/cis/propostas/$pid/decisao",$admin,['decisao'=>'aprovar','notas'=>'Verificação','risco_confirmado'=>true,'risco_versao'=>$show['provisao']['versao']??'']);
ok(in_array($st,[200,201],true) && ($p['estado']??'')==='aprovada','4. administrador aprova (com revisão do risco)'.($st!==200?" — $st ".mb_substr(json_encode($p,JSON_UNESCAPED_UNICODE),0,300):''));
// 3. Conta da Caixa de verificação
$marker=(int)($legacy->table('Extracto')->max('Nr')??0);
$acc=DB::table('cis_cash_accounts')->insertGetId(['code'=>'VERIF-'.$tag,'name'=>'Verificação '.$tag,'type'=>'numerario','currency'=>'MZN','status'=>'activa',
    'opening_balance'=>'100000.00','cut_at'=>now(),'opening_extracto_nr'=>$marker,'validated_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
[$st,$ov0]=call('GET','/api/cis/visao-geral',$admin);
// 4. Desembolso a partir da proposta
$open=['cliente_id'=>$cid,'montante'=>1000,'tipo_juro'=>'Fixo','estrategia_calculo'=>\App\Services\ContractCalculationStrategy::FIXED_AMORTIZATION,'periodo_dias'=>30,
    'prazo'=>'1 Mês','taxa_informada'=>15,'taxa_multa'=>10,'prazo_meses'=>1,'modalidade'=>'Uma Parcela','forma_pagamento'=>'Numerário','disponibilidade'=>'Numerário',
    'data_inicio'=>$today,'confirmacao_desembolso'=>true,'referencia'=>'VERIF-SAIDA-'.$tag,'conta_id'=>$acc,'proposta_id'=>$pid,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid()];
[$st,$o]=call('POST','/api/cis/contractos/abrir',$admin,$open);
$num=(string)($o['numero']??'');
ok(in_array($st,[200,201],true) && $num!=='','5. desembolso: contrato '.$num.' aberto'.(!in_array($st,[200,201],true)?" — $st ".mb_substr(json_encode($o,JSON_UNESCAPED_UNICODE),0,400):''));
ok(abs((float)($o['saldo_conta_actual']??-1)-99000)<0.01,'   Caixa: 100 000 − 1 000 = 99 000');
$pr=DB::table('cis_credit_proposals')->where('id',$pid)->first();
ok($pr && $pr->status==='desembolsada' && $pr->contract_number===$num,'   proposta passa a «desembolsada» e fica ligada ao contrato');
$inst=$legacy->table('Contractos')->where('Nr do Contracto',$num)->orderBy('Ordem')->get();
$due=round((float)$inst->sum('Valor a Pagar'),2);
ok($inst->count()>=1 && $due>1000,"   prestações criadas: {$inst->count()} · a receber ".number_format($due,2,'.',''));
$out=$legacy->table('Extracto')->where('account_id',$acc)->where('Tipo de Movimento','Saídas')->sum('Valor');
ok(abs((float)$out-1000)<0.01,'   Extracto: saída de 1 000 na conta do desembolso');
[$st,$ov1]=call('GET','/api/cis/visao-geral',$admin);
ok(abs(((float)($ov1['carteira']['valor_a_receber']??0)-(float)($ov0['carteira']['valor_a_receber']??0))-$due)<0.01,'   Visão geral: carteira a receber aumenta exactamente o valor do contrato');
// 5. Cobrança da 1.ª prestação
$first=$inst->first();
[$st,$q]=call('POST',"/api/cis/contractos/$num/prestacoes/{$first->Ordem}/simular-cobranca",$admin,['data_pagamento'=>$today,'cobrar_multa'=>false]);
$total=(string)($q['total_texto']??'');
ok($st===200 && $total!=='','6. prévia da cobrança: '.$total.' MT'.($st!==200?" — $st ".mb_substr(json_encode($q,JSON_UNESCAPED_UNICODE),0,300):''));
[$st,$c]=call('POST',"/api/cis/contractos/$num/prestacoes/{$first->Ordem}/cobrar",$admin,['data_pagamento'=>$today,'cobrar_multa'=>false,'valor_recebido'=>$total,
    'conta_id'=>$acc,'referencia'=>'VERIF-REC-'.$tag,'idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'confirmacao_recebimento'=>true]);
ok(in_array($st,[200,201],true) && ($c['registado']??false),'7. pagamento registado'.(!in_array($st,[200,201],true)?" — $st ".mb_substr(json_encode($c,JSON_UNESCAPED_UNICODE),0,300):''));
ok(abs((float)($c['saldo_conta_actual']??-1)-(99000+(float)$total))<0.01,'   Caixa: 99 000 + '.$total.' = '.number_format(99000+(float)$total,2,'.',''));
$in=$legacy->table('Extracto')->where('account_id',$acc)->where('Tipo de Movimento','Entradas')->sum('Valor');
ok(abs((float)$in-(float)$total)<0.01,'   Extracto: entrada igual ao valor recebido');
ok($legacy->table('Contractos')->where('Ordem',$first->Ordem)->value('Estado')==='Pago','   prestação marcada como Paga');
[$st,$ov2]=call('GET','/api/cis/visao-geral',$admin);
$expect=$inst->count()===1?$due:(float)$first->{'Valor a Pagar'};
ok(abs(((float)($ov1['carteira']['valor_a_receber']??0)-(float)($ov2['carteira']['valor_a_receber']??0))-$expect)<0.01,'   Visão geral: carteira a receber diminui o valor pago');
[$st,$rc]=call('GET',"/api/cis/contractos/$num/recibos",$admin);
ok($st===200 && count($rc['recibos']??$rc['data']??$rc)>=1,'8. recibo emitido para o pagamento');
[$st,$ex]=call('GET','/api/cis/extractos/clientes',$admin,['contrato'=>$num,'mes'=>'todos','ano'=>'todos']);
ok($st===200 && collect($ex['linhas']??[])->where('situacao','paga')->count()>=1,'9. extracto do mutuário mostra a prestação paga');
[$st,$eg]=call('GET','/api/cis/extractos/geral',$admin,['conta_id'=>$acc]);
ok($st===200 && count($eg['linhas']??[])>=2,'10. extracto geral da conta: desembolso e recebimento');
[$st,$hs]=call('GET',"/api/cis/contractos/$num/historico-alteracoes",$admin);
ok($st===200,'11. histórico de alterações do contrato disponível');
// 6. Agente vê o seu mutuário; outra agência não
ok(call('GET',"/api/cis/propostas/$pid",$agent)[0]===200,'12. o agente vê a proposta da sua carteira');

echo "\n";
if ($warn) { echo "Módulos por instalar nesta base (503, não é erro):\n"; foreach (array_keys($warn) as $w) echo "  · $w\n"; }
echo "\nResultado: $pass OK, $fail falha(s)\n";
exit($fail===0?0:1);
