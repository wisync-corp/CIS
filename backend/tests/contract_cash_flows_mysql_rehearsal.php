<?php
// ENSAIO REAL em MySQL 8 DESCARTÁVEL (serviço do CI): pagamentos e desembolsos
// ligados à Caixa + histórico imutável das prestações.
// Só corre com CIS_CI_CONTRACT_REHEARSAL=true e base 'cis_ci' (nunca produção).
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\ContractCollectionController;
use App\Http\Controllers\Api\ContractOpeningController;
use App\Models\User;
use App\Services\ContractCalculationStrategy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (!filter_var(env('CIS_CI_CONTRACT_REHEARSAL',false),FILTER_VALIDATE_BOOLEAN)
    || DB::getDriverName()!=='mysql' || DB::connection()->getDatabaseName()!=='cis_ci') {
    throw new RuntimeException('BLOQUEADO: ensaio apenas no MySQL descartável cis_ci do CI.');
}
foreach (['CIS_PRIVATE_PREVIEW','CIS_CONTRACTS_READ_APPROVED','CIS_CLIENTES_PREVIEW_APPROVED',
    'CIS_CASH_WRITES_APPROVED','CIS_CASH_CONTRACT_FLOWS_APPROVED','CIS_COLLECTION_WRITES_APPROVED',
    'CIS_NEW_CONTRACTS_APPROVED'] as $f) {
    $_ENV[$f]='true';$_SERVER[$f]='true';putenv($f.'=true');
}
$fail=0;$pass=0;
function ok(bool $c,string $m):void{global $fail,$pass; if($c){$pass++;echo "  OK  $m\n";}else{$fail++;echo "  FALHA $m\n";}}
function status(callable $fn):int{ try{$fn();return 200;}catch(HttpExceptionInterface $e){return $e->getStatusCode();}catch(ValidationException $e){return 422;} }
function req(string $uri,array $payload,User $u):Request{$r=Request::create($uri,'POST',$payload);$r->setUserResolver(static fn()=>$u);return $r;}
function data($response):array{return $response->getData(true);}

try {
// ---------- Esquema legado (colunas verificadas pelo próprio controlador) ----------
$db=DB::connection();
foreach (['cis_contractos_historico','Contractos','Extracto','Clientes','cis_tabela_juros','cis_cash_accounts','users','personal_access_tokens','cis_user_audit'] as $t) {
    $db->statement('DROP TABLE IF EXISTS `'.$t.'`');
}
$db->unprepared(<<<'SQL'
CREATE TABLE users(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255),email VARCHAR(255) UNIQUE,password VARCHAR(255),
 role ENUM('administrador','gestor','agente','cobrador'),legacy_username VARCHAR(255) NULL,active TINYINT(1) DEFAULT 1,
 remember_token VARCHAR(100) NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL) ENGINE=InnoDB;
CREATE TABLE Clientes(ID INT AUTO_INCREMENT PRIMARY KEY,`Nome Completo` VARCHAR(255),Cidade VARCHAR(100)) ENGINE=InnoDB;
CREATE TABLE cis_tabela_juros(id BIGINT AUTO_INCREMENT PRIMARY KEY,limite_inferior INT UNSIGNED,limite_superior INT UNSIGNED,
 taxa DECIMAL(8,2),periodo_referencia SMALLINT UNSIGNED) ENGINE=InnoDB;
CREATE TABLE cis_cash_accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40),name VARCHAR(120),type VARCHAR(20),
 currency CHAR(3),status VARCHAR(30),opening_balance DECIMAL(18,2) NULL,cut_at DATETIME NULL,opening_extracto_nr BIGINT NULL,
 validated_at DATETIME NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL) ENGINE=InnoDB;
CREATE TABLE Extracto(Nr INT AUTO_INCREMENT PRIMARY KEY,Contracto VARCHAR(50),Cliente VARCHAR(255),Parcela VARCHAR(50),
 `Data da Operação` DATE,Valor FLOAT,`Tipo de Movimento` VARCHAR(20),`Descrição` VARCHAR(255),usuario VARCHAR(255),
 account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(120) NULL,UNIQUE KEY cis_extracto_operation_key_uq(operation_key))
 ENGINE=InnoDB DEFAULT CHARSET=latin1;
CREATE TABLE Contractos(Ordem INT AUTO_INCREMENT PRIMARY KEY,`Nr do Contracto` VARCHAR(50),`Nº` INT,`Nome do Cliente` VARCHAR(255),
 `Nº de Cliente` INT,`Quantia Creditada` FLOAT,`M. Pagamento` VARCHAR(50),`Prazo/Duração` VARCHAR(50),`% de Juro` INT,
 `Tipo de Juro` VARCHAR(20),`% de Multa` INT,`Forma de Pagamento` VARCHAR(80),Disponibilidade VARCHAR(80),`Data de Inicio` DATE,
 Cidade VARCHAR(100),`Data Final` DATE,`Data de Cobrança` DATE,Capital INT,`Amortização` INT,`Valor a Pagar` FLOAT,
 `Data de Pagamento` DATE NULL,`Valor de Juros` FLOAT,`Valor de Multa` FLOAT,`Valor Pago` FLOAT,Estado VARCHAR(40),
 `Codigo Cliente` VARCHAR(20),`Estado do Contrato` VARCHAR(40),`Nº da Parcela` INT,`Nº de Prestações` INT,
 `Estratégia de Cálculo` VARCHAR(90) NULL,Column1 VARCHAR(40),Dias INT,Intervalo VARCHAR(60),`Situação de Pagamento` VARCHAR(60),
 usuario VARCHAR(255)) ENGINE=InnoDB;
SQL);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',[
    '--path'=>'database/migrations/2026_11_18_000070_create_cis_contractos_historico.php','--force'=>true]);
$db->unprepared(<<<'SQL'
CREATE TRIGGER cis_contr_hist_no_update BEFORE UPDATE ON cis_contractos_historico FOR EACH ROW
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historico de prestacoes e imutavel: UPDATE recusado';
CREATE TRIGGER cis_contr_hist_no_delete BEFORE DELETE ON cis_contractos_historico FOR EACH ROW
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historico de prestacoes e imutavel: DELETE recusado';
SQL);

// ---------- Escritor RESTRITO com as MESMAS permissões da VPS (nunca root) ----------
$db->unprepared(<<<'SQL'
DROP USER IF EXISTS 'cis_contract_writer'@'%';
CREATE USER 'cis_contract_writer'@'%' IDENTIFIED BY 'ci-writer-pw';
GRANT SELECT ON `cis_ci`.`Clientes` TO 'cis_contract_writer'@'%';
GRANT SELECT ON `cis_ci`.`cis_tabela_juros` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON `cis_ci`.`Extracto` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON `cis_ci`.`Contractos` TO 'cis_contract_writer'@'%';
GRANT UPDATE (`Valor de Juros`,`Valor de Multa`,`Valor a Pagar`,`Valor Pago`,
 `Data de Pagamento`,`Estado`,`Dias`,`Situação de Pagamento`,
 `Estado do Contrato`,`Data de Cobrança`,`Data de Inicio`,`Data Final`,`Capital`,`Amortização`,`% de Juro`,`Prazo/Duração`) ON `cis_ci`.`Contractos` TO 'cis_contract_writer'@'%';
GRANT SELECT ON `cis_ci`.`cis_cash_accounts` TO 'cis_contract_writer'@'%';
GRANT UPDATE (`updated_at`) ON `cis_ci`.`cis_cash_accounts` TO 'cis_contract_writer'@'%';
GRANT SELECT, INSERT ON `cis_ci`.`cis_contractos_historico` TO 'cis_contract_writer'@'%';
SQL);
config(['database.connections.mysql_contract_writer.username'=>'cis_contract_writer',
    'database.connections.mysql_contract_writer.password'=>'ci-writer-pw']);
DB::purge('mysql_contract_writer');
if (DB::connection('mysql_contract_writer')->selectOne('SELECT CURRENT_USER() AS u')->u!=='cis_contract_writer@%') {
    throw new RuntimeException('O ensaio tem de usar o escritor restrito, não root.');
}
echo "Escritor restrito activo: cis_contract_writer@% (permissões da VPS)\n";

// ---------- Dados ----------
$tz='Africa/Maputo';
$today=now($tz)->toDateString();
$user=User::query()->create(['name'=>'Admin Ensaio','email'=>'admin@ci.test','password'=>bcrypt('x'),'role'=>'administrador','active'=>true]);
$db->table('Extracto')->insert(['Contracto'=>'','Cliente'=>'LEGADO','Parcela'=>'0','Data da Operação'=>'2024-01-01',
    'Valor'=>5000,'Tipo de Movimento'=>'Entradas','Descrição'=>'Histórico','usuario'=>'vba']);
$marker=(int)$db->table('Extracto')->max('Nr');
$acc=fn($code,$bal)=>$db->table('cis_cash_accounts')->insertGetId(['code'=>$code,'name'=>$code,'type'=>'numerario','currency'=>'MZN',
    'status'=>'activa','opening_balance'=>$bal,'cut_at'=>now(),'opening_extracto_nr'=>$marker,'validated_at'=>now(),
    'created_at'=>now(),'updated_at'=>now()]);
$numerario=$acc('DINHEIRO','100.00');
$banco=$acc('BIM-001','20000.00');
$row=function(array $o) use ($db,$tz){ $attrs=$o+[
    'Nr do Contracto'=>'C-0100','Nº'=>100,'Nome do Cliente'=>'Cliente Histórico','Nº de Cliente'=>7,'Quantia Creditada'=>1000,
    'M. Pagamento'=>'Uma Parcela','Prazo/Duração'=>'1 Mês','% de Juro'=>15,'Tipo de Juro'=>'Fixo','% de Multa'=>10,
    'Forma de Pagamento'=>'Numerário','Disponibilidade'=>'Numerário','Data de Inicio'=>now($tz)->subDays(20)->toDateString(),
    'Cidade'=>'Maputo','Data Final'=>now($tz)->addDays(10)->toDateString(),'Data de Cobrança'=>now($tz)->addDays(10)->toDateString(),
    'Capital'=>1000,'Amortização'=>1000,'Valor a Pagar'=>1150.5,'Data de Pagamento'=>null,'Valor de Juros'=>150.5,
    'Valor de Multa'=>0,'Valor Pago'=>1150.5,'Estado'=>'Pendente','Codigo Cliente'=>'007','Estado do Contrato'=>'Em Progresso',
    'Nº da Parcela'=>1,'Nº de Prestações'=>1,'Estratégia de Cálculo'=>null,'Column1'=>'Mensal','Dias'=>0,'Intervalo'=>'',
    'Situação de Pagamento'=>'Em Progresso','usuario'=>'vba'];
    // `M. Pagamento` tem ponto: o Query Builder separa-o; usar o mesmo SQL do controlador.
    [$sql,$bind]=\App\Services\LegacyContractInsert::statement($attrs);
    $db->insert($sql,$bind);
    return (int)$db->getPdo()->lastInsertId();
};
$hist=$row([]);                                     // histórico, fixo, em dia
$late=$row(['Nr do Contracto'=>'C-0101','Nº'=>101,'Tipo de Juro'=>'Variável','Capital'=>2000,'Quantia Creditada'=>2000,
    '% de Juro'=>1,'Data de Inicio'=>now($tz)->subDays(40)->toDateString(),'Data de Cobrança'=>now($tz)->subDays(5)->toDateString(),
    'Estado'=>'Pagamento Atrasado','Valor a Pagar'=>2600,'Valor de Juros'=>600,'Valor Pago'=>2600]);
$dupe=$row(['Nr do Contracto'=>'C-0102','Nº'=>102]);
$db->table('Extracto')->insert(['Contracto'=>'C-0102','Cliente'=>'x','Parcela'=>'1','Data da Operação'=>'2025-01-01',
    'Valor'=>10,'Tipo de Movimento'=>'Entradas','Descrição'=>'Receita de Juros','usuario'=>'vba']);
$atomic=$row(['Nr do Contracto'=>'C-0103','Nº'=>103]);

$col=new ContractCollectionController();
$snap=fn()=>[$db->table('Extracto')->count(),(string)$db->table('Extracto')->sum('Valor'),
    $db->table('Contractos')->where('Estado','like','Pago%')->count(),$db->table('cis_contractos_historico')->count()];

echo "== Cobrança: contrato histórico (VBA), juro fixo, em dia\n";
$q=data($col->preview(req('/x',['data_pagamento'=>$today,'cobrar_multa'=>false],$user),'C-0100',$hist));
ok($q['origem']==='historico' && $q['total_texto']==='1150.50' && $q['registo_activo']===true,'prévia histórica = 1150.50 com registo activo');
$key='11111111-1111-4111-8111-111111111111';
$pay=['data_pagamento'=>$today,'cobrar_multa'=>false,'valor_recebido'=>'1150.50','conta_id'=>$numerario,
    'referencia'=>'RECIBO-001','idempotency_key'=>$key,'confirmacao_recebimento'=>true];
$r=data($col->collect(req('/x',$pay,$user),'C-0100',$hist));
ok($r['registado'] && !$r['repetido'] && $r['saldo_conta_actual']==='1250.50','pagamento registado; saldo 100 → 1250.50');
$e=$db->table('Extracto')->where('operation_key','contract:collect:'.$key)->first();
ok($e && (int)$e->account_id===$numerario && $e->{'Tipo de Movimento'}==='Entradas' && abs((float)$e->Valor-1150.5)<0.001,'Extracto com account_id, Entrada e valor');
$c=$db->table('Contractos')->where('Ordem',$hist)->first();
ok($c->Estado==='Pago' && $c->{'Situação de Pagamento'}==='Pagamento Completo' && $c->{'Data de Pagamento'}===$today,'prestação Pago / Pagamento Completo');
ok($c->{'Estado do Contrato'}==='Finalizado','contrato finalizado (última prestação)');
$h=$db->table('cis_contractos_historico')->where('installment_ordem',$hist)->get();
ok($h->count()===1,'1 registo de histórico');
$before=json_decode($h[0]->before_snapshot,true);$after=json_decode($h[0]->after_snapshot,true);
ok($before['Estado']==='Pendente' && $after['Estado']==='Pago' && (int)$h[0]->extracto_nr===(int)$e->Nr,'histórico guarda ANTES (Pendente) e DEPOIS (Pago) + Nr do Extracto');

echo "== Repetição do mesmo pedido (duplo clique)\n";
$s=$snap();
$r2=data($col->collect(req('/x',$pay,$user),'C-0100',$hist));
ok($r2['repetido']===true && $snap()===$s,'mesma chave: nada duplicado');

echo "== Valor errado / conta inexistente / referência em falta\n";
$s=$snap();
ok(status(fn()=>$col->collect(req('/x',['valor_recebido'=>'2599.00','idempotency_key'=>'22222222-2222-4222-8222-222222222222']+$pay,$user),'C-0101',$late))===422,'valor diferente do devido → 422');
ok(status(fn()=>$col->collect(req('/x',['conta_id'=>999,'idempotency_key'=>'33333333-3333-4333-8333-333333333333']+$pay,$user),'C-0101',$late))===404,'conta inexistente → 404');
ok(status(fn()=>$col->collect(req('/x',array_diff_key($pay,['referencia'=>1]),$user),'C-0101',$late))===422,'sem referência → 422');
ok($snap()===$s,'nenhuma alteração nas tentativas recusadas');

echo "== Contrato histórico atrasado, juro variável, com multa\n";
$q=data($col->preview(req('/x',['data_pagamento'=>$today,'cobrar_multa'=>true],$user),'C-0101',$late));
// capital 2000 × 1% × 40 dias = 800; base 2800; multa 10% de 800 = 80; total 2880
ok($q['total_texto']==='2880.00' && $q['dias_atraso']===5 && $q['estado']==='Pago em Atraso','variável: 2000×1%×40d=800 + multa 80 = 2880.00');
$r=data($col->collect(req('/x',['cobrar_multa'=>true,'mora'=>['tipo'=>'legado'],'valor_recebido'=>'2880.00','conta_id'=>$banco,
    'idempotency_key'=>'44444444-4444-4444-8444-444444444444']+$pay,$user),'C-0101',$late));
ok($r['registado'] && $r['saldo_conta_actual']==='22880.00','entrada na conta BIM-001 escolhida');
$stored=json_decode($db->table('cis_contractos_historico')->where('operation_key','contract:collect:44444444-4444-4444-8444-444444444444')->value('after_snapshot'),true);
ok(($stored['Regra de mora']['cobrado_centimos']??null)===8000,'histórico guarda a regra legada e a mora cobrada');

echo "== Prestação com entrada já existente no Extracto\n";
ok(status(fn()=>$col->collect(req('/x',['idempotency_key'=>'55555555-5555-4555-8555-555555555555']+$pay,$user),'C-0102',$dupe))===409,'bloqueada (409) — evita cobrar duas vezes');

echo "== Atomicidade: falha no histórico reverte tudo\n";
$db->unprepared("CREATE TRIGGER ci_break_hist BEFORE INSERT ON cis_contractos_historico FOR EACH ROW
 BEGIN IF NEW.contract_number='C-0103' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='falha simulada'; END IF; END");
$s=$snap();
$failed=false; try{$col->collect(req('/x',['idempotency_key'=>'66666666-6666-4666-8666-666666666666']+$pay,$user),'C-0103',$atomic);}catch(\Throwable $t){$failed=true;}
ok($failed && $snap()===$s && $db->table('Contractos')->where('Ordem',$atomic)->value('Estado')==='Pendente','sem histórico → sem pagamento, sem Extracto (rollback)');
$db->unprepared('DROP TRIGGER ci_break_hist');

echo "== Histórico imutável\n";
$blocked=fn($sql)=>(function()use($db,$sql){try{$db->statement($sql);return false;}catch(\Throwable $t){return str_contains($t->getMessage(),'imutavel');}})();
ok($blocked("UPDATE cis_contractos_historico SET reason='x'"),'UPDATE recusado pela base de dados');
ok($blocked("DELETE FROM cis_contractos_historico"),'DELETE recusado pela base de dados');

echo "== Desembolso a partir de uma conta escolhida\n";
$db->table('Clientes')->insert(['ID'=>7,'Nome Completo'=>'Cliente Novo','Cidade'=>'Maputo']);
$db->table('cis_tabela_juros')->insert(['limite_inferior'=>1,'limite_superior'=>100000,'taxa'=>15,'periodo_referencia'=>30]);
$open=new ContractOpeningController();
$base=['cliente_id'=>7,'montante'=>3000,'tipo_juro'=>'Fixo','estrategia_calculo'=>ContractCalculationStrategy::FIXED_AMORTIZATION,
    'periodo_dias'=>30,'prazo'=>'1 Mês','taxa_informada'=>15,'taxa_multa'=>10,'prazo_meses'=>1,'modalidade'=>'Uma Parcela',
    'forma_pagamento'=>'Numerário','disponibilidade'=>'Numerário','data_inicio'=>$today,'confirmacao_desembolso'=>true,
    'referencia'=>'SAIDA-001'];
$s=$snap();
ok(status(fn()=>$open->store(req('/x',$base+['conta_id'=>$numerario,'idempotency_key'=>'77777777-7777-4777-8777-777777777777'],$user)))===422
    && $snap()===$s,'saldo insuficiente na conta Numerário (1250.50 < 3000) → 422, nada gravado');
$k='88888888-8888-4888-8888-888888888888';
$o=data($open->store(req('/x',$base+['conta_id'=>$banco,'idempotency_key'=>$k],$user)));
ok(($o['conta']??'')==='BIM-001' && $o['saldo_conta_actual']==='19880.00','desembolso da BIM-001: 22880 → 19880');
$out=$db->table('Extracto')->where('operation_key','contract:open:'.$k)->first();
ok($out && (int)$out->account_id===$banco && $out->{'Tipo de Movimento'}==='Saídas','Saída no Extracto ligada à conta');
$n=$o['numero'];
$rows=$db->table('Contractos')->where('Nr do Contracto',$n)->count();
ok($rows>0 && $db->table('cis_contractos_historico')->where('contract_number',$n)->where('operation','desembolso')->count()===$rows,
    'histórico: uma fotografia por prestação criada');
$s=$snap();
$o2=data($open->store(req('/x',$base+['conta_id'=>$banco,'idempotency_key'=>$k],$user)));
ok(($o2['repetido']??false)===true && $o2['numero']===$n && $snap()===$s,'repetição do desembolso: devolve o mesmo contrato, nada duplicado');

echo "== Indicadores da Visão geral (SQL real)\n";
$ov=data((new \App\Http\Controllers\Api\OverviewController())(Request::create('/x','GET')));
// Pagas: C-0100 (1150,50; juros 150,50) e C-0101 (2880; juros 800; multa 80).
ok($ov['recebimentos']['juros']==='950.50' && $ov['recebimentos']['multas']==='80.00','juros 950,50 e multas 80,00 recebidos');
ok($ov['recebimentos']['total_recebido']==='4030.50' && $ov['recebimentos']['capital_recuperado']==='3000.00','recebido 4030,50 = capital 3000 + juros + multa');
ok($ov['recebimentos']['juros_mes']==='950.50','juros do mês contam os pagamentos de hoje');
ok($ov['carteira']['prestacoes_vencidas']===0 && $ov['carteira']['contratos_activos']===3,'0 vencidas; 3 contratos por receber (C-0102, C-0103, novo)');
ok((float)$ov['carteira']['capital_em_carteira']>=5000,'capital em carteira inclui o novo desembolso de 3000');
ok($ov['caixa']['disponivel']===true && $ov['caixa']['saldo_total']==='21130.50','saldo da Caixa = 1250,50 + 19880,00');
ok($ov['caixa']['entradas_mes']==='4030.50' && $ov['caixa']['desembolsos_mes']==='3000.00','entradas 4030,50 e desembolsos 3000,00 no mês');
$db->table('Contractos')->where('Ordem',$dupe)->update(['Data de Cobrança'=>now($tz)->subDays(3)->toDateString()]);
$ov=data((new \App\Http\Controllers\Api\OverviewController())(Request::create('/x','GET')));
ok($ov['carteira']['prestacoes_vencidas']===1 && $ov['carteira']['valor_vencido']==='1150.50' && $ov['carteira']['contratos_em_atraso']===1,'prestação com vencimento passado conta como vencida (1150,50)');

echo "== Lista de contratos: capital, prestação a pagar e situação\n";
$list=new \App\Http\Controllers\Api\ContractListController();
$sum=data($list->summary());
$bySit=collect($sum['por_situacao'])->pluck('total','situacao');
ok($bySit['Finalizado']===2 && $bySit['Pagamento Atrasado']===1 && $bySit['Pendente']===2,'separadores: 2 finalizados, 1 atrasado, 2 pendentes');
$late=collect($list->index(Request::create('/x','GET',['situacao'=>'Pagamento Atrasado']))->items());
ok($late->count()===1 && $late[0]->numero==='C-0102','filtro «Pagamento atrasado» devolve só C-0102');
echo '    valores: capital='.var_export($late[0]->capital,true).' prestação='.var_export($late[0]->prestacao_a_pagar,true).' vence='.var_export($late[0]->proximo_vencimento,true)."\n";
ok((string)$late[0]->prestacao_a_pagar==='1150.50' && $late[0]->proximo_vencimento===now($tz)->subDays(3)->toDateString()
    && (string)$late[0]->capital==='1000.00','C-0102: capital 1000,00, prestação 1150,50 e vencimento correcto');
$fin=collect($list->index(Request::create('/x','GET',['situacao'=>'Finalizado']))->items());
ok($fin->count()===2 && $fin->every(fn($r)=>$r->prestacao_a_pagar===null),'finalizados sem prestação a pagar');

echo "== Leitura do histórico pela API\n";
$hh=data($col->history(tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(fn()=>$user)),'C-0100'));
ok($hh['instalado']===true && count($hh['data'])===1 && $hh['data'][0]['antes']['Estado']==='Pendente','endpoint devolve antes/depois');


echo "== Renovação do empréstimo (mesmo contrato, juros pagos)\n";
$err=function(callable $f){ try { $f(); return null; } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode(); } catch (\Illuminate\Validation\ValidationException $e) { return 422; }
    catch (\Illuminate\Validation\ValidationException $e) { return 422; } };
$oldDue=now($tz)->subDays(5)->toDateString();
$ren=$row(['Nr do Contracto'=>'C-0110','Nº'=>110,'Data de Inicio'=>now($tz)->subDays(35)->toDateString(),
    'Data de Cobrança'=>$oldDue,'Data Final'=>$oldDue,'Estado'=>'Pagamento Atrasado']);
$rq=fn($o)=>req('/x',$o+['data_pagamento'=>$today,'cobrar_multa'=>false,'data_inicio'=>$oldDue],$user);
$p0=data($col->previewRenewal($rq([]),'C-0110',$ren));
$expDue=\Carbon\CarbonImmutable::parse($oldDue)->addMonthsNoOverflow(1)->toDateString();
ok($p0['total_texto']==='150.50' && $p0['nova_prestacao']['data_vencimento']===$expDue && $p0['nova_prestacao']['prazo']==='1 Mês'
    && $p0['nova_prestacao']['parcela']===2 && $p0['nova_prestacao']['valor_a_pagar']==='1150.50','prévia: só juros 150,50; vencimento = início + 1 mês');
$p1=data($col->previewRenewal($rq(['cobrar_multa'=>true]),'C-0110',$ren));
ok($p1['total_texto']==='165.55' && $p1['multa']===15.05,'multa opcional sobre os juros (165,55)');
ok($err(fn()=>$col->previewRenewal($rq(['data_inicio'=>now($tz)->subDays(40)->toDateString()]),'C-0110',$ren))===422,'início anterior ao actual recusado');
$balBefore=\App\Services\CashAccountLedger::balance($db,$db->table('cis_cash_accounts')->find($numerario));
$rk='a1a1a1a1-0110-4000-8000-000000000001';
$rp=['data_pagamento'=>$today,'cobrar_multa'=>true,'data_inicio'=>$oldDue,'valor_recebido'=>'165.55','conta_id'=>$numerario,
    'referencia'=>'REN-001','idempotency_key'=>$rk,'confirmacao_recebimento'=>true];
ok($err(fn()=>$col->renew(req('/x',['valor_recebido'=>'150.50']+$rp,$user),'C-0110',$ren))===422,'valor diferente do calculado recusado');
$r1=data($col->renew(req('/x',$rp,$user),'C-0110',$ren));
$old=$db->table('Contractos')->where('Ordem',$ren)->first();
$new=$db->table('Contractos')->where('Ordem',$r1['nova_ordem'])->first();
ok($old->Estado==='Renovado' && abs((float)$old->{'Valor Pago'}-165.55)<0.001 && $old->{'Data de Pagamento'}===$today,'prestação antiga: Renovado, pagos 165,55');
ok($new->{'Nr do Contracto'}==='C-0110' && (int)$new->{'Nº da Parcela'}===2 && $new->Estado==='Pendente'
    && $new->{'Data de Inicio'}===$oldDue && $new->{'Data de Cobrança'}===$expDue && abs((float)$new->{'Valor a Pagar'}-1150.5)<0.001
    && (int)$new->Capital===1000 && (float)$new->{'Valor Pago'}===0.0,'nova prestação no mesmo contrato com o mesmo capital e juros');
ok(\App\Services\CashAccountLedger::balance($db,$db->table('cis_cash_accounts')->find($numerario))===$balBefore+16555,'juros entram na conta Numerário');
$hr=$db->table('cis_contractos_historico')->where('contract_number','C-0110')->pluck('operation')->sort()->values()->all();
ok($hr===['renovacao_nova_prestacao','renovar_juros'],'histórico regista a renovação e a nova prestação');
$r1b=data($col->renew(req('/x',$rp,$user),'C-0110',$ren));
ok($r1b['repetido']===true && $db->table('Contractos')->where('Nr do Contracto','C-0110')->count()===2,'repetição com a mesma chave não duplica');
// Sem limite: renovar outra vez a nova prestação.
$r2=data($col->renew(req('/x',['data_inicio'=>$expDue,'cobrar_multa'=>false,'valor_recebido'=>'150.50',
    'idempotency_key'=>'a1a1a1a1-0110-4000-8000-000000000002']+$rp,$user),'C-0110',$r1['nova_ordem']));
ok($r2['nova_prestacao']['parcela']===3 && $db->table('Contractos')->where('Nr do Contracto','C-0110')->where('Estado','Renovado')->count()===2,'segunda renovação (sem limite) cria P3');
$fin3=data($col->collect(req('/x',['data_pagamento'=>$today,'cobrar_multa'=>false,'valor_recebido'=>'1150.50','conta_id'=>$numerario,
    'referencia'=>'RECIBO-110','idempotency_key'=>'a1a1a1a1-0110-4000-8000-000000000003','confirmacao_recebimento'=>true],$user),'C-0110',$r2['nova_ordem']));
ok($fin3['contrato_finalizado']===true,'pagamento integral da última prestação finaliza o contrato renovado');
$two=$row(['Nr do Contracto'=>'C-0111','Nº'=>111,'Nº de Prestações'=>2]);
$row(['Nr do Contracto'=>'C-0111','Nº'=>111,'Nº de Prestações'=>2,'Nº da Parcela'=>2]);
ok($err(fn()=>$col->previewRenewal($rq(['data_inicio'=>$today]),'C-0111',$two))===409,'recusa renovar com mais de uma prestação em aberto');


echo "== Data de pagamento escolhida (hoje ou passada)\n";
$bd=$row(['Nr do Contracto'=>'C-0120','Nº'=>120]);
$bp=fn($o)=>$o+['data_pagamento'=>now($tz)->subDays(2)->toDateString(),'cobrar_multa'=>false,'valor_recebido'=>'1150.50',
    'conta_id'=>$numerario,'referencia'=>'RECIBO-120','idempotency_key'=>'b2b2b2b2-0120-4000-8000-000000000001','confirmacao_recebimento'=>true];
ok($err(fn()=>$col->preview(req('/x',['data_pagamento'=>now($tz)->addDay()->toDateString(),'cobrar_multa'=>false],$user),'C-0120',$bd))===422,'data futura recusada');
ok($err(fn()=>$col->collect(req('/x',$bp([]),$user),'C-0120',$bd))===422,'data anterior à abertura da conta recusada');
$gestor=clone $user;$gestor->role='agente'; // perfil sem cobranças/caixa/mensagens/relatórios/definições
ok($err(fn()=>$col->collect(req('/x',$bp([]),$gestor),'C-0120',$bd))===403,'agente não regista pagamentos (nem com data passada)');
$db->table('cis_cash_accounts')->whereIn('id',[$numerario,$banco])->update(['cut_at'=>now($tz)->subDays(15)]);
$main=\Illuminate\Support\Facades\DB::connection();
if (!$main->getSchemaBuilder()->hasTable('cis_cash_daily_closures')) {
    $main->statement('CREATE TABLE cis_cash_daily_closures(id BIGINT AUTO_INCREMENT PRIMARY KEY,account_id BIGINT UNSIGNED,closing_date DATE)');
}
$main->table('cis_cash_daily_closures')->insert(['account_id'=>$banco,'closing_date'=>now($tz)->subDays(1)->toDateString()]);
try { $col->collect(req('/x',$bp(['conta_id'=>$banco]),$user),'C-0120',$bd); $why='aceite'; }
catch (\Throwable $e) { $why=get_class($e).': '.$e->getMessage(); }
ok(str_contains($why,'fecho diário'),'dia com fecho de Caixa recusado ('.$why.')');
$r=data($col->collect(req('/x',$bp([]),$user),'C-0120',$bd));
$pr=$db->table('Contractos')->where('Ordem',$bd)->first();
$ex=$db->table('Extracto')->where('Nr',$r['extracto_nr'])->first();
ok($pr->{'Data de Pagamento'}===now($tz)->subDays(2)->toDateString() && substr((string)$ex->{'Data da Operação'},0,10)===now($tz)->subDays(2)->toDateString()
    && $pr->Estado==='Pago','pagamento com data passada gravado nessa data (contrato e Extracto)');


echo "== Visão geral: filtro de mês e ano\n";
$ov=new \App\Http\Controllers\Api\OverviewController();
$get=fn(array $q)=>data($ov(Request::create('/x','GET',$q)));
$all=$get(['mes'=>'todos','ano'=>'todos']);
ok($all['recebimentos']['recebido_mes']===$all['recebimentos']['total_recebido'] && $all['periodo']['mes']==='todos','Todos/Todos = total geral');
$def=$get([]);
ok($def['periodo']['mes']===(string)now($tz)->month && $def['periodo']['ano']===(string)now($tz)->year && in_array((int)now($tz)->year,$def['periodo']['anos'],true),'por defeito: mês e ano actuais');
$expM=(float)$db->table('Contractos')->whereIn('Estado',['Pago','Pago em Atraso','Renovado'])
    ->whereYear('Data de Pagamento',now($tz)->year)->whereMonth('Data de Pagamento',now($tz)->month)->sum('Valor Pago');
ok(abs((float)$def['recebimentos']['recebido_mes']-$expM)<0.01,'recebido no mês actual confere com a base');
$py=$get(['mes'=>'todos','ano'=>(string)now($tz)->year]);
ok((float)$py['recebimentos']['recebido_mes']>=(float)$def['recebimentos']['recebido_mes'],'ano inteiro inclui o mês');
ok($err(fn()=>$ov(Request::create('/x','GET',['mes'=>'13'])))===422,'mês inválido recusado');
$expD=0.0;$expP=0.0;$n=0;
foreach ($db->table('Contractos')->get()->groupBy('Nr do Contracto') as $rows) {
    $d=$rows->min('Data de Inicio');
    if (substr((string)$d,0,7)!==now($tz)->format('Y-m') || $d>now($tz)->toDateString()) continue;
    $n++;$expD+=(float)$rows->max('Quantia Creditada');
    $expP+=(float)$rows->where('Estado','!=','Renovado')->sum('Valor a Pagar');
}
ok($def['carteira']['contratos_desembolsados_periodo']===$n && abs((float)$def['carteira']['desembolsado_periodo']-$expD)<0.01
    && abs((float)$def['carteira']['prestacoes_desembolsadas_periodo']-$expP)<0.01,'carteira desembolsada e prestações do mês conferem ('.$n.' contratos)');
$t0=$get(['mes'=>'todos','ano'=>'todos']);
ok((float)$t0['carteira']['desembolsado_periodo']>=(float)$def['carteira']['desembolsado_periodo'],'Todos/Todos inclui a carteira do mês');
$open=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->get();
$tod=now($tz)->toDateString();
ok($t0['carteira']['vencidas_periodo']===$open->filter(fn($r)=>$r->{'Data de Cobrança'}<$tod)->count()
    && $t0['carteira']['pendentes_periodo']===$open->filter(fn($r)=>$r->{'Data de Cobrança'}>=$tod)->count()
    && $t0['carteira']['vencidas_periodo']+$t0['carteira']['pendentes_periodo']===$open->count(),'Todos/Todos: vencidas + pendentes = todas em aberto');
$inM=$open->filter(fn($r)=>substr((string)$r->{'Data de Cobrança'},0,7)===now($tz)->format('Y-m'));
ok($def['carteira']['vencidas_periodo']+$def['carteira']['pendentes_periodo']===$inM->count()
    && abs((float)$def['carteira']['valor_pendente_periodo']+(float)$def['carteira']['valor_vencido_periodo']-(float)$inM->sum('Valor a Pagar'))<0.01,'mês actual: vencidas e pendentes pela data de vencimento');


echo "== Extracto (geral, clientes, evidência)\n";
$ex=new \App\Http\Controllers\Api\ExtractoController();
$greq=fn(array $q,$u=null)=>tap(Request::create('/x','GET',$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
$eg=data($ex->geral($greq(['por_pagina'=>200])));
$ascending=data($ex->geral($greq(['direccao'=>'asc','por_pagina'=>10])));
$allAscending=data($ex->geral($greq(['direccao'=>'asc','por_pagina'=>200])));
ok(count($eg['linhas'])>1
    && array_reverse(array_column($eg['linhas'],'nr'))===array_column($allAscending['linhas'],'nr')
    && $ascending['linhas']===array_slice($allAscending['linhas'],0,10),
    'ordem cronológica crescente e decrescente aplicada antes da paginação');
$clientAsc=data($ex->clientes($greq(['direccao'=>'asc','por_pagina'=>200])));
$clientDesc=data($ex->clientes($greq(['direccao'=>'desc','por_pagina'=>200])));
ok(array_reverse(array_column($clientAsc['linhas'],'vencimento'))===array_column($clientDesc['linhas'],'vencimento'),
    'extracto do mutuário ordenado pelo vencimento');
$amounts = static fn(array $result):array => array_map('floatval',array_column($result['linhas'],'valor'));
$cashAmounts=$amounts(data($ex->geral($greq(['ordenar'=>'valor','direccao'=>'asc','por_pagina'=>200]))));
$clientAmounts=$amounts(data($ex->clientes($greq(['ordenar'=>'valor','direccao'=>'desc','por_pagina'=>200]))));
$expectedCash=$cashAmounts;sort($expectedCash,SORT_NUMERIC);
$expectedClient=$clientAmounts;rsort($expectedClient,SORT_NUMERIC);
ok(count($cashAmounts)>1 && $cashAmounts===$expectedCash,'extracto geral ordena montantes numericamente');
ok(count($clientAmounts)>1 && $clientAmounts===$expectedClient,'extracto do mutuário ordena montantes numericamente');


$expE=round((float)$db->table('Extracto')->where('Tipo de Movimento','Entradas')->sum('Valor'),2);
$expS=round((float)$db->table('Extracto')->where('Tipo de Movimento','<>','Entradas')->sum('Valor'),2);
ok($eg['total_linhas']===$db->table('Extracto')->count() && abs((float)$eg['totais']['entradas']-$expE)<0.01
    && abs((float)$eg['totais']['saidas']-$expS)<0.01 && abs((float)$eg['totais']['disponivel']-($expE-$expS))<0.01,'geral: entradas, saídas e disponível');
$egc=data($ex->geral($greq(['contrato'=>'C-0110','tipo'=>'entradas'])));
ok($egc['total_linhas']===$db->table('Extracto')->where('Contracto','C-0110')->where('Tipo de Movimento','Entradas')->count()
    && collect($egc['linhas'])->every(fn($l)=>$l['contrato']==='C-0110' && $l['tipo']==='entrada'),'geral: filtro por contrato e tipo');
$egn=data($ex->geral($greq(['conta_id'=>$numerario])));
ok($egn['total_linhas']===$db->table('Extracto')->where('account_id',$numerario)->count() && ($egn['linhas'][0]['conta']??null)!==null,'geral: filtro por conta da Caixa');
ok($err(fn()=>$ex->geral($greq([],$gestor)))===403,'geral reservado ao administrador');
ok($err(fn()=>$ex->geral($greq(['inicio'=>'2026-02-01','fim'=>'2026-01-01'])))===422,'intervalo de datas inválido recusado');
$ec=data($ex->clientes($greq(['por_pagina'=>200])));
$tod=now($tz)->toDateString();
$openV=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->get()
    ->filter(fn($r)=>$r->{'Data de Cobrança'}<$tod);
ok($ec['total_linhas']===$db->table('Contractos')->count() && $ec['totais']['n_vencido']===$openV->count()
    && abs((float)$ec['totais']['vencido']-$openV->sum(fn($r)=>(float)$r->{'Valor a Pagar'}+(float)$r->{'Valor de Multa'}))<0.01,'clientes: vencidas pela data e valor em dívida');
$paidSum=(float)$db->table('Contractos')->whereIn('Estado',['Pago','Pago em Atraso','Renovado'])->sum('Valor Pago');
ok(abs((float)$ec['totais']['pago']-$paidSum)<0.01,'clientes: total pago (inclui renovações)');
$ecv=data($ex->clientes($greq(['estado'=>'vencida'])));
ok($ecv['total_linhas']===$openV->count() && collect($ecv['linhas'])->every(fn($l)=>$l['estado']==='Em atraso'),'clientes: filtro Vencidas');
$name=$db->table('Contractos')->where('Nr do Contracto','C-0110')->value('Nome do Cliente');
$lk=data($ex->contratos($greq(['termo'=>mb_substr($name,0,4)])));
$lc=data($ex->contratos($greq(['cliente'=>$name])));
ok(collect($lk['clientes'])->pluck('nome')->contains($name) && in_array('C-0110',$lc['contratos'],true),'pesquisa de cliente e contratos do cliente');
$ev=data($ex->evidencia($greq(['contrato'=>'C-0110'])));
ok(count($ev['prestacoes'])===3 && $ev['contratos'][0]['contrato']==='C-0110' && $ev['contratos'][0]['situacao']==='Liquidado'
    && strlen($ev['codigo_verificacao'])===16 && count($ev['movimentos'])===$db->table('Extracto')->where('Contracto','C-0110')->count(),'evidência: contrato renovado e liquidado com prestações e movimentos');
ok(collect($ev['movimentos'])->every(fn($m)=>!str_contains($m['descricao'],'[CAIXA') && !str_contains($m['descricao'],'REF:')),'evidência sem marcas internas nem referências');
ok($err(fn()=>$ex->evidencia($greq([])))===422,'evidência exige cliente ou contrato');
$inf=data($ex->informacao($greq(['contrato'=>'C-0110'])));
$cid=(int)$db->table('Contractos')->where('Nr do Contracto','C-0110')->value('Nº de Cliente');
ok($inf['contrato']==='C-0110' && $inf['situacao']==='Finalizado' && $inf['prestacoes']['total']===3 && $inf['prestacoes']['pagas']===3
    && $inf['em_divida']==='0.00' && $inf['cliente']['codigo']===str_pad((string)$cid,3,'0',STR_PAD_LEFT)
    && $inf['quantia_creditada']===number_format((float)$db->table('Contractos')->where('Nr do Contracto','C-0110')->max('Quantia Creditada'),2,'.',''),'informação: contrato renovado finalizado, código do cliente com 3 dígitos');
$late=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->where('Data de Cobrança','<',$tod)->value('Nr do Contracto');
if ($late) { $il=data($ex->informacao($greq(['contrato'=>$late]))); ok($il['situacao']==='Pagamento atrasado' && $il['prestacoes']['vencidas']>0 && $il['dias_atraso']>0,'informação: contrato com atraso'); }
ok($err(fn()=>$ex->informacao($greq(['contrato'=>'NAO-EXISTE'])))===404,'informação: contrato inexistente');
// Cartões da Visão geral → Extracto: os números têm de coincidir.
foreach ([['todos','todos'],[(string)now($tz)->month,(string)now($tz)->year]] as [$m,$y]) {
    $o=data($ov(Request::create('/x','GET',['mes'=>$m,'ano'=>$y])));
    $xv=data($ex->clientes($greq(['mes'=>$m,'ano'=>$y,'base'=>'cobranca','estado'=>'vencida'])));
    $xp=data($ex->clientes($greq(['mes'=>$m,'ano'=>$y,'base'=>'cobranca','estado'=>'aberta'])));
    $xj=data($ex->clientes($greq(['mes'=>$m,'ano'=>$y,'base'=>'pagamento','estado'=>'paga'])));
    $xd=data($ex->clientes($greq(['mes'=>$m,'ano'=>$y,'base'=>'desembolso','por_pagina'=>200])));
    ok($xv['total_linhas']===$o['carteira']['vencidas_periodo'] && $xv['totais']['vencido']===$o['carteira']['valor_vencido_periodo']
        && $xp['total_linhas']===$o['carteira']['pendentes_periodo'] && $xp['totais']['aberto']===$o['carteira']['valor_pendente_periodo'],"cartões vencidas/pendentes = extracto ($m/$y)");
    ok(abs((float)$xj['totais']['juros_pago']-((float)$o['recebimentos']['juros_mes']+(float)$o['recebimentos']['multas_mes']))<0.01,"cartões juros+multas = extracto pagas ($m/$y)");
    ok(collect($xd['linhas'])->pluck('contrato')->unique()->count()===$o['carteira']['contratos_desembolsados_periodo'],"cartão carteira desembolsada = contratos do extracto ($m/$y)");
}
$gd=data($ex->geralEvidencia($greq(['conta_id'=>$numerario])));
ok($gd['total_linhas']===$egn['total_linhas'] && count($gd['linhas'])===$gd['total_linhas'] && $gd['totais']===data($ex->geral($greq(['conta_id'=>$numerario])))['totais']
    && str_starts_with((string)$gd['filtros']['conta'],'DINHEIRO') && strlen($gd['codigo_verificacao'])===16,'extracto geral (PDF): todas as linhas e totais da conta');
ok(collect($gd['linhas'])->every(fn($m)=>!str_contains($m['descricao'],'[CAIXA') && !str_contains($m['descricao'],'REF:')),'extracto geral sem marcas internas');
ok($err(fn()=>$ex->geralEvidencia($greq([],$gestor)))===403,'extracto geral (PDF) reservado ao administrador');
// Leitor restrito como na VPS (cis_reader): só SELECT nas tabelas do legado, nada de cis_cash_*.
$db->statement("CREATE USER IF NOT EXISTS 'ci_reader'@'%' IDENTIFIED BY 'ci_reader_pw'");
foreach (['Contractos','Extracto','Clientes'] as $tb) $db->statement("GRANT SELECT ON `cis_ci`.`$tb` TO 'ci_reader'@'%'");
$legacyCfg=config('database.connections.mysql_legacy');
config(['database.connections.mysql_legacy.username'=>'ci_reader','database.connections.mysql_legacy.password'=>'ci_reader_pw']);
DB::purge('mysql_legacy');
try {
    $rg=data($ex->geral($greq(['conta_id'=>$numerario])));
    $rc=data($ex->clientes($greq([])));
    $rv=data($ex->evidencia($greq(['contrato'=>'C-0110'])));
    $rd=data($ex->geralEvidencia($greq([])));
    ok($rg['total_linhas']===$egn['total_linhas'] && ($rg['linhas'][0]['conta']??null)==='DINHEIRO' && $rc['total_linhas']>0
        && count($rv['prestacoes'])===3 && $rd['total_linhas']>0,'extracto funciona com leitor restrito (sem acesso a cis_cash_*)');
} catch (\Throwable $e) { ok(false,'extracto com leitor restrito: '.$e->getMessage()); }
finally { config(['database.connections.mysql_legacy'=>$legacyCfg]); DB::purge('mysql_legacy'); }


echo "== Centro de mensagens\n";
$db->statement('DROP TABLE IF EXISTS cis_message_log');$db->statement('DROP TABLE IF EXISTS cis_message_templates');
(require __DIR__.'/../database/migrations/2026_11_19_000080_create_cis_message_center.php')->up();
(require __DIR__.'/../database/migrations/2026_11_19_000100_add_status_to_cis_message_log.php')->up();
$mc=new \App\Http\Controllers\Api\MessageCenterController();
$jreq=fn(string $m,array $q,$u=null)=>tap(Request::create('/x',$m,$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
$mods=data($mc->modelos($jreq('GET',[])));
ok(count($mods['modelos'])===4 && in_array('{-1*Dias}',$mods['campos'],true),'modelos iniciais e campos (inclui {-1*Dias})');
$new=data($mc->criarModelo($jreq('POST',['name'=>'Teste CI','body'=>'Olá {Primeiro Nome}, contrato {Nr do Contracto}: {Valor em Dívida} MT, {-1*Dias} dias. {Campo Inexistente}'])));
ok($err(fn()=>$mc->criarModelo($jreq('POST',['name'=>'Teste CI','body'=>'x x x'])))===422,'nome de modelo duplicado recusado');
data($mc->actualizarModelo($jreq('PUT',['name'=>'Teste CI','body'=>'Olá {Primeiro Nome}, contrato {Nr do Contracto}: {Valor em Dívida} MT, {-1*Dias} dias. {Campo Inexistente}']),$new['id']));
$pv=data($mc->preview($jreq('GET',['contrato'=>'C-0110','modelo_id'=>$new['id']])));
ok(str_contains($pv['mensagem'],'contrato C-0110: 0,00 MT') && str_contains($pv['mensagem'],'{Campo Inexistente}') && !str_contains($pv['mensagem'],'{Primeiro Nome}')
    && $pv['situacao']==='finalizado','pré-visualização substitui os campos (desconhecidos ficam intactos)');
$late=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->where('Data de Cobrança','<',$tod)->distinct()->pluck('Nr do Contracto');
$openC=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->distinct()->pluck('Nr do Contracto');
$qa=data($mc->destinatarios($jreq('GET',['grupo'=>'atrasados','modelo_id'=>$new['id']])));
$qp=data($mc->destinatarios($jreq('GET',['grupo'=>'pendentes','modelo_id'=>$new['id']])));
ok($qa['total']===$late->count() && collect($qa['destinatarios'])->pluck('contrato')->sort()->values()->all()===$late->sort()->values()->all()
    && $qp['total']===$openC->count()-$late->count(),'fila: atrasados e pendentes por contrato');
$qt=data($mc->destinatarios($jreq('GET',['grupo'=>'todos','texto'=>'Olá {Nome Completo}'])));
$qi=data($mc->destinatarios($jreq('GET',['grupo'=>'inactivos','texto'=>'x {Nr do Contracto}'])));
$qv=data($mc->destinatarios($jreq('GET',['grupo'=>'activos','texto'=>'x {Nr do Contracto}'])));
$clientsN=$db->table('Contractos')->distinct()->count('Nº de Cliente');
ok($qt['total']===$clientsN && $qv['total']+$qi['total']===$clientsN && collect($qi['destinatarios'])->every(fn($d)=>$d['situacao']==='finalizado'),'fila: todos/activos/inactivos um por cliente');
ok(\App\Http\Controllers\Api\MessageCenterController::phone('84 123 4567')==='258841234567' && \App\Http\Controllers\Api\MessageCenterController::phone('+258 86 555 1212')==='258865551212'
    && \App\Http\Controllers\Api\MessageCenterController::phone('')===null,'número normalizado para WhatsApp (258)');
data($mc->registo($jreq('POST',['contrato'=>'C-0110','telefone'=>'841234567','canal'=>'whatsapp','modelo_id'=>$new['id'],'mensagem'=>$pv['mensagem']])));
$hi=data($mc->historico($jreq('GET',['contrato'=>'C-0110'])));
ok(count($hi['mensagens'])===1 && $hi['mensagens'][0]['phone']==='258841234567' && $hi['mensagens'][0]['channel']==='whatsapp' && $hi['mensagens'][0]['status']==='aberta','mensagem aberta fica registada');
data($mc->registo($jreq('POST',['contrato'=>'C-0110','telefone'=>'258841234567','canal'=>'whatsapp_web','estado'=>'enviada','mensagem'=>'x'])));
data($mc->registo($jreq('POST',['contrato'=>'C-0110','telefone'=>'258800000000','canal'=>'whatsapp_web','estado'=>'falhada','detalhe'=>'Número inválido ou sem WhatsApp.','mensagem'=>'x'])));
$hw=data($mc->historico($jreq('GET',['contrato'=>'C-0110'])))['mensagens'];
ok(count($hw)===3 && $hw[0]['status']==='falhada' && $hw[0]['detail']==='Número inválido ou sem WhatsApp.' && $hw[1]['status']==='enviada' && $hw[1]['channel']==='whatsapp_web','envio pelo WhatsApp Web regista enviada/falhada com motivo');
ok($err(fn()=>$mc->registo($jreq('POST',['canal'=>'whatsapp_web','estado'=>'talvez','mensagem'=>'x'])))===422,'estado de envio inválido recusado');
$rg=data($mc->registos($jreq('GET',['contrato'=>'C-0110'])));
ok($rg['total_linhas']===3 && $rg['totais']===['enviadas'=>1,'falhadas'=>1,'abertas'=>1] && $rg['linhas'][0]['estado']==='falhada'
    && $rg['linhas'][0]['motivo']==='Número inválido ou sem WhatsApp.' && $rg['linhas'][0]['cliente']===$db->table('Contractos')->where('Nr do Contracto','C-0110')->value('Nome do Cliente')
    && $rg['estado_disponivel']===true,'registo de mensagens: lista, totais e nome do cliente');
$rf=data($mc->registos($jreq('GET',['estado'=>'enviada','canal'=>'whatsapp_web','inicio'=>now($tz)->toDateString()])));
ok($rf['total_linhas']===1 && $rf['linhas'][0]['canal']==='whatsapp_web','registo de mensagens: filtros estado/canal/data');
ok($err(fn()=>$mc->registos($jreq('GET',[],$gestor)))===403,'registo de mensagens reservado ao administrador');
ok($err(fn()=>$mc->modelos($jreq('GET',[],$gestor)))===403 && $err(fn()=>$mc->destinatarios($jreq('GET',['grupo'=>'todos','texto'=>'x'],$gestor)))===403,'centro de mensagens reservado ao administrador');
data($mc->eliminarModelo($jreq('DELETE',[]),$new['id']));
ok(count(data($mc->modelos($jreq('GET',[])))['modelos'])===4,'modelo eliminado');
$seed=require __DIR__.'/../database/migrations/2026_11_19_000090_seed_capta_message_templates.php';
$db->table('cis_message_templates')->insert(['name'=>'Bom dia','body'=>'EDITADO {Nome Completo}','created_at'=>now(),'updated_at'=>now()]);
$seed->up(); $seed->up();
$names=$db->table('cis_message_templates')->pluck('name')->all();
ok(count($names)===9 && $db->table('cis_message_templates')->where('name','Bom dia')->value('body')==='EDITADO {Nome Completo}'
    && count(array_intersect(['Cobrança Antecipada','Mensagem no próprio dia','Mensagem Vencida','Actualização de Dados'],$names))===4,'modelos CAPTA: idempotente e sem substituir edições');
$vid=$db->table('cis_message_templates')->where('name','Mensagem Vencida')->value('id');
$emo=$db->table('cis_message_templates')->whereIn('name',['Actualização de Dados','Cobrança Antecipada','Mensagem Vencida'])->pluck('body')->implode(' ');
ok(str_contains($emo,'👋') && str_contains($emo,'📄') && str_contains($emo,'💰') && str_contains($emo,'⚠️') && mb_check_encoding($emo,'UTF-8'),'emojis dos modelos guardados intactos (utf8mb4)');
$pid=$db->table('cis_message_templates')->where('name','Mensagem no próprio dia')->value('id');
$late1=$late->first();
if ($late1) {
    $m1=data($mc->preview($jreq('GET',['contrato'=>$late1,'modelo_id'=>$vid])))['mensagem'];
    $m2=data($mc->preview($jreq('GET',['contrato'=>$late1,'modelo_id'=>$pid])))['mensagem'];
    ok(!preg_match('/\{[^{}]+\}/u',$m1) && !preg_match('/\{[^{}]+\}/u',$m2) && str_contains($m1,'*em atraso*') && str_contains($m1,'MT'),'modelos CAPTA sem campos por preencher (inclui {Nome do Cliente})');
}


echo "== Configurações da empresa\n";
$db->statement('DROP TABLE IF EXISTS cis_company_settings');
\App\Support\CompanySettings::forget();
ok(\App\Support\CompanySettings::load(true)['instalado']===false && \App\Support\CompanySettings::get('score_minimo')==='60','sem tabela: valores por defeito, sem erro');
(require __DIR__.'/../database/migrations/2026_11_19_000110_create_cis_company_settings.php')->up();
$csc=new \App\Http\Controllers\Api\CompanySettingsController();
$sreq=fn(string $m,array $q,$u=null)=>tap(Request::create('/x',$m,$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
$cs0=data($csc->show($sreq('GET',[])));
ok($cs0['instalado']===true && $cs0['valores']['fim_de_semana']==='permitir' && count($cs0['provincias'])===11,'configurações: leitura do administrador');
ok($err(fn()=>$csc->update($sreq('PUT',['valores'=>['nome_empresa'=>'X']],$gestor)))===403,'configurações: só o administrador grava');
ok($err(fn()=>$csc->update($sreq('PUT',['valores'=>['nuit'=>'12345678']])))===422
    && $err(fn()=>$csc->update($sreq('PUT',['valores'=>['score_risco_medio'=>'90','score_baixo_risco'=>'80']])))===422
    && $err(fn()=>$csc->update($sreq('PUT',['valores'=>['logotipo'=>'data:image/png;base64,AAAA']])))===422
    && $err(fn()=>$csc->update($sreq('PUT',['valores'=>['campo_x'=>'1']])))===422,'configurações: NUIT, limiares, logótipo e campos validados');
$png='data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
$cs1=data($csc->update($sreq('PUT',['valores'=>['nome_empresa'=>'CAPTA Microcrédito','nuit'=>'400123456','provincia'=>'Maputo Cidade','logotipo'=>$png,
    'penalidades_automaticas'=>true,'taxa_diaria_mora'=>'2,5','capital_inicial'=>'1 500 000,5','incentivo_cliente_novo'=>'100','incentivo_taxa_juros'=>'10']])));
ok($cs1['valores']['nuit']==='400123456' && $cs1['valores']['penalidades_automaticas']==='1' && $cs1['valores']['taxa_diaria_mora']==='2.5'
    && $cs1['valores']['capital_inicial']==='1500000.50' && $cs1['actualizado_por']!==null,'configurações: gravadas e normalizadas');
$pg=data($csc->show($sreq('GET',[],$gestor)));
ok(!isset($pg['valores']) && $pg['perfil']['nome_empresa']==='CAPTA Microcrédito' && str_starts_with($pg['perfil']['logotipo'],'data:image/png'),'configurações: outros utilizadores só vêem o perfil (documentos)');

echo "== Relatórios regulatórios\n";
$rc=new \App\Http\Controllers\Api\ReportController();
$rq=fn(array $q,$u=null)=>tap(Request::create('/x','GET',$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
ok(count(data($rc->index($rq([])))['relatorios'])===14,'catálogo com 14 relatórios (4 regulatórios + 7 de gestão + 3 de desempenho)');
$mStart=now($tz)->startOfMonth()->toDateString();
$ra=data($rc->show($rq(['inicio'=>$mStart,'fim'=>$tod]),'actividade'));
$ovm=data($ov(Request::create('/x','GET',['mes'=>(string)now($tz)->month,'ano'=>(string)now($tz)->year])));
$sec=collect($ra['seccoes'])->keyBy('titulo');
$disbN=$sec['1. Desembolsos']['linhas'][0][1];$disbV=$sec['1. Desembolsos']['linhas'][1][1];
ok((int)$disbN===$ovm['carteira']['contratos_desembolsados_periodo'] && $disbV===$ovm['carteira']['desembolsado_periodo'],'actividade: desembolsos = Visão geral');
$openCap=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->where('Data de Inicio','<=',$tod)->get()
    ->sum(fn($r)=>max(0,(float)$r->{'Valor a Pagar'}-(float)$r->{'Valor de Juros'}));
$cartRow=collect($ra['seccoes'][1]['linhas'])->first(fn($l)=>str_starts_with($l[0],'Carteira em curso'));
ok(abs((float)$cartRow[1]-$openCap)<0.01 && strlen($ra['codigo_verificacao'])===16,'actividade: carteira na data = prestações em aberto');
$rec=collect($sec['4. Recebimentos no período']['linhas'])->keyBy(0);
ok(abs((float)$rec['Total recebido'][1]-(float)$ovm['recebimentos']['recebido_mes'])<0.01,'actividade: recebido no período = Visão geral');
$rcrc=data($rc->show($rq(['data'=>$tod]),'crc'));
$l110=collect($rcrc['seccoes'][0]['linhas'])->first(fn($l)=>$l[0]==='C-0110');
ok($l110 && $l110[13]==='Liquidado' && $l110[10]==='0.00' && str_contains($l110[15],'BI'),'CRC: contrato renovado e liquidado, identificação em falta assinalada');
$openN=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->where('Data de Inicio','<=',$tod)->distinct()->count('Nr do Contracto');
ok(collect($rcrc['seccoes'][0]['linhas'])->filter(fn($l)=>$l[13]!=='Liquidado')->count()===$openN,'CRC: uma linha por contrato com saldo');
$rb=data($rc->show($rq(['ano'=>now($tz)->year]),'branqueamento'));
ok((int)$rb['resumo'][0][1]>0 && count($rb['seccoes'])===2,'branqueamento: clientes com movimento no ano');
$ri=data($rc->show($rq([]),'identificacao'));
ok((int)$ri['resumo'][1][1]===(int)$ri['resumo'][0][1],'identificação: sem BI/NUIT no ensaio, todos assinalados');
// Gestão
$in60=now($tz)->addDays(60)->toDateString();
$ag=data($rc->show($rq(['inicio'=>$tod,'fim'=>$in60]),'agenda'));
$expAg=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->whereBetween('Data de Cobrança',[$tod,$in60])->count();
ok(count($ag['seccoes'][1]['linhas'])===$expAg && (int)$ag['resumo'][0][1]===$expAg,'agenda: prestações a vencer nos próximos 60 dias');
$at=data($rc->show($rq(['data'=>$tod]),'atrasos'));
$expAt=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->where('Data de Cobrança','<',$tod)->where('Data de Inicio','<=',$tod)->distinct()->count('Nr do Contracto');
$sumBands=array_sum(array_map(fn($l)=>(int)$l[2],$at['seccoes'][0]['linhas']));
ok(count($at['seccoes'][1]['linhas'])===$expAt && $sumBands===$expAt,'atrasos: contratos em atraso e faixas coerentes');
$op=data($rc->show($rq(['inicio'=>$mStart,'fim'=>$tod]),'operadores'));
ok(abs(array_sum(array_map(fn($l)=>(float)$l[2],$op['seccoes'][0]['linhas']))-(float)$disbV)<0.01,'operadores: soma dos desembolsos = relatório de actividade');
$expInc=0.0;foreach ($op['seccoes'][0]['linhas'] as $l) $expInc+=(int)$l[9]*100+(float)$l[4]*0.10;
ok(abs((float)str_replace(' MT','',$op['resumo'][4][1])-round($expInc,2))<0.05 && array_sum(array_map(fn($l)=>(int)$l[9],$op['seccoes'][0]['linhas']))<=(int)$disbN,'operadores: incentivo = clientes novos × 100 + 10% dos juros (configurações)');
$ev=data($rc->show($rq(['ano'=>now($tz)->year]),'evolucao'));
$yStart=now($tz)->startOfYear()->toDateString();
$expY=0.0;foreach ($db->table('Contractos')->get()->groupBy('Nr do Contracto') as $c) { $d=substr((string)$c->min('Data de Inicio'),0,10); if ($d>=$yStart && $d<=$tod) $expY+=(float)$c->max('Quantia Creditada'); }
$totRow=collect($ev['seccoes'][0]['linhas'])->last();
ok($totRow[0]==='Total do ano' && abs((float)$totRow[2]-$expY)<0.01,'evolução: desembolsado no ano confere');
$cx=data($rc->show($rq(['inicio'=>$mStart,'fim'=>$tod]),'caixa'));
$expE=(float)$db->table('Extracto')->whereBetween('Data da Operação',[$mStart,$tod])->where('Tipo de Movimento','Entradas')->sum('Valor');
ok(abs(array_sum(array_map(fn($l)=>(float)$l[2],$cx['seccoes'][0]['linhas']))-$expE)<0.01
    && abs(array_sum(array_map(fn($l)=>(float)$l[2],$cx['seccoes'][1]['linhas']))-$expE)<0.01,'caixa por conta e por tipo: entradas conferem com o Extracto');
$nc=data($rc->show($rq([]),'novocredito'));
$openClients=$db->table('Contractos')->whereIn('Estado',['Pendente','Pagamento Atrasado'])->pluck('Nome do Cliente')->unique()->all();
ok(collect($nc['seccoes'][0]['linhas'])->every(fn($l)=>!in_array($l[0],$openClients,true)),'novo crédito: só clientes sem contratos em aberto');
ok(collect($nc['seccoes'][0]['linhas'])->every(fn($l)=>($l[8]==='Baixo risco')===((int)$l[7]>=80) && ($l[9]==='Sim')===((int)$l[7]>=60)),'novo crédito: score e classificação pelos limiares configurados');
data($csc->update($sreq('PUT',['valores'=>['max_creditos_activos'=>'3','max_creditos_atraso'=>'5']])));\App\Support\CompanySettings::forget();
$nc3=data($rc->show($rq([]),'novocredito'));
ok(count($nc3['seccoes'][0]['linhas'])>=count($nc['seccoes'][0]['linhas']) && collect($nc3['seccoes'][0]['linhas'])->every(fn($l)=>(int)$l[10]<3),'novo crédito: máximo de créditos activos configurável');
data($csc->update($sreq('PUT',['valores'=>['max_creditos_activos'=>'1','max_creditos_atraso'=>'0']])));\App\Support\CompanySettings::forget();
ok($err(fn()=>$rc->show($rq([],$gestor),'actividade'))===403 && $err(fn()=>$rc->show($rq(['inicio'=>$tod,'fim'=>$mStart==$tod?'2000-01-01':$mStart]),'actividade'))===422,'relatórios: só administrador; período inválido recusado');

echo "== Prévia da prorrogação de 15 para 30 dias\n";
$extension=$row([
    'Nr do Contracto'=>'C-0270', 'Nº'=>270, 'Prazo/Duração'=>'15 Dias',
    'Data de Inicio'=>'2026-01-20', 'Data de Cobrança'=>'2026-02-04',
    'Capital'=>500000, 'Valor de Juros'=>50000, 'Valor a Pagar'=>550000, 'Valor Pago'=>0,
]);
$db->table('cis_tabela_juros')->insert([
    'limite_inferior'=>500000,'limite_superior'=>500000,'taxa'=>20,'periodo_referencia'=>30,
]);
$before=(array)$db->table('Contractos')->where('Ordem',$extension)->first();
$movementsBefore=$db->table('Extracto')->count();
$scenario=new \App\Http\Controllers\Api\CollectionScenarioPreviewController();
$extensionRequest=['operacao'=>'prorrogar_15_30','prazo_origem_dias'=>15,'prazo_destino_dias'=>30];
$preview=fn(array $options=[])=>data($scenario->preview(req('/x',$options+$extensionRequest,$user),'C-0270',$extension));
$both=$preview();
ok($both['novo_vencimento']==='2026-02-19' && $both['tratamento_juros']===null
    && count($both['opcoes_juros'])===2,'vencimento original + 30 dias e duas opções sem selecção automática');
$cap0=data($scenario->preview(req('/x',['operacao'=>'capitalizar','periodo_referencia'=>30],$user),'C-0270',$extension));
$cap1=data($scenario->preview(req('/x',['operacao'=>'capitalizar','periodo_referencia'=>30,'taxa_informada'=>'12,5'],$user),'C-0270',$extension));
ok(in_array($cap0['resultado'],['indicativo_sem_capitalizacao','sem_taxa'],true) && ($cap0['taxa_alterada']??null)===false
    && ($cap0['resultado']==='sem_taxa'||$cap0['taxa']===$cap0['taxa_tabela']),'capitalização: juros a cobrar com a taxa da tabela por defeito');
ok($cap1['taxa']==='12.5' && $cap1['taxa_alterada']===($cap0['taxa_tabela']!=='12.5') && $cap1['juros_novos']===number_format(round((float)$cap1['capital_novo']*100*12.5/100)/100,2,'.','')
    && $err(fn()=>$scenario->preview(req('/x',['operacao'=>'capitalizar','periodo_referencia'=>30,'taxa_informada'=>'150'],$user),'C-0270',$extension))===422,'capitalização: outra taxa indicada pelo operador (e validada)');
ok($preview(['tratamento_juros'=>'manter'])['resultado_escolhido']
    ===['juros'=>'50000.00','valor_a_pagar'=>'550000.00'],'manter preserva os valores actuais');
ok($preview(['tratamento_juros'=>'recalcular'])['resultado_escolhido']
    ===['juros'=>'100000.00','valor_a_pagar'=>'600000.00'],'recalcular aplica a taxa de 30 dias ao capital');
ok(status(fn()=>$preview(['tratamento_juros'=>'invalido']))===422,'tratamento desconhecido recusado');
$db->table('cis_tabela_juros')->where('limite_inferior',500000)->where('limite_superior',500000)->delete();
ok($preview(['tratamento_juros'=>'manter'])['resultado_escolhido']['juros']==='50000.00',
    'manter disponível sem taxa de 30 dias');
ok(status(fn()=>$preview(['tratamento_juros'=>'recalcular']))===409,'recalcular sem taxa válida recusado');
ok((array)$db->table('Contractos')->where('Ordem',$extension)->first()===$before
    && $db->table('Extracto')->count()===$movementsBefore,'a prévia não altera a prestação nem cria movimentos');


echo "== Prévia de mora diária\n";
$fineOrder=$row([
    'Nr do Contracto'=>'C-0370', 'Nº'=>370, 'Data de Cobrança'=>'2026-01-20',
    'Capital'=>12000, 'Amortização'=>5000, 'Valor de Juros'=>1000,
    'Valor a Pagar'=>6000, 'Valor Pago'=>0,
]);
$fineScenario=new \App\Http\Controllers\Api\CollectionScenarioPreviewController();
$fineInput=['operacao'=>'mora_diaria','data_referencia'=>'2026-01-24','taxa_mora_diaria'=>'0.10',
    'carencia_dias'=>1,'tecto_mora'=>'50.00'];
$finePreview=fn(array $changes=[])=>data($fineScenario->preview(req('/x',$changes+$fineInput,$user),'C-0370',$fineOrder));
$fineMovements=$db->table('Extracto')->count();
$fineSnapshot=(array)$db->table('Contractos')->where('Ordem',$fineOrder)->first();
$fine=$finePreview();
ok($fine['mora']==='15.00' && $fine['capital_vencido']==='5000.00'
    && $fine['dias_sujeitos_a_mora']===3,'mora apenas sobre o capital vencido, após carência');
$fine=$finePreview(['tecto_mora'=>'9.00']);
ok($fine['mora']==='9.00' && $fine['limitada_pelo_tecto'],'mora limitada pelo tecto');
ok($finePreview(['data_referencia'=>'2026-01-20'])['mora']==='0.00','vencimento sem mora');
ok($finePreview(['data_referencia'=>'2026-01-21'])['mora']==='0.00','primeiro dia de atraso ainda em carência');
ok((array)$db->table('Contractos')->where('Ordem',$fineOrder)->first()===$fineSnapshot
    && $db->table('Extracto')->count()===$fineMovements,'prévia sem alteração ao contrato ou ao Extracto');


echo "== Parametrização (tabela de preços e tipos de movimento)\n";
$db->statement('DROP TABLE IF EXISTS cis_parametrizacao_log');$db->statement('DROP TABLE IF EXISTS cis_tipos_movimento');
$db->statement("CREATE TABLE cis_tipos_movimento(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tipo_movimento ENUM('Entradas','Saídas') NOT NULL,descricao VARCHAR(150) NOT NULL,UNIQUE KEY u(tipo_movimento,descricao)) ENGINE=InnoDB");
$db->table('cis_tipos_movimento')->insert([['tipo_movimento'=>'Entradas','descricao'=>'Receita de Juros'],['tipo_movimento'=>'Saídas','descricao'=>'Salários'],['tipo_movimento'=>'Saídas','descricao'=>'Desembolso de Empréstimo']]);
$pc=new \App\Http\Controllers\Api\ParametrizacaoController();
$preq=fn(string $m,array $q,$u=null)=>tap(Request::create('/x',$m,$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
ok(data($pc->show($preq('GET',[])))['editavel']===false && $err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>0,'ate'=>10,'taxa_30'=>'10']]])))===503,'parametrização: sem migração fica só de consulta');
(require __DIR__.'/../database/migrations/2026_11_19_000120_parametrizacao_editavel.php')->up();
(require __DIR__.'/../database/migrations/2026_11_19_000120_parametrizacao_editavel.php')->up();
$p0=data($pc->show($preq('GET',[])));
$mv=collect($p0['movimentos'])->keyBy('descricao');
ok($p0['editavel'] && $mv['Salários']['grupo']==='Despesa operacional' && $mv['Desembolso de Empréstimo']['protegido'] && !$mv['Salários']['protegido'],'parametrização: grupos e tipos base protegidos (migração idempotente)');
$priceBefore=$db->table('cis_tabela_juros')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
ok($err(fn()=>$pc->show($preq('GET',[],$gestor)))===403 && $err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>0,'ate'=>10,'taxa_30'=>'10']]],$gestor)))===403,'parametrização: só o administrador');
ok($err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>0,'ate'=>5000,'taxa_15'=>'20'],['de'=>5000,'ate'=>9000,'taxa_30'=>'25']]])))===422
    && $err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>100,'ate'=>50,'taxa_15'=>'20']]])))===422
    && $err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>0,'ate'=>50,'taxa_15'=>'','taxa_30'=>'']]])))===422
    && $err(fn()=>$pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>0,'ate'=>50,'taxa_15'=>'120']]])))===422,'preços: sobreposição, faixa invertida, sem taxa e taxa > 100 recusadas');
$p1=data($pc->guardarPrecos($preq('PUT',['faixas'=>[['de'=>3001,'ate'=>5000,'taxa_15'=>'20','taxa_30'=>'25,5'],['de'=>0,'ate'=>3000,'taxa_15'=>'20','taxa_30'=>'25'],['de'=>6001,'ate'=>9000,'taxa_30'=>'30']]])));
ok(count($p1['precos'])===3 && $p1['precos'][1]['taxa_30']==='25.5' && $p1['precos'][2]['taxa_15']===null && $p1['lacunas']===[[5001,6000]]
    && $db->table('cis_tabela_juros')->count()===5 && $db->table('cis_parametrizacao_log')->where('tabela','tabela_juros')->count()===1,'preços: gravados por faixa e período, lacuna assinalada, alteração registada');
$sim=new \App\Http\Controllers\Api\FinancialReferenceController();
$q=json_decode($sim->quote($preq('GET',['montante'=>'4000','periodo_dias'=>'30']))->getContent(),true);
ok($q['found'] && (float)$q['taxa']===25.5,'preços: novas consultas usam a taxa alterada');
ok($err(fn()=>$pc->criarMovimento($preq('POST',['tipo_movimento'=>'Saídas','descricao'=>'Salários','grupo'=>'Despesa operacional'])))===422
    && $err(fn()=>$pc->criarMovimento($preq('POST',['tipo_movimento'=>'Saídas','descricao'=>'Renda [escritório]','grupo'=>'Despesa operacional'])))===422
    && $err(fn()=>$pc->criarMovimento($preq('POST',['tipo_movimento'=>'Entradas','descricao'=>'Renda','grupo'=>'Investimento'])))===422,'tipos: duplicado, nome inválido e grupo errado recusados');
$p2=data($pc->criarMovimento($preq('POST',['tipo_movimento'=>'Saídas','descricao'=>'Renda do escritório','grupo'=>'Despesa operacional'])));
$renda=collect($p2['movimentos'])->firstWhere('descricao','Renda do escritório');
$base=collect($p2['movimentos'])->firstWhere('descricao','Desembolso de Empréstimo');
ok($renda && $err(fn()=>$pc->actualizarMovimento($preq('PUT',['activo'=>false]),$base['id']))===422
    && $err(fn()=>$pc->actualizarMovimento($preq('PUT',['descricao'=>'Outro nome']),$base['id']))===422,'tipos: criado; tipo base não pode ser desactivado nem renomeado');
data($pc->actualizarMovimento($preq('PUT',['activo'=>false,'descricao'=>'Renda escritório']),$renda['id']));
$act=json_decode($pc->tiposActivos()->getContent(),true)['movimentos'];
ok(!collect($act)->contains('descricao','Renda escritório') && collect($act)->contains('descricao','Salários')
    && $db->table('cis_parametrizacao_log')->where('tabela','tipos_movimento')->count()===2,'tipos: desactivado sai da Caixa; alterações registadas');
$db->table('cis_tabela_juros')->delete();foreach ($priceBefore as $r) $db->table('cis_tabela_juros')->insert($r);


echo "== Numeração anual dos contratos (PREFIXO-ANO-SEQUÊNCIA)\n";
$db->statement('DROP TABLE IF EXISTS cis_contract_numbers');
(require __DIR__.'/../database/migrations/2026_11_19_000140_create_cis_contract_numbers.php')->up();
\App\Support\CompanySettings::forget();
ok(!\App\Services\ContractNumbering::active() && \App\Services\ContractNumbering::prefix()==='CAPTA' && \App\Services\ContractNumbering::format(2026,7)==='CAPTA-2026-0007','numeração: por defeito CAPTA-ANO-0000, ainda inactiva');
$nBefore=$db->table('Contractos')->distinct()->count('Nr do Contracto');$rowsBefore=$db->table('Contractos')->count();$extBefore=$db->table('Extracto')->count();
$plan=\App\Services\ContractNumbering::plan($db);
$byYear=collect($plan['linhas'])->groupBy('ano');
ok(count($plan['linhas'])+count($plan['sem_data'])===$nBefore && $byYear->every(fn($g)=>$g->pluck('sequencia')->values()->all()===range(1,$g->count()))
    && collect($plan['linhas'])->every(fn($l)=>str_starts_with($l['novo'],'CAPTA-'.$l['ano'].'-') && substr($l['data'],0,4)==(string)$l['ano']),'plano: sequência recomeça em 1 em cada ano (ano do desembolso)');
$sorted=collect($plan['linhas'])->sortBy(fn($l)=>$l['data'].sprintf('%09d',$l['ordem']))->pluck('novo')->values()->all();
ok($sorted===collect($plan['linhas'])->pluck('novo')->all(),'plano: ordenado pela data de desembolso e ordem de registo');
// Alvo: um contrato com histórico e com o número na descrição do Extracto (ex.: desembolso pelo CIS).
$inPlan=collect($plan['linhas'])->pluck('atual')->all();
$n=collect($db->table('cis_contractos_historico')->distinct()->pluck('contract_number'))
    ->first(fn($c)=>in_array($c,$inPlan,true) && $db->table('Extracto')->where('Descrição','like','%Desembolso de Empréstimo '.$c.' |%')->exists());
ok($n!==null,'ensaio: contrato com histórico e desembolso no Extracto ('.($n??'?').')');
$histN=$db->table('cis_contractos_historico')->where('contract_number',$n)->count();
$extN=$db->table('Extracto')->where('Contracto',$n)->orWhere('Descrição','like','%'.$n.' |%')->count();
$db->table('cis_message_log')->insert(['contract_number'=>$n,'phone'=>'258840000000','channel'=>'whatsapp','body'=>'x','sent_by'=>1,'created_at'=>now()]);
$newN=collect($plan['linhas'])->firstWhere('atual',$n)['novo'];
$st=\App\Services\ContractNumbering::apply($db,$plan,'ensaio');
ok($db->table('Contractos')->count()===$rowsBefore && $db->table('Extracto')->count()===$extBefore
    && $db->table('Contractos')->where('Nr do Contracto','like','C-%')->count()===$db->table('Contractos')->whereIn('Nr do Contracto',$plan['sem_data'])->count(),'renumeração: mesmas linhas; só ficam C-… os contratos sem data');
ok($db->table('Contractos')->where('Nr do Contracto',$newN)->exists() && !$db->table('Contractos')->where('Nr do Contracto',$n)->exists()
    && $db->table('Extracto')->where('Descrição','like','%Desembolso de Empréstimo '.$newN.' |%')->exists() && $extN>0
    && $db->table('cis_message_log')->where('contract_number',$newN)->exists(),'renumeração: Contractos, Extracto (coluna e descrição) e mensagens actualizados');
ok($histN>0 && in_array($n,\App\Services\ContractNumbering::aliases($newN),true)
    && $db->table('cis_contractos_historico')->whereIn('contract_number',\App\Services\ContractNumbering::aliases($newN))->count()===$histN
    && \App\Services\ContractNumbering::resolve($n)===$newN,'histórico imutável lido pelo número antigo; número antigo leva ao novo');
$prof=data((new \App\Http\Controllers\Api\ContractProfileController())->show(Request::create('/x','GET'),$n));
ok(count($prof['installments']??[])>0,'ficha do contrato abre pelo número antigo');
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'numeracao_anual_activa'],['valor'=>'1','updated_at'=>now(),'created_at'=>now()]);\App\Support\CompanySettings::forget();
$y=(int)substr($today,0,4);$maxY=(int)$db->table('cis_contract_numbers')->where('ano',$y)->max('sequencia');
$ob=['cliente_id'=>7,'montante'=>101,'tipo_juro'=>'Fixo','estrategia_calculo'=>ContractCalculationStrategy::FIXED_AMORTIZATION,
    'periodo_dias'=>30,'prazo'=>'1 Mês','taxa_informada'=>15,'taxa_multa'=>10,'prazo_meses'=>1,'modalidade'=>'Uma Parcela',
    'forma_pagamento'=>'Numerário','disponibilidade'=>'Numerário','data_inicio'=>$today,'confirmacao_desembolso'=>true,'referencia'=>'SAIDA-RN1'];
$on=data((new ContractOpeningController())->store(req('/x',$ob+['conta_id'=>$banco,'idempotency_key'=>'99999999-9999-4999-8999-999999999999'],$user)));
ok(($on['numero']??'')===\App\Services\ContractNumbering::format($y,$maxY+1),'novo contrato recebe o próximo número do ano ('.($on['numero']??'?').')');
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'contrato_prefixo'],['valor'=>'CPT','updated_at'=>now(),'created_at'=>now()]);
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'contrato_digitos'],['valor'=>'5','updated_at'=>now(),'created_at'=>now()]);\App\Support\CompanySettings::forget();
$plan2=\App\Services\ContractNumbering::plan($db);\App\Services\ContractNumbering::apply($db,$plan2,'ensaio');
$newN2=collect($plan2['linhas'])->firstWhere('atual',$newN)['novo'];
ok(str_starts_with($newN2,'CPT-') && strlen(explode('-',$newN2)[2])===5 && $db->table('Contractos')->where('Nr do Contracto','like','CAPTA-%')->count()===0
    && \App\Services\ContractNumbering::resolve($n)===$newN2 && in_array($n,\App\Services\ContractNumbering::aliases($newN2),true),'mudar o prefixo e renumerar de novo: todos os números antigos levam ao actual');
$cs=new \App\Http\Controllers\Api\CompanySettingsController();
ok($err(fn()=>$cs->update(tap(Request::create('/x','PUT',['valores'=>['contrato_prefixo'=>'CA PTA']]),fn($r)=>$r->setUserResolver(static fn()=>$user))))===422
    && $err(fn()=>$cs->update(tap(Request::create('/x','PUT',['valores'=>['numeracao_anual_activa'=>'0']]),fn($r)=>$r->setUserResolver(static fn()=>$user))))===422,'prefixo validado; activação gerida só pelo sistema');
$pv=data((new \App\Http\Controllers\Api\ContractNumberingController())->show(tap(Request::create('/x','GET',['prefixo'=>'emp','digitos'=>'6']),fn($r)=>$r->setUserResolver(static fn()=>$user))));
ok($pv['activa'] && $pv['exemplo']==='EMP-'.$y.'-000001' && array_sum(array_column($pv['resumo'],'contratos'))===$pv['total'],'pré-visualização nas Definições com outro prefixo');


echo "== Avisos de cobrança e atrasos\n";
\App\Support\CompanySettings::forget();
$noticeList=new \App\Http\Controllers\Api\ContractListController();
$noticeRequest=fn(string $kind)=>data($noticeList->notices(Request::create('/x','GET',['tipo'=>$kind])));
$noticeBefore=$noticeRequest('cobranca');
$noticeToday=\Carbon\CarbonImmutable::now('Africa/Maputo')->startOfDay();
$row(['Nr do Contracto'=>'AV-HOJE','Nº'=>11001,'Data de Cobrança'=>$noticeToday->toDateString(),'Valor Pago'=>0]);
$row(['Nr do Contracto'=>'AV-ATRASO','Nº'=>11002,'Data de Cobrança'=>$noticeToday->subDay()->toDateString(),'Valor Pago'=>0]);
$row(['Nr do Contracto'=>'AV-FORA','Nº'=>11003,'Data de Cobrança'=>$noticeToday->addDays($noticeBefore['antecedencia']+1)->toDateString()]);
$row(['Nr do Contracto'=>'AV-PAGO','Nº'=>11004,'Estado'=>'Pago','Data de Cobrança'=>$noticeToday->toDateString()]);
$noticeAfter=$noticeRequest('cobranca');
ok($noticeAfter['cobranca']===$noticeBefore['cobranca']+1 && $noticeAfter['atraso']===$noticeBefore['atraso']+1,
    'inclui vencimento de hoje e atraso por data; exclui pago e prazo fora da antecedência');
ok(in_array('AV-HOJE',array_column(array_map(static fn($line)=>(array)$line,$noticeAfter['linhas']),'contrato'),true),
    'aviso de cobrança indica o contrato');


echo "== Recibos de pagamento\n";
$rcc=new \App\Http\Controllers\Api\ContractReceiptController();
$paidC=$db->table('Contractos')->whereIn('Estado',['Pago','Pago em Atraso','Renovado'])->where('Valor Pago','>',0)->distinct()->pluck('Nr do Contracto');
$checked=0;
foreach ($paidC as $c) {
    $rr=data($rcc->index(tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(static fn()=>$user)),$c));
    $parcelas=collect($rr['recibos'])->pluck('prestacao')->map(fn($x)=>(string)$x)->unique();
    $paidP=$db->table('Contractos')->where('Nr do Contracto',$c)->whereIn('Estado',['Pago','Pago em Atraso','Renovado'])->where('Valor Pago','>',0)->pluck('Nº da Parcela')->map(fn($x)=>(string)$x)->unique();
    $extE=$db->table('Extracto')->where('Contracto',$c)->where('Tipo de Movimento','Entradas')->where('Valor','>',0)->pluck('Nr');
    if (!$paidP->diff($parcelas)->isEmpty() || !$extE->every(fn($nr)=>collect($rr['recibos'])->contains('numero','REC-'.$nr))) { ok(false,"recibos do contrato $c"); break; }
    $checked++;
}
ok($checked===$paidC->count() && $checked>0,"recibos: cada prestação paga e cada entrada no Extracto tem recibo ($checked contratos)");
$legacy=$db->table('Contractos')->whereIn('Estado',['Pago','Pago em Atraso'])->where('Valor Pago','>',0)->get()
    ->first(fn($r)=>!$db->table('Extracto')->where('Contracto',$r->{'Nr do Contracto'})->where('Parcela',(string)$r->{'Nº da Parcela'})->where('Tipo de Movimento','Entradas')->exists());
if ($legacy) { $lr=data($rcc->index(tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(static fn()=>$user)),$legacy->{'Nr do Contracto'}));
    ok(collect($lr['recibos'])->contains(fn($r)=>$r['numero']==='REC-P'.$legacy->Ordem && $r['origem']==='prestacao'),'recibo de pagamento antigo (sem movimento no Extracto): REC-P'.$legacy->Ordem); }
ok($err(fn()=>$rcc->index(tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(static fn()=>$gestor)),(string)$paidC->first()))===403,'recibos: só o administrador');


echo "== Numeração anual dos recibos (REC-ANO-SEQUÊNCIA)\n";
$db->statement('DROP TABLE IF EXISTS cis_receipts');
(require __DIR__.'/../database/migrations/2026_11_19_000150_create_cis_receipts.php')->up();
$db->table('cis_company_settings')->where('chave','recibos_numerados')->delete();\App\Support\CompanySettings::forget();
$areq=fn()=>tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(static fn()=>$user));
$c1=(string)$paidC->map(fn($c)=>\App\Services\ContractNumbering::resolve($c))->first();
$r1=data($rcc->index($areq(),$c1));
$cand=collect(\App\Services\ReceiptNumbering::candidates($db))->filter(fn($i)=>$i['data']!==null);
$all=$db->table('cis_receipts')->orderBy('ano')->orderBy('sequencia')->get();
ok($all->count()===$cand->count() && \App\Support\CompanySettings::get('recibos_numerados')==='1','recibos: na primeira consulta todo o histórico é numerado ('.$all->count().')');
ok($all->groupBy('ano')->every(fn($g)=>$g->pluck('sequencia')->values()->all()===range(1,$g->count()))
    && $all->groupBy('ano')->every(fn($g)=>$g->pluck('data')->map(fn($d)=>substr((string)$d,0,10))->values()->all()===$g->pluck('data')->map(fn($d)=>substr((string)$d,0,10))->sort()->values()->all())
    && $all->every(fn($r)=>$r->numero===\App\Services\ReceiptNumbering::format((int)$r->ano,(int)$r->sequencia) && str_starts_with($r->numero,'REC-'.$r->ano.'-')),'recibos: sequência recomeça em 1 em cada ano, por ordem da data do pagamento');
ok(collect($r1['recibos'])->every(fn($r)=>preg_match('/^REC-\d{4}-\d{5}$/',$r['numero'])),'recibos do contrato com número anual (ex.: '.(collect($r1['recibos'])->first()['numero']??'?').')');
$r1b=data($rcc->index($areq(),$c1));
ok(collect($r1b['recibos'])->pluck('numero')->all()===collect($r1['recibos'])->pluck('numero')->all(),'recibos: número fixo depois de atribuído');
$yR=(int)substr($today,0,4);$maxR=(int)$db->table('cis_receipts')->where('ano',$yR)->max('sequencia');
$db->table('Extracto')->insert(['Contracto'=>$c1,'Parcela'=>'1','Data da Operação'=>$today,'Tipo de Movimento'=>'Entradas','Descrição'=>'[CAIXA:COBRANCA] Pagamento ensaio '.$c1,'Valor'=>10]);
$r2=data($rcc->index($areq(),$c1));
ok(collect($r2['recibos'])->contains('numero',\App\Services\ReceiptNumbering::format($yR,$maxR+1)),'novo recebimento recebe o próximo número do ano');


echo "== Modelos de contrato (editor com variáveis)\n";
$db->statement('DROP TABLE IF EXISTS cis_contract_templates');
(require __DIR__.'/../database/migrations/2026_11_19_000160_create_cis_contract_templates.php')->up();
(require __DIR__.'/../database/migrations/2026_11_19_000160_create_cis_contract_templates.php')->up();
$ctc=new \App\Http\Controllers\Api\ContractTemplateController();
$treq=fn(string $m,array $q,$u=null)=>tap(Request::create('/x',$m,$q),fn($r)=>$r->setUserResolver(static fn()=>$u??$user));
$t0=data($ctc->index($treq('GET',[])));
$body0=$t0['modelos'][0]['corpo'];
preg_match_all('/\{([^{}\n]+)\}/u',$body0,$mm);
$names=array_column($t0['variaveis'],'nome');
ok(count($t0['modelos'])===1 && (bool)$t0['modelos'][0]['padrao'] && str_contains($body0,'## DÉCIMA TERCEIRA') && !array_diff(array_unique($mm[1]),$names),'modelo de empréstimo instalado uma vez, só com variáveis conhecidas');
$cv=(string)$db->table('Contractos')->orderBy('Ordem')->value('Nr do Contracto');
$vv=data($ctc->values($treq('GET',[]),$cv));
$cap=(float)$db->table('Contractos')->where('Nr do Contracto',$cv)->max('Quantia Creditada');
ok($vv['contrato']===$cv && !array_diff(array_diff($names,['Plano de Pagamentos','Assinaturas']),array_keys($vv['valores']))
    && $vv['valores']['Capital por Extenso']===\App\Support\Extenso::meticais($cap) && count($vv['plano'])>0,'valores do contrato para todas as variáveis ('.$vv['valores']['Capital por Extenso'].')');
ok(\App\Support\Extenso::meticais(4800)==='quatro mil e oitocentos meticais' && \App\Support\Extenso::meticais(1250.5)==='mil duzentos e cinquenta meticais e cinquenta centavos','valor por extenso');
ok($err(fn()=>$ctc->store($treq('POST',['nome'=>'X','corpo'=>'curto'])))===422 && $err(fn()=>$ctc->store($treq('POST',['nome'=>'Novo modelo','corpo'=>str_repeat('Texto do contrato. ',3)],$gestor)))===403,'modelos: validação e só o administrador');
$t1=data($ctc->store($treq('POST',['nome'=>'Contrato de grupo','corpo'=>"# CONTRATO {Nr do Contrato}\nEntre {Nome da Empresa} e {Nome do Mutuário}.\n{Assinaturas}"])));
$id1=$t1['id'];
ok($err(fn()=>$ctc->destroy($treq('DELETE',[]),$t0['modelos'][0]['id']))===422,'não elimina o modelo padrão');
data($ctc->update($treq('PUT',['padrao'=>true]),$id1));
$t2=data($ctc->index($treq('GET',[])));
ok(collect($t2['modelos'])->firstWhere('id',$id1)['padrao'] && collect($t2['modelos'])->where('padrao',true)->count()===1,'tornar padrão: só um modelo padrão');
data($ctc->destroy($treq('DELETE',[]),$t0['modelos'][0]['id']));
ok(count(data($ctc->index($treq('GET',[])))['modelos'])===1,'modelo não padrão eliminado');


echo "== Operações de cobrança activadas (parcial, saldo, renovação dos juros, capitalização, prorrogação)\n";
$opc=new ContractCollectionController();
$op=fn(array $b,string $nr,int $ord,$u=null)=>data($opc->operate(req('/x',$b+['confirmacao'=>true],$u??$user),$nr,$ord));
$opv=fn(array $b,string $nr,int $ord)=>data($opc->previewOperation(req('/x',$b,$user),$nr,$ord));
$ext=fn(string $nr)=>$db->table('Extracto')->where('Contracto',$nr)->where('Tipo de Movimento','Entradas')->get();
$todayD=now('Africa/Maputo')->toDateString();
// A) pagamento parcial + liquidação do saldo
$oA=$row(['Nr do Contracto'=>'OP-0001','Nº'=>9001]);
ok($err(fn()=>$op(['operacao'=>'parcial','data_pagamento'=>$todayD,'valor_recebido'=>'100','conta_id'=>$banco,'referencia'=>'REF-PA0','idempotency_key'=>'a0000000-0000-4000-8000-000000000001'],'OP-0001',$oA))===422,'parcial: tem de cobrir os juros');
$pp=$opv(['operacao'=>'parcial','data_pagamento'=>$todayD],'OP-0001',$oA);
ok($pp['taxa_editavel']===true && $pp['valor']===null && ($pp['taxa_tabela']===null || $pp['taxa']===$pp['taxa_tabela'])
    && is_array($pp['nova_prestacao']) && $pp['nova_prestacao']['capital']==='1000.00','parcial: prévia mostra a nova prestação (capital que fica + juros à taxa da tabela, editável)');
$pq=$opv(['operacao'=>'parcial','taxa_informada'=>'15','data_pagamento'=>$todayD,'valor_recebido'=>'200'],'OP-0001',$oA);
ok($pq['nova_prestacao']['capital']==='950.50' && $pq['nova_prestacao']['juros']==='142.58' && $pq['nova_prestacao']['valor_a_pagar']==='1093.08'
    && $pq['amortizacao']==='49.50' && $pq['juros_cobertos']==='150.50','parcial: 200 = juros 150,50 + capital 49,50; nova prestação 950,50 + 142,58 = 1093,08');
$pa=$op(['operacao'=>'parcial','taxa_informada'=>'15','data_pagamento'=>$todayD,'valor_recebido'=>'200','conta_id'=>$banco,'referencia'=>'REF-PA1','idempotency_key'=>'a0000000-0000-4000-8000-000000000002'],'OP-0001',$oA);
$rA=$db->table('Contractos')->where('Ordem',$oA)->first();
$nA=$db->table('Contractos')->where('Ordem',(int)$pa['nova_ordem'])->first();
ok($pa['registado'] && !$pa['repetido'] && in_array($rA->Estado,['Pago','Pago em Atraso'],true) && abs((float)$rA->{'Valor Pago'}-200)<0.001 && abs((float)$rA->{'Valor a Pagar'}-200)<0.001
    && $ext('OP-0001')->count()===1,'parcial: 200 MT na Caixa, prestação actual fechada com o valor pago');
ok($nA && $nA->{'Nr do Contracto'}==='OP-0001' && $nA->Estado!=='Pago' && abs((float)$nA->{'Valor a Pagar'}-1093.08)<0.001 && abs((float)$nA->{'Valor de Juros'}-142.58)<0.001
    && (int)$nA->{'Nº da Parcela'}===(int)$rA->{'Nº da Parcela'}+1 && (float)$nA->{'Valor Pago'}===0.0
    && substr((string)$nA->{'Data de Cobrança'},0,10)===$pa['nova_prestacao']['data_vencimento'],'parcial: nova prestação criada no mesmo contrato (1093,08 MT)');
$pa2=$op(['operacao'=>'parcial','taxa_informada'=>'15','data_pagamento'=>$todayD,'valor_recebido'=>'200','conta_id'=>$banco,'referencia'=>'REF-PA1','idempotency_key'=>'a0000000-0000-4000-8000-000000000002'],'OP-0001',$oA);
ok($pa2['repetido'] && $ext('OP-0001')->count()===1 && $db->table('Contractos')->where('Nr do Contracto','OP-0001')->count()===2,'parcial: chave repetida não duplica');
ok($err(fn()=>$op(['operacao'=>'parcial','data_pagamento'=>$todayD,'valor_recebido'=>'1150.50','conta_id'=>$banco,'referencia'=>'REF-PA2','idempotency_key'=>'a0000000-0000-4000-8000-000000000003'],'OP-0001',(int)$pa['nova_ordem']))===422
    && $err(fn()=>$op(['operacao'=>'renovar_juros','idempotency_key'=>'a0000000-0000-4000-8000-000000000004'],'OP-0001',$oA))===409,'parcial: não chega ao valor total; prestação já paga não se altera');
$rm=data($opc->collect(req('/x',['data_pagamento'=>$todayD,'cobrar_multa'=>false,'valor_recebido'=>'1093.08','conta_id'=>$banco,'referencia'=>'REF-PA3','idempotency_key'=>'a0000000-0000-4000-8000-000000000005','confirmacao_recebimento'=>true],$user),'OP-0001',(int)$pa['nova_ordem']));
$nA=$db->table('Contractos')->where('Ordem',(int)$pa['nova_ordem'])->first();
ok(in_array($nA->Estado,['Pago','Pago em Atraso'],true) && abs($ext('OP-0001')->sum(fn($e)=>(float)$e->Valor)-1293.08)<0.001,'nova prestação liquidada normalmente: Extracto = 200 + 1093,08');
// B) renovação dos juros sem pagamento
$oB=$row(['Nr do Contracto'=>'OP-0002','Nº'=>9002]);$dueB=(string)$db->table('Contractos')->where('Ordem',$oB)->value('Data de Cobrança');
$rb=$op(['operacao'=>'renovar_juros','idempotency_key'=>'b0000000-0000-4000-8000-000000000001'],'OP-0002',$oB);
$rB=$db->table('Contractos')->where('Ordem',$oB)->first();
ok(abs((float)$rB->{'Valor de Juros'}-300.5)<0.001 && abs((float)$rB->{'Valor a Pagar'}-1300.5)<0.001
    && substr((string)$rB->{'Data de Cobrança'},0,10)===\Carbon\CarbonImmutable::parse($dueB)->addMonthsNoOverflow(1)->toDateString()
    && $ext('OP-0002')->count()===0 && $rB->{'Situação de Pagamento'}==='Juros renovados','renovação dos juros: +1 período (15% × 1000 = 150), vencimento +1 mês, sem Caixa');
$rb2=$op(['operacao'=>'renovar_juros','idempotency_key'=>'b0000000-0000-4000-8000-000000000001'],'OP-0002',$oB);
ok($rb2['repetido'] && abs((float)$db->table('Contractos')->where('Ordem',$oB)->value('Valor de Juros')-300.5)<0.001,'renovação dos juros: chave repetida não duplica');
// C) capitalização com taxa indicada
$oC=$row(['Nr do Contracto'=>'OP-0003','Nº'=>9003]);$dueC=substr((string)$db->table('Contractos')->where('Ordem',$oC)->value('Data de Cobrança'),0,10);
$cp=$op(['operacao'=>'capitalizar','periodo_referencia'=>30,'taxa_informada'=>'20','idempotency_key'=>'c0000000-0000-4000-8000-000000000001'],'OP-0003',$oC);
$rC=$db->table('Contractos')->where('Ordem',$oC)->first();
ok((int)$rC->Capital===1151 && (int)$rC->{'Amortização'}===1151 && abs((float)$rC->{'Valor de Juros'}-230.2)<0.001
    && abs((float)$rC->{'Valor a Pagar'}-1381.2)<0.001 && (float)$rC->{'% de Juro'}===20.0 && substr((string)$rC->{'Data de Inicio'},0,10)===$dueC
    && substr((string)$rC->{'Data de Cobrança'},0,10)===\Carbon\CarbonImmutable::parse($dueC)->addDays(30)->toDateString() && $cp['taxa_alterada']===true,'capitalização: juros passam a capital (1151), nova taxa 20% (230,20), +30 dias');
// D) prorrogação 15 → 30 dias
$startD=now('Africa/Maputo')->subDays(5)->toDateString();
$oD=$row(['Nr do Contracto'=>'OP-0004','Nº'=>9004,'Prazo/Duração'=>'15 Dias','Data de Inicio'=>$startD,'Data de Cobrança'=>now('Africa/Maputo')->addDays(10)->toDateString()]);
$pr=$op(['operacao'=>'prorrogar_15_30','tratamento_juros'=>'manter','idempotency_key'=>'d0000000-0000-4000-8000-000000000001'],'OP-0004',$oD);
$rD=$db->table('Contractos')->where('Ordem',$oD)->first();
ok(substr((string)$rD->{'Data de Cobrança'},0,10)===\Carbon\CarbonImmutable::parse($startD)->addDays(30)->toDateString() && $rD->{'Prazo/Duração'}==='30 Dias'
    && abs((float)$rD->{'Valor de Juros'}-150.5)<0.001,'prorrogação: vencimento = início + 30 dias, prazo 30 dias, juros mantidos');
$hk=$db->table('cis_contractos_historico')->whereIn('operation',['pagamento_parcial','liquidacao_saldo','renovacao_juros_sem_pagamento','capitalizacao_juros','prorrogacao_15_30'])->pluck('operation')->countBy();
ok(($hk['pagamento_parcial']??0)===1 && ($hk['liquidacao_saldo']??0)===0 && ($hk['renovacao_juros_sem_pagamento']??0)===1 && ($hk['capitalizacao_juros']??0)===1 && ($hk['prorrogacao_15_30']??0)===1,'histórico antes/depois de cada operação');
ok($err(fn()=>$op(['operacao'=>'renovar_juros','idempotency_key'=>'e0000000-0000-4000-8000-000000000001'],'OP-0003',$oC,$gestor))===403,'operações: perfil sem cobranças recusado');

echo "== Utilizadores e permissões\n";
$db->statement('CREATE TABLE IF NOT EXISTS personal_access_tokens(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tokenable_type VARCHAR(255),tokenable_id BIGINT UNSIGNED,name VARCHAR(255),token VARCHAR(64) UNIQUE,abilities TEXT NULL,last_used_at TIMESTAMP NULL,expires_at TIMESTAMP NULL,created_at TIMESTAMP NULL,updated_at TIMESTAMP NULL)');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000170_cis_user_management.php','--force'=>true]);
$uc=new \App\Http\Controllers\Api\UserController();
$ureq=function(string $m,array $p,$u){$r=Request::create('/x',$m,$p);$r->setUserResolver(static fn()=>$u);return $r;};
$admin=User::query()->where('email','admin@ci.test')->firstOrFail();
$cr=$uc->store($ureq('POST',['name'=>'Cobrador Ensaio','email'=>'Cobrador@CI.test','role'=>'cobrador','telefone'=>'841234567'],$admin));
$cj=data($cr);$cob=User::query()->where('email','cobrador@ci.test')->firstOrFail();
ok($cr->getStatusCode()===201 && strlen($cj['palavra_passe_temporaria'])===12 && $cob->must_change_password===true && \App\Support\Permissions::of($cob)===[],'utilizador criado com palavra-passe temporária; sem acesso até a trocar');
ok($err(fn()=>$uc->store($ureq('POST',['name'=>'Outro','email'=>'cobrador@ci.test','role'=>'gestor'],$admin)))===422,'email repetido recusado');
$au=new \App\Http\Controllers\Api\AuthController();
ok($err(fn()=>$au->changePassword($ureq('POST',['actual'=>'errada','nova'=>'NovaSenha2026','nova_confirmation'=>'NovaSenha2026'],$cob)))===422,'troca de palavra-passe exige a actual correcta');
$pw=data($au->changePassword($ureq('POST',['actual'=>$cj['palavra_passe_temporaria'],'nova'=>'NovaSenha2026','nova_confirmation'=>'NovaSenha2026'],$cob)));
$cob->refresh();
ok($cob->must_change_password===false && \Illuminate\Support\Facades\Hash::check('NovaSenha2026',$cob->password) && in_array('cobrancas',$pw['user']['permissions'],true)
    && !in_array('caixa',$pw['user']['permissions'],true) && !in_array('utilizadores',$pw['user']['permissions'],true),'após trocar: permissões do cobrador (cobranças sim, Caixa/utilizadores não)');
ok($err(fn()=>$uc->index($ureq('GET',[],$cob)))===403,'cobrador não gere utilizadores');
ok($err(fn()=>$uc->update($ureq('PATCH',['role'=>'gestor'],$admin),$admin->id))===422 && $err(fn()=>$uc->update($ureq('PATCH',['active'=>false],$admin),$admin->id))===422,'não pode mudar a própria função nem desactivar-se');
$up=data($uc->update($ureq('PATCH',['role'=>'financeiro'],$admin),$cob->id));
$cob->refresh();
ok($cob->role==='financeiro' && $up['utilizador']['role_label']==='Gestor financeiro' && \App\Support\Permissions::can($cob,'caixa'),'função alterada para Gestor financeiro (Caixa)');
$cob->createToken('t');data($uc->update($ureq('PATCH',['active'=>false],$admin),$cob->id));$cob->refresh();
ok($cob->active===false && $cob->tokens()->count()===0 && \App\Support\Permissions::of($cob)===[],'desactivado: sessões terminadas e sem permissões');
$rp=data($uc->resetPassword($ureq('POST',[],$admin),$cob->id));$cob->refresh();
ok(strlen($rp['palavra_passe_temporaria'])===12 && $cob->must_change_password===true,'repor palavra-passe gera nova temporária');
$li=data($uc->index($ureq('GET',[],$admin)));
ok(count($li['utilizadores'])>=2 && count($li['auditoria'])>=4,'lista de utilizadores e auditoria');
ok($err(fn()=>$col->collect(req('/x',$bp([]),$gestor),'C-0120',$bd))===403,'agente continua sem cobranças');

echo "== Agências\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000180_cis_branches.php','--force'=>true]);
\App\Support\Branches::reset();
$bc=new \App\Http\Controllers\Api\BranchController();
$sede=\App\Support\Branches::principalId();
ok($sede!==null && $db->table('cis_branches')->where('codigo','SEDE')->exists(),'agência principal (Sede) criada');
$mat=(int)data($bc->store($ureq('POST',['codigo'=>'mat-01','nome'=>'Matola','cidade'=>'Matola'],$admin)))['id'];
ok($db->table('cis_branches')->where('id',$mat)->value('codigo')==='MAT-01','agência criada (código em maiúsculas)');
ok($err(fn()=>$bc->store($ureq('POST',['codigo'=>'MAT-01','nome'=>'Outra'],$admin)))===422 && $err(fn()=>$bc->store($ureq('POST',['codigo'=>'X1','nome'=>'Y'],$gestor)))===403,'código repetido recusado; só quem tem Definições cria');
data($bc->assignContract($ureq('PUT',['branch_id'=>$mat],$admin),'OP-0001'));
$gm=data($uc->store($ureq('POST',['name'=>'Gestora Matola','email'=>'gm@ci.test','role'=>'gestor','branch_id'=>$mat],$admin)));
$gmu=User::query()->where('email','gm@ci.test')->firstOrFail();$gmu->forceFill(['must_change_password'=>false])->save();
ok($gm['utilizador']['agencia']==='Matola' && $gmu->branch_id===$mat,'utilizador ligado à agência Matola');
$lc=new \App\Http\Controllers\Api\ContractListController();
$lreq=function(array $q,$u){$r=Request::create('/x','GET',$q);$r->setUserResolver(static fn()=>$u);return $r;};
$mine=collect($lc->index($lreq([],$gmu))->toArray()['data'])->pluck('numero')->all();
$all=collect($lc->index($lreq([],$admin))->toArray()['data'])->pluck('numero');
$filtered=collect($lc->index($lreq(['agencia'=>$mat],$admin))->toArray()['data'])->pluck('numero')->all();
ok($mine===['OP-0001'] && $filtered===['OP-0001'] && $all->count()>1 && $all->contains('OP-0001'),'agência: a gestora só vê os contratos da Matola; o administrador vê todos e pode filtrar');
\App\Support\Branches::reset();
$e1=null;try{\App\Support\Branches::requireContract($gmu,'OP-0002');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$e1=$x->getStatusCode();}
$e2=null;try{\App\Support\Branches::requireContract($gmu,'OP-0001');\App\Support\Branches::requireContract($admin,'OP-0002');}catch(\Throwable $x){$e2='erro';}
ok($e1===403 && $e2===null,'contrato de outra agência recusado (403); da própria agência e administrador permitidos');
data($bc->assignAccount($ureq('PUT',['branch_id'=>$mat],$admin),$banco));
ok(\App\Support\Branches::accountVisible($gmu,$banco) && \App\Support\Branches::accountVisible($gmu,$numerario),'contas: a da agência e as partilhadas visíveis');
data($bc->assignAccount($ureq('PUT',['branch_id'=>$sede],$admin),$numerario));
ok(!\App\Support\Branches::accountVisible($gmu,$numerario),'conta de outra agência escondida');
$bi=data($bc->index($ureq('GET',[],$admin)));
ok(count($bi['agencias'])===2 && collect($bi['agencias'])->firstWhere('id',$mat)['contratos']===1 && collect($bi['agencias'])->firstWhere('id',$mat)['utilizadores']===1,'resumo por agência (contratos e utilizadores)');
ok($err(fn()=>$bc->update($ureq('PUT',['codigo'=>'SEDE','nome'=>'Sede','activo'=>false],$admin),$sede))===422,'a agência principal não pode ser desactivada');

echo "== Carteiras e desempenho\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000190_cis_portfolios.php','--force'=>true]);
\App\Support\Portfolio::reset();\App\Support\Branches::reset();
$pc=new \App\Http\Controllers\Api\PortfolioController();
$c1=new User();$c1->forceFill(['name'=>'Carla Carteira','email'=>'carla@ci.test','password'=>bcrypt('x'),'role'=>'cobrador','active'=>1,'must_change_password'=>false])->save();
$ag1=new User();$ag1->forceFill(['name'=>'Alberto Agente','email'=>'alberto@ci.test','password'=>bcrypt('x'),'role'=>'agente','active'=>1,'must_change_password'=>false])->save();
ok($err(fn()=>$pc->assignContract($ureq('PUT',['user_id'=>$ag1->id],$admin),'OP-0002'))===422,'só um gestor de carteira (cobrador) pode ser responsável');
ok($err(fn()=>$pc->assignContract($ureq('PUT',['user_id'=>$c1->id],$gmu),'OP-0002'))===403,'só o administrador atribui carteiras');
data($pc->assignContract($ureq('PUT',['user_id'=>$c1->id],$admin),'OP-0002'));
\App\Support\Portfolio::reset();
$cobList=collect($lc->index($lreq([],$c1))->toArray()['data'])->pluck('numero')->all();
ok($cobList===['OP-0002'],'gestor de carteira só vê os contratos de que é responsável');
$e3=null;try{\App\Support\Branches::requireContract($c1,'OP-0001');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$e3=$x->getStatusCode();}
ok($e3===403,'contrato fora da carteira recusado (403)');
$bulk=data($pc->assignBulk($ureq('POST',['user_id'=>$c1->id,'agencia'=>$sede,'so_activos'=>false,'so_sem_responsavel'=>true],$admin)));
\App\Support\Portfolio::reset();
$owned=$db->table('cis_contract_owner')->where('user_id',$c1->id)->pluck('contrato')->all();
ok($bulk['atribuidos']>=1 && in_array('OP-0002',$owned,true) && !in_array('OP-0001',$owned,true),'atribuição em massa por agência (só a Sede; Matola fica de fora)');
$cid=(int)$db->table('Contractos')->where('Nr do Contracto','OP-0003')->value('Nº de Cliente');
data($pc->assignClient($ureq('PUT',['user_id'=>$ag1->id],$admin),$cid));
\App\Support\Portfolio::reset();
$agContracts=\App\Support\Portfolio::contracts($ag1);
ok(\App\Support\Portfolio::clients($ag1)===[$cid] && in_array('OP-0003',$agContracts,true),'agente vê os seus mutuários e os contratos deles');
$e4=null;try{\App\Support\Portfolio::requireClient($ag1,$cid+999);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$e4=$x->getStatusCode();}
ok($e4===403,'mutuário de outro agente recusado (403)');
$ovc=data((new \App\Http\Controllers\Api\OverviewController())($lreq([],$c1)));
$ovg=data((new \App\Http\Controllers\Api\OverviewController())($lreq([],$gmu)));
ok($ovc['carteira_propria']===true && $ovc['caixa']['disponivel']===false && $ovg['agencia']['nome']==='Matola','visão geral: carteira própria sem Caixa; gestora vê a sua agência');
$rc=new \App\Http\Controllers\Api\ReportController();
$cart=data($rc->show($lreq([],$admin),'carteira'));
$names=collect($cart['seccoes'][0]['linhas'])->pluck(0)->all();
ok(in_array('Carla Carteira',$names,true),'relatório de gestores de carteira');
$agr=data($rc->show($lreq([],$admin),'agentes'));
ok(collect($agr['seccoes'][0]['linhas'])->pluck(0)->contains('Alberto Agente'),'relatório de agentes');
ok($err(fn()=>$rc->show($lreq([],$gmu),'carteira'))===403,'desempenho da equipa: só o administrador');
$gl=collect(data($rc->index($lreq([],$gmu)))['relatorios'])->pluck('id');
ok(!$gl->contains('carteira') && !$gl->contains('operadores') && $gl->contains('agenda'),'gestor não vê os relatórios de desempenho');
$ex=data((new \App\Http\Controllers\Api\ExtractoController())->geral($lreq([],$gmu)));
ok(collect($ex['linhas'])->every(fn($l)=>in_array((string)($l['contrato']??$l['Contracto']??''),['OP-0001',''],true)),'extracto geral: a gestora só vê movimentos da sua agência');


echo "== Reforço, abate e recuperação de crédito\n";
$changeAccount=$acc('CREDITO-TESTE','5000.00');
$db->table('cis_tabela_juros')->insert([
    ['limite_inferior'=>1001,'limite_superior'=>1250,'taxa'=>25,'periodo_referencia'=>15],
    ['limite_inferior'=>1251,'limite_superior'=>1500,'taxa'=>30,'periodo_referencia'=>15],
]);
$creditOrder=$row(['Nr do Contracto'=>'C-4200','Nº'=>4200,'Prazo/Duração'=>'15 Dias','Valor Pago'=>0,'Valor de Juros'=>150,'Valor a Pagar'=>1150]);
$creditBefore=$db->table('Contractos')->where('Ordem',$creditOrder)->first();
$creditProfile=data((new \App\Http\Controllers\Api\ContractProfileController())->show(req('/x',[],$user),'C-4200'));
ok(($creditProfile['installments'][0]['Tipo de Juro']??null)==='Fixo','a ficha expõe o tipo de juro de cada prestação para o reforço');

$creditChanges=new \App\Http\Controllers\Api\ContractCollectionController();
$change=fn(array $fields)=>data($creditChanges->creditChange(req('/x',$fields,$user),'C-4200'));
$reinforce=['operacao'=>'reforco','ordem'=>$creditOrder,'valor'=>'200.00','taxa'=>15];
$preview=$change($reinforce);
$automaticRate=$change(array_diff_key($reinforce,['taxa'=>true]));
$editedRate=$change(array_replace($reinforce,['taxa'=>20]));
ok((float)($creditProfile['installments'][0]['% de Juro']??-1)===15.0
    && $automaticRate['taxa']===25 && $automaticRate['periodo_referencia']===15 && $automaticRate['origem_taxa']==='tabela' && $automaticRate['capital']==='1200.00'
    && $automaticRate['juros']==='300.00' && $automaticRate['valor_a_pagar']==='1500.00',
    'prévia usa a tabela do prazo em vigor e discrimina capital, juros e prestação');
$anotherBand=$change(array_replace(array_diff_key($reinforce,['taxa'=>true]),['valor'=>'300.00']));
ok($anotherBand['taxa']===30 && $anotherBand['capital']==='1300.00','alterar o montante actualiza a faixa e a taxa sugerida');
ok($editedRate['taxa']===20 && $editedRate['juros']==='240.00' && $editedRate['valor_a_pagar']==='1440.00',
    'alterar a taxa recalcula os juros e o valor da prestação');

ok($preview['valor_a_pagar']==='1380.00' && $preview['vencimento']===$creditBefore->{'Data de Cobrança'},'reforço mantém vencimento e recalcula juros');
$confirm=['confirmar'=>true,'motivo'=>'Autorizado no ensaio','referencia'=>'DOC-CI','conta_id'=>$changeAccount];
$reinforce+=['idempotency_key'=>'a0000000-0000-4000-8000-000000004200','valor_confirmado'=>'1380.00']+$confirm;
$change($reinforce);
$reinforcedProfile=data((new \App\Http\Controllers\Api\ContractProfileController())->show(req('/x',[],$user),'C-4200'));
$reinforcedInstallment=$reinforcedProfile['installments'][0];
ok(count($reinforcedProfile['installments'])===1
    && (int)$reinforcedInstallment['Ordem']===$creditOrder
    && (float)$reinforcedInstallment['Capital']===1200.0
    && (float)$reinforcedInstallment['Valor de Juros']===180.0
    && (float)$reinforcedInstallment['Valor a Pagar']===1380.0
    && (string)$reinforcedInstallment['Data de Cobrança']===(string)$creditBefore->{'Data de Cobrança'},
    'o reforço actualiza capital, juros e valor da prestação na ficha, mantendo o vencimento');
ok($change($reinforce)['repetido']===true && $db->table('Extracto')->where('Contracto','C-4200')->where('Tipo de Movimento','Saídas')->count()===1,
    'reforço e repetição geram uma saída');
$beforeAbate=$db->table('Extracto')->count();
$abatePreview=$change(['operacao'=>'abate']);
ok($abatePreview['antecipado']===true && $abatePreview['dias_minimos']===90,'abate de contrato sem 90 dias de atraso é marcado como antecipado');
ok($err(fn()=>$change(['operacao'=>'abate','idempotency_key'=>'a0000000-0000-4000-8000-000000004203','valor_confirmado'=>'1380.00']+$confirm))===422,
    'abate antecipado exige confirmação expressa');
$change(['operacao'=>'abate','antecipado'=>true,'idempotency_key'=>'a0000000-0000-4000-8000-000000004204','valor_confirmado'=>'1380.00']+$confirm);
ok($db->table('Contractos')->where('Ordem',$creditOrder)->value('Estado')==='Abatido','abate antecipado justificado registado');
$change(['operacao'=>'anular_abate','idempotency_key'=>'a0000000-0000-4000-8000-000000004205','valor_confirmado'=>'1380.00']+$confirm);
ok(in_array($db->table('Contractos')->where('Ordem',$creditOrder)->value('Estado'),['Pendente','Pagamento Atrasado'],true)
    && $db->table('Contractos')->where('Ordem',$creditOrder)->value('Estado do Contrato')!=='Abatido','anular o abate devolve a prestação à carteira');
$change(['operacao'=>'abate','antecipado'=>true,'idempotency_key'=>'a0000000-0000-4000-8000-000000004201','valor_confirmado'=>'1380.00']+$confirm);
ok($db->table('Extracto')->count()===$beforeAbate && $db->table('Contractos')->where('Ordem',$creditOrder)->value('Estado')==='Abatido',
    'abate sai da carteira sem afectar a Caixa');
$recover=['operacao'=>'recuperacao','ordem'=>$creditOrder,'valor'=>'50.00'];
ok($change($recover)['saldo_apos_operacao']==='1330.00','recuperação parte do saldo abatido congelado');
$change($recover+['idempotency_key'=>'a0000000-0000-4000-8000-000000004202','valor_confirmado'=>'1330.00']+$confirm);
ok($change($recover)['saldo_recuperavel']==='1330.00' && $db->table('Extracto')->where('Contracto','C-4200')->where('Tipo de Movimento','Entradas')->count()===1,
    'recuperação reduz saldo abatido, com uma entrada na Caixa');
ok($err(fn()=>$change(['operacao'=>'anular_abate','idempotency_key'=>'a0000000-0000-4000-8000-000000004206','valor_confirmado'=>'1380.00']+$confirm))===409,
    'com recuperações o abate já não pode ser anulado');
$abProfile=data((new \App\Http\Controllers\Api\ContractProfileController())->show(req('/x',[],$user),'C-4200'));
ok(($abProfile['abate']['valor']??null)==='1380.00' && ($abProfile['abate']['recuperado']??null)==='50.00' && count($abProfile['reforcos']??[])===1
    && ($abProfile['reforcos'][0]['valor']??null)==='200.00','a ficha mostra o reforço e o abate em vigor com o recuperado');
$abRep=data((new \App\Http\Controllers\Api\ReportController())->show(req('/x',['inicio'=>now($tz)->subDays(30)->toDateString(),'fim'=>now($tz)->toDateString()],$user),'abates'));
$abLines=collect($abRep['seccoes'][0]['linhas'])->where(1,'C-4200');
ok($abLines->count()===2 && $abLines->pluck(6)->sort()->values()->all()===['Anulado','Recuperação parcial'] && count($abRep['seccoes'][1]['linhas'])===1,
    'relatório de abates: abate anulado, abate em vigor com recuperação parcial e a recuperação do período');
$act=data((new \App\Http\Controllers\Api\ReportController())->show(req('/x',['inicio'=>now($tz)->subDays(30)->toDateString(),'fim'=>now($tz)->toDateString()],$user),'actividade'));
ok(collect($act['seccoes'][0]['linhas'])->contains(fn($l)=>$l[0]==='Reforços de crédito (n.º / MT)' && str_starts_with($l[1],'1 / 200.00')),'relatório de actividade conta o reforço');
$pc2=new \App\Http\Controllers\Api\ContractCollectionController();
$plain=new User();$plain->forceFill(['name'=>'Gestora Sem Alteração','email'=>'gsa@ci.test','password'=>bcrypt('x'),'role'=>'gestor','active'=>1,'must_change_password'=>false])->save();
ok($err(fn()=>$pc2->creditChange(req('/x',['operacao'=>'abate'],$plain),'C-4200'))===403,'só quem tem a permissão de alterações de crédito');

echo "== Propostas de crédito com aprovação\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000210_cis_credit_proposals.php','--force'=>true]);
$PC=new \App\Http\Controllers\Api\ProposalController();
ok(\App\Http\Controllers\Api\ProposalController::ready(),'tabelas das propostas instaladas');
\App\Support\Portfolio::assignClient(7,$ag1->id,$admin->id);\App\Support\Portfolio::reset();
$pb=['cliente_id'=>7,'montante'=>300,'tipo_juro'=>'Fixo','estrategia_calculo'=>ContractCalculationStrategy::FIXED_AMORTIZATION,
    'periodo_dias'=>30,'prazo'=>'1 Mês','taxa_informada'=>15,'taxa_multa'=>10,'prazo_meses'=>1,'modalidade'=>'Uma Parcela',
    'forma_pagamento'=>'Numerário','disponibilidade'=>'Numerário','data_inicio'=>$today,'confirmacao_desembolso'=>true,'referencia'=>'SAIDA-PROP','conta_id'=>$banco];
ok(status(fn()=>(new ContractOpeningController())->store(req('/x',$pb+['idempotency_key'=>'c0000000-0000-4000-8000-000000000001'],$admin)))===422,
    'sem proposta aprovada não há desembolso');
$prop=['client_id'=>7,'montante'=>400,'tipo_juro'=>'Fixo','estrategia'=>ContractCalculationStrategy::FIXED_AMORTIZATION,'periodo_dias'=>30,'prazo'=>'1 Mês',
    'modalidade'=>'Uma Parcela','finalidade'=>'Compra de stock','rendimento_mensal'=>5000,'despesas_mensais'=>1500,'outras_dividas_mensais'=>0];
$an=data($PC->analyse(req('/x',$prop,$ag1)));
ok($an['analise']['prestacao']==='460.00' && $an['analise']['taxa_esforco']!==null,'análise: prestação estimada pela tabela (400 + 15%) e taxa de esforço');
$p1=data($PC->store(req('/x',$prop,$ag1)));
ok($p1['estado']==='rascunho' && str_starts_with($p1['numero'],'PROP-'),'agente cria a proposta em rascunho ('.$p1['numero'].')');
$p1=data($PC->submit(req('/x',[],$ag1),$p1['id']));
ok($p1['estado']==='submetida' && ($p1['analise']['calculo']['prestacao']??null)==='460.00','submetida com a análise registada');
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'aprovar'],$ag1),$p1['id']))===403,'o agente não aprova');
$g2=new User();$g2->forceFill(['name'=>'Gestor Sede','email'=>'gsede@ci.test','password'=>bcrypt('x'),'role'=>'gestor','active'=>1,'must_change_password'=>false])->save();
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'limite_aprovacao_gestor'],['valor'=>'350','updated_at'=>now(),'created_at'=>now()]);\App\Support\CompanySettings::forget();
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'aprovar'],$g2),$p1['id']))===403,'acima do limite do gestor: só o administrador');
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'rejeitar'],$admin),$p1['id']))===422,'rejeitar exige motivo');
$p1=data($PC->decide(req('/x',['decisao'=>'aprovar','montante_aprovado'=>300,'notas'=>'Aprovado com redução'],$admin),$p1['id']));
ok($p1['estado']==='aprovada' && $p1['montante_aprovado']===300 && $p1['valida_ate']!==null,'administrador aprova com montante reduzido e validade');
$ap=collect(data($PC->approved(req('/x',[],$admin)))['data'])->pluck('id');
ok($ap->contains($p1['id']),'proposta aparece entre as aprovadas para abrir contrato');
ok(status(fn()=>(new ContractOpeningController())->store(req('/x',array_replace($pb,['montante'=>400])+['proposta_id'=>$p1['id'],'idempotency_key'=>'c0000000-0000-4000-8000-000000000002'],$admin)))===422,
    'o capital não pode exceder o aprovado');
$kP='c0000000-0000-4000-8000-000000000003';
$op=data((new ContractOpeningController())->store(req('/x',$pb+['proposta_id'=>$p1['id'],'idempotency_key'=>$kP],$admin)));
$after=$db->table('cis_credit_proposals')->where('id',$p1['id'])->first();
ok(!empty($op['numero']) && $after->status==='desembolsada' && $after->contract_number===$op['numero'],'desembolso a partir da proposta: proposta desembolsada e ligada ao contrato');
$op2=data((new ContractOpeningController())->store(req('/x',$pb+['proposta_id'=>$p1['id'],'idempotency_key'=>$kP],$admin)));
ok(($op2['repetido']??false)===true && $db->table('cis_credit_proposals')->where('id',$p1['id'])->value('contract_number')===$op['numero'],'repetição do desembolso não duplica nem desliga a proposta');
ok(status(fn()=>(new ContractOpeningController())->store(req('/x',$pb+['proposta_id'=>$p1['id'],'idempotency_key'=>'c0000000-0000-4000-8000-000000000004'],$admin)))===422,
    'a mesma proposta não abre um segundo contrato');
$p2=data($PC->store(req('/x',array_replace($prop,['montante'=>200]),$ag1)));$PC->submit(req('/x',[],$ag1),$p2['id']);
$p2=data($PC->decide(req('/x',['decisao'=>'devolver','notas'=>'Falta comprovativo de rendimento'],$g2),$p2['id']));
ok($p2['estado']==='devolvida','gestor devolve dentro do seu limite, com notas');
$PC->submit(req('/x',[],$ag1),$p2['id']);
$p2=data($PC->decide(req('/x',['decisao'=>'aprovar'],$g2),$p2['id']));
ok($p2['estado']==='aprovada' && $p2['decidido_por']==='Gestor Sede','gestor aprova dentro do limite');
$db->table('cis_credit_proposals')->where('id',$p2['id'])->update(['valida_ate'=>now($tz)->subDay()->toDateString()]);
$ix=data($PC->index(req('/x',[],$admin)));
ok(collect($ix['data'])->firstWhere('id',$p2['id'])['estado']==='expirada','aprovação vencida passa a expirada');
$p3=data($PC->store(req('/x',array_replace($prop,['montante'=>100]),$g2)));$PC->submit(req('/x',[],$g2),$p3['id']);
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'aprovar'],$g2),$p3['id']))===403,'ninguém (excepto o administrador) aprova a própria proposta');
ok($err(fn()=>$PC->show(req('/x',[],$gmu),$p3['id']))===404,'gestora de outra agência não vê a proposta');
$ev=$db->table('cis_credit_proposal_events')->where('proposal_id',$p1['id'])->pluck('accao')->all();
ok($ev===['criada','submetida','aprovada','desembolsada'],'histórico da proposta: '.implode(' → ',$ev));

echo "== Contactos, promessas e lembretes\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000220_cis_collection_contacts.php','--force'=>true]);
$CC=new \App\Http\Controllers\Api\CollectionContactController();
$cn=(string)$op['numero'];
ok(\App\Http\Controllers\Api\CollectionContactController::ready() && $db->table('cis_message_templates')->where('name','Lembrete de promessa')->exists(),'tabela de contactos e modelo «Lembrete de promessa» instalados');
ok(status(fn()=>$CC->store(req('/x',['canal'=>'chamada','resultado'=>'prometeu_pagar'],$admin),$cn))===422,'promessa exige valor e data');
$c1=data($CC->store(req('/x',['canal'=>'chamada','resultado'=>'prometeu_pagar','promessa_valor'=>'100','promessa_data'=>$today,'notas'=>'Paga ao fim do dia'],$admin),$cn));
ok(($c1['promessa']['estado']??null)==='pendente','contacto com promessa registado');
$ag=data($CC->agenda(req('/x',[],$admin)));
ok(collect($ag['promessas_hoje'])->pluck('contrato')->contains($cn),'agenda: promessa para hoje');
$mcc=new \App\Http\Controllers\Api\MessageCenterController();
$lem=collect($mcc->lembretesLista(req('/x',[],$admin)));
$mine=$lem->firstWhere('contrato',$cn);
ok($mine && $mine['regra']==='promessa' && str_contains($mine['mensagem'],'100'),'lembrete automático da promessa com o valor prometido');
$mcc->registo(req('/x',['contrato'=>$cn,'canal'=>'whatsapp_web','estado'=>'enviada','grupo'=>'lembretes','mensagem'=>$mine['mensagem']],$admin));
ok(!collect($mcc->lembretesLista(req('/x',[],$admin)))->pluck('contrato')->contains($cn),'contrato já lembrado hoje sai da lista');
$db->table('Extracto')->insert(['Contracto'=>$cn,'Cliente'=>'Cliente Novo','Parcela'=>'1','Data da Operação'=>$today,'Valor'=>100,'Tipo de Movimento'=>'Entradas',
    'Descrição'=>'[CAIXA:COBRANCA] Pagamento parcial ensaio','usuario'=>'ensaio','account_id'=>$banco,'operation_key'=>'ci:promessa:1']);
\App\Http\Controllers\Api\CollectionContactController::evaluate([$cn]);
ok($db->table('cis_collection_contacts')->where('id',$c1['id'])->value('promessa_estado')==='cumprida','pagamento até à data prometida: promessa cumprida');
$c2=data($CC->store(req('/x',['canal'=>'visita','resultado'=>'prometeu_pagar','promessa_valor'=>'200','promessa_data'=>$today],$admin),$cn));
$db->table('cis_collection_contacts')->where('id',$c2['id'])->update(['promessa_data'=>now($tz)->subDay()->toDateString(),'created_at'=>now($tz)->subDays(2)]);
$ag=data($CC->agenda(req('/x',[],$admin)));
ok($db->table('cis_collection_contacts')->where('id',$c2['id'])->value('promessa_estado')==='falhada' && collect($ag['promessas_falhadas'])->pluck('contrato')->contains($cn),
    'data passada sem pagamento suficiente: promessa falhada e na agenda');
$c3=data($CC->store(req('/x',['canal'=>'chamada','resultado'=>'sem_resposta','proximo_contacto'=>$today],$admin),$cn));
$ag=data($CC->agenda(req('/x',[],$admin)));
ok(collect($ag['seguimentos'])->pluck('contrato')->contains($cn) && !collect($ag['promessas_falhadas'])->pluck('contrato')->contains($cn),'novo contacto com seguimento substitui o alerta da promessa falhada');
$pr=data((new \App\Http\Controllers\Api\ReportController())->show(req('/x',['inicio'=>now($tz)->subDays(5)->toDateString(),'fim'=>$today],$admin),'promessas'));
ok(collect($pr['resumo'])->firstWhere(0,'Taxa de cumprimento')[1]==='50.0 %' && count($pr['seccoes'][1]['linhas'])===1,'relatório de promessas: 1 cumprida, 1 falhada (50%)');
$hist=data($CC->index(req('/x',[],$admin),$cn));
ok(count($hist['data'])===3 && $hist['data'][0]['resultado']==='sem_resposta','histórico de contactos do contrato');

echo "== Propostas: limites da agência, não levantada e avisos (Loandisk)\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000230_cis_proposal_rules.php','--force'=>true]);
ok(\Illuminate\Support\Facades\Schema::hasColumn('cis_branches','credito_maximo') && \Illuminate\Support\Facades\Schema::hasColumn('cis_credit_proposals','avisado_estado')
    && $db->table('cis_message_templates')->whereIn('name',['Proposta aprovada','Proposta recusada','Proposta não levantada'])->count()===3,'colunas de limites/avisos e modelos de aviso instalados');
$p4=data($PC->store(req('/x',$prop,$ag1)));
$b4=(int)$db->table('cis_credit_proposals')->where('id',$p4['id'])->value('branch_id');
$bRow=$db->table('cis_branches')->where('id',$b4)->first();
$BC=new \App\Http\Controllers\Api\BranchController();
ok(status(fn()=>$BC->update(req('/x',['codigo'=>$bRow->codigo,'nome'=>$bRow->nome,'credito_minimo'=>500,'credito_maximo'=>100],$admin),$b4))===422,'agência: máximo inferior ao mínimo é recusado');
ok(status(fn()=>$BC->update(req('/x',['codigo'=>$bRow->codigo,'nome'=>$bRow->nome,'credito_maximo'=>350],$admin),$b4))===200
    && (int)$db->table('cis_branches')->where('id',$b4)->value('credito_maximo')===350,'agência guarda só o máximo (mínimo em branco)');
$an4=data($PC->analyse(req('/x',$prop,$ag1)));
ok(collect($an4['analise']['alertas'])->contains(fn($a)=>str_contains($a,'máximo da agência')),'análise avisa que o montante passa o máximo da agência');
ok($err(fn()=>$PC->submit(req('/x',[],$ag1),$p4['id']))===422,'proposta acima do máximo da agência não é submetida');
$db->table('cis_branches')->where('id',$b4)->update(['credito_maximo'=>1000]);
$p4=data($PC->submit(req('/x',[],$ag1),$p4['id']));
ok($p4['estado']==='submetida','dentro dos limites: submetida');
$db->table('cis_branches')->where('id',$b4)->update(['credito_maximo'=>350]);
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'aprovar'],$admin),$p4['id']))===422,'a aprovação também respeita o máximo da agência');
$p4=data($PC->decide(req('/x',['decisao'=>'aprovar','montante_aprovado'=>300],$admin),$p4['id']));
$db->table('cis_branches')->where('id',$b4)->update(['credito_maximo'=>null]);
$d4=data($PC->show(req('/x',[],$ag1),$p4['id']));
ok($p4['estado']==='aprovada' && $d4['avisado']===false && ($d4['aviso']['modelo']??null)==='Proposta aprovada'
    && str_contains($d4['aviso']['mensagem'],$p4['numero']) && str_contains($d4['aviso']['mensagem'],'300,00'),'aprovada: aviso por enviar com o modelo preenchido');
$MC=new \App\Http\Controllers\Api\MessageCenterController();
$fila=data($MC->destinatarios(req('/x',['grupo'=>'propostas'],$admin)));
ok(collect($fila['destinatarios'])->pluck('contrato')->contains($p4['numero']),'Centro de Mensagens: a proposta aparece em «Avisos de propostas»');
$MC->registo(req('/x',['contrato'=>$p4['numero'],'canal'=>'whatsapp','grupo'=>'propostas','mensagem'=>'teste'],$admin));
$fila=data($MC->destinatarios(req('/x',['grupo'=>'propostas'],$admin)));
ok(!collect($fila['destinatarios'])->pluck('contrato')->contains($p4['numero']) && data($PC->show(req('/x',[],$ag1),$p4['id']))['avisado']===true,'depois de enviado sai da fila e fica avisado');
ok($err(fn()=>$PC->notTaken(req('/x',['notas'=>'x'],$ag1),$p4['id']))===422,'não levantada exige motivo');
$p4=data($PC->notTaken(req('/x',['notas'=>'O mutuário desistiu'],$ag1),$p4['id']));
ok($p4['estado']==='nao_levantada' && $p4['avisado']===false && ($p4['aviso']['modelo']??null)==='Proposta não levantada','não levantada: novo aviso por enviar');
ok(!collect(data($PC->approved(req('/x',[],$admin)))['data'])->pluck('id')->contains($p4['id']),'não levantada já não serve para abrir contrato');
ok($err(fn()=>$PC->notTaken(req('/x',['notas'=>'Outra vez'],$ag1),$p4['id']))===409,'só uma proposta aprovada pode ficar não levantada');
$p4=data($PC->noticed(req('/x',[],$ag1),$p4['id']));
ok($p4['avisado']===true && $db->table('cis_message_log')->where('group','propostas')->where('contract_number',$p4['numero'])->count()===2,'«Já avisei» regista o aviso no histórico de mensagens');

echo "== Dossier do crédito e mutuários por agência\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_23_000240_cis_credit_dossier.php','--force'=>true]);
\App\Support\Branches::reset();\App\Support\Portfolio::reset();\App\Support\CreditDossier::reset();
ok(\App\Support\CreditDossier::ready() && Schema::hasTable('cis_client_branch'),'tabelas do dossier e agência do mutuário instaladas');
ok(strtolower((string)$db->selectOne("SELECT DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cis_document_files' AND COLUMN_NAME='conteudo'")->t)==='mediumblob','ficheiros guardados em MEDIUMBLOB');
// Mutuários por agência
$matClient=(int)$db->table('Contractos')->where('Nr do Contracto','OP-0001')->value('Nº de Cliente');
$newRow=(array)$db->table('Clientes')->where('ID',7)->first();$newRow['ID']=9901;$newRow['Nome Completo']='Mutuário Sem Contratos';
if(array_key_exists('Numeração',$newRow))$newRow['Numeração']='9901';if(array_key_exists('Nº de BI',$newRow))$newRow['Nº de BI']='990100000000Z';
$db->table('Clientes')->insert($newRow);\App\Support\Branches::reset();
$visMat=(clone $db->table('Clientes'));\App\Support\Branches::applyToClients($visMat,$mat);$visMat=$visMat->pluck('ID')->map(fn($v)=>(int)$v)->all();
$visSede=(clone $db->table('Clientes'));\App\Support\Branches::applyToClients($visSede,$sede);$visSede=$visSede->pluck('ID')->map(fn($v)=>(int)$v)->all();
$onlySede=9901;
ok(in_array($matClient,$visMat,true) && in_array(9901,$visSede,true) && !in_array(9901,$visMat,true),'lista de mutuários por agência: mutuário sem contratos fica na Sede e não aparece na Matola');
$e5=null;try{\App\Support\Portfolio::requireClient($gmu,$onlySede);}catch(\Symfony\Component\HttpKernel\Exception\HttpException $x){$e5=$x->getStatusCode();}
ok($e5===403,'ficha de mutuário de outra agência recusada (403)');
\App\Support\Branches::assignClient($onlySede,$mat,$admin->id);
$e6=null;try{\App\Support\Portfolio::requireClient($gmu,$onlySede);}catch(\Throwable $x){$e6='erro';}
ok($e6===null,'mutuário atribuído à agência passa a ser visível nela');
\App\Support\Branches::assignClient($onlySede,null,$admin->id);
$ag2=new User();$ag2->forceFill(['name'=>'Beatriz Agente','email'=>'beatriz@ci.test','password'=>bcrypt('x'),'role'=>'agente','active'=>1,'must_change_password'=>false])->save();
$bk=data($pc->assignClientsBulk($ureq('POST',['user_id'=>$ag2->id,'agencia'=>$sede,'so_sem_agente'=>true],$admin)));
ok($bk['atribuidos']>=1 && $db->table('cis_client_owner')->where('user_id',$ag2->id)->where('client_id',9901)->exists() && !$db->table('cis_client_owner')->where('user_id',$ag2->id)->where('client_id',7)->exists(),'atribuição em massa de mutuários sem agente a um agente');
ok($err(fn()=>$pc->assignClientsBulk($ureq('POST',['user_id'=>(int)User::query()->where('email','carla@ci.test')->value('id'),'agencia'=>$mat],$admin)))===422,'atribuição de mutuários só a agentes');
\App\Support\Portfolio::reset();
// Dossier
$DC=new \App\Http\Controllers\Api\CreditDossierController();
$p5=data($PC->store(req('/x',array_replace($prop,['montante'=>150]),$ag1)));$PC->submit(req('/x',[],$ag1),$p5['id']);
$ds=data($DC->show(req('/x',[],$ag1),$p5['id']));
ok($ds['instalado'] && $ds['verificacao']['em_falta']===['Bilhete de identidade'],'por omissão a aprovação exige o BI digitalizado');
ok($err(fn()=>$PC->decide(req('/x',['decisao'=>'aprovar'],$admin),$p5['id']))===422,'sem BI a proposta não é aprovada');
ok($err(fn()=>$DC->addGuarantor(req('/x',['nome'=>'Jo'],$ag1),$p5['id']))===422,'fiador exige nome');
$ds=data($DC->addGuarantor(req('/x',['nome'=>'João Cossa','bi'=>'110100000000A','contacto'=>'841234567','relacao'=>'Irmão'],$ag1),$p5['id']));
$ds=data($DC->addCollateral(req('/x',['tipo'=>'viatura','descricao'=>'Motorizada Honda 2015','valor_estimado'=>300],$ag1),$p5['id']));
ok(count($ds['fiadores'])===1 && count($ds['garantias'])===1 && $ds['verificacao']['cobertura']['pct']==200.0,'fiador e garantia registados; cobertura 200%');
$pdf="%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$upReq=function(array $data,string $name,string $content,$u){$f=\Illuminate\Http\UploadedFile::fake()->createWithContent($name,$content);$r=Request::create('/x','POST',$data,[],['ficheiro'=>$f]);$r->setUserResolver(static fn()=>$u);return $r;};
ok($err(fn()=>$DC->upload($upReq(['client_id'=>7,'tipo'=>'bi'],'bi.exe','MZ binário',$ag1)))===422,'tipo de ficheiro não permitido é recusado');
$up=data($DC->upload($upReq(['client_id'=>7,'tipo'=>'bi','descricao'=>'Frente e verso'],'BI João.pdf',$pdf,$ag1)));
$doc=$db->table('cis_documents')->where('id',$up['id'])->first();
ok($doc && $doc->mime==='application/pdf' && $doc->proposal_id===null && $doc->sha256===hash('sha256',$pdf),'BI anexado na ficha do mutuário (PDF, com hash)');
$dl=$DC->download(req('/x',[],$admin),$up['id']);
ok($dl->getContent()===$pdf && $dl->headers->get('Content-Type')==='application/pdf','o documento abre com o conteúdo original');
$p5=data($PC->decide(req('/x',['decisao'=>'aprovar'],$admin),$p5['id']));
ok($p5['estado']==='aprovada','com o BI no dossier a proposta é aprovada');
ok($err(fn()=>$DC->addGuarantor(req('/x',['nome'=>'Outro Fiador'],$ag1),$p5['id']))===409,'depois da decisão os fiadores não se alteram');
$cd=data($DC->clientDocuments(req('/x',[],$ag1),7));
ok(count($cd['documentos'])===1 && $cd['documentos'][0]['tipo']==='bi','ficha do mutuário lista os seus documentos');
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'cobertura_garantias_pct'],['valor'=>'250','updated_at'=>now(),'created_at'=>now()]);
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'fiador_a_partir_de'],['valor'=>'100','updated_at'=>now(),'created_at'=>now()]);
\App\Support\CompanySettings::forget();
$chk=\App\Support\CreditDossier::checklist($db->table('cis_credit_proposals')->where('id',$p5['id'])->first());
ok(collect($chk['itens'])->firstWhere('chave','fiador')['ok']===true && collect($chk['itens'])->firstWhere('chave','cobertura')['ok']===false,'regras de fiador e cobertura mínima avaliadas');
$db->table('cis_company_settings')->whereIn('chave',['cobertura_garantias_pct','fiador_a_partir_de'])->delete();\App\Support\CompanySettings::forget();
ok($err(fn()=>$DC->remove(req('/x',[],$ag2),$up['id']))===403,'outro agente não retira o documento');
data($DC->remove(req('/x',[],$ag1),$up['id']));
ok($db->table('cis_documents')->where('id',$up['id'])->value('deleted_by')==$ag1->id && $err(fn()=>$DC->download(req('/x',[],$admin),$up['id']))===404,'documento retirado fica registado e deixa de abrir');

echo "== Mutuários ao nível do GEMIC: empresa, fotografia, lista negra e avalista registado\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_26_000250_cis_client_registry.php','--force'=>true]);
\App\Support\ClientRegistry::reset();
ok(\App\Support\ClientRegistry::ready() && Schema::hasColumn('cis_guarantors','guarantor_client_id'),'perfil, lista negra e ligação do avalista instalados');
$RC=new \App\Http\Controllers\Api\ClientRegistryController();
$pf=data($RC->show(req('/x',[],$admin),7));
ok($pf['instalado'] && $pf['tipo']==='singular' && $pf['lista_negra']===null && $pf['pode_lista_negra']===true,'ficha: pessoa singular por omissão, sem lista negra');
ok(status(fn()=>$RC->update(req('/x',['tipo'=>'colectiva','nuit'=>'400123456'],$admin),7))===422,'empresa exige representante legal');
ok(status(fn()=>$RC->update(req('/x',['tipo'=>'colectiva','nuit'=>'12345','representante_nome'=>'João Cossa'],$admin),7))===422,'NUIT tem 9 algarismos');
$pf=data($RC->update(req('/x',['tipo'=>'colectiva','nuit'=>'400123456','forma_juridica'=>'Lda','actividade'=>'Comércio','representante_nome'=>'João Cossa','representante_cargo'=>'Sócio-gerente'],$admin),7));
ok($pf['tipo']==='colectiva' && $pf['empresa']['nuit']==='400123456' && $pf['empresa']['forma_juridica']==='Lda','mutuário passa a pessoa colectiva com dados da empresa');
data($RC->update(req('/x',['tipo'=>'singular'],$admin),7));
// Novo mutuário pessoa colectiva (cadastro)
$_ENV['CIS_CLIENTES_EDIT_APPROVED']='true';$_SERVER['CIS_CLIENTES_EDIT_APPROVED']='true';putenv('CIS_CLIENTES_EDIT_APPROVED=true');
config(['database.connections.mysql_editor'=>config('database.connections.'.config('database.default'))]);DB::purge('mysql_editor');
$CC2=new \App\Http\Controllers\Api\ClientCreateController();
$firm=['dados'=>['Nome Completo'=>'Agro Boane Lda','Cidade'=>'Boane'],'tipo'=>'colectiva','empresa'=>['nuit'=>'400999888','forma_juridica'=>'Lda','representante_nome'=>'Rui Bila']];
ok(status(fn()=>$CC2->store(req('/x',array_replace_recursive($firm,['empresa'=>['nuit'=>'']]),$admin)))===422,'nova empresa exige NUIT');
$nf=$CC2->store(req('/x',$firm,$admin));$nfId=(int)(data($nf)['id']??0);
ok($nf->getStatusCode()===201 && $db->table('cis_client_profile')->where('client_id',$nfId)->value('tipo')==='colectiva','nova ficha de pessoa colectiva registada com o perfil da empresa');
$dupF=$CC2->store(req('/x',array_replace_recursive($firm,['dados'=>['Nome Completo'=>'Outra Firma']]),$admin));
ok($dupF->getStatusCode()===409,'NUIT repetido é recusado');
// Fotografia
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$ph=Request::create('/x','POST',[],[],['foto'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('eu.png',$png)]);$ph->setUserResolver(static fn()=>$ag1);
ok($RC->uploadPhoto($ph,7)->getStatusCode()===201 && $RC->photo(req('/x',[],$admin),7)->getContent()===$png && data($RC->show(req('/x',[],$admin),7))['tem_foto']===true,'fotografia da ficha gravada e servida');
$bad=Request::create('/x','POST',[],[],['foto'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('x.pdf',"%PDF-1.4\n%%EOF\n")]);$bad->setUserResolver(static fn()=>$ag1);
ok(status(fn()=>$RC->uploadPhoto($bad,7))===422,'a fotografia tem de ser imagem');
// Lista negra
ok(status(fn()=>$RC->blacklist(req('/x',['motivo'=>'Abandonou o crédito'],$ag1),9901))===403,'agente não mexe na lista negra');
ok(status(fn()=>$RC->blacklist(req('/x',['motivo'=>'x'],$admin),9901))===422,'lista negra exige motivo');
$bl=data($RC->blacklist(req('/x',['motivo'=>'Abandonou o crédito e não atende'],$admin),9901));
ok($bl['lista_negra']['motivo']==='Abandonou o crédito e não atende' && status(fn()=>$RC->blacklist(req('/x',['motivo'=>'Outra vez aqui'],$admin),9901))===409,'mutuário adicionado à lista negra (uma só vez)');
$ix=data($RC->index(req('/x',[],$ag1)));
ok(collect($ix['data'])->pluck('client_id')->contains(9901),'central de risco lista o mutuário para todos');
ok(status(fn()=>$PC->store(req('/x',array_replace($prop,['client_id'=>9901]),$admin)))===422,'não se cria proposta para mutuário na lista negra');
$p6=data($PC->store(req('/x',array_replace($prop,['montante'=>140]),$ag1)));
$DC=new \App\Http\Controllers\Api\CreditDossierController();
ok(status(fn()=>$DC->addGuarantor(req('/x',['nome'=>'Eu Mesmo','mutuario_id'=>7],$ag1),$p6['id']))===422,'o mutuário não é avalista de si próprio');
ok(status(fn()=>$DC->addGuarantor(req('/x',['nome'=>'Bloqueado','mutuario_id'=>9901],$ag1),$p6['id']))===422,'avalista na lista negra é recusado');
ok(status(fn()=>$RC->unblacklist(req('/x',['motivo'=>'ok'],$admin),9901))===422,'saída da lista exige motivo');
data($RC->unblacklist(req('/x',['motivo'=>'Dívida regularizada em acordo'],$admin),9901));
$gd=data($DC->addGuarantor(req('/x',['nome'=>'Avalista','relacao'=>'Vizinho','mutuario_id'=>9901],$ag1),$p6['id']));
$gg=collect($gd['fiadores'])->firstWhere('mutuario_id',9901);
ok($gg && $gg['nome']==='Mutuário Sem Contratos' && $gg['relacao']==='Vizinho','avalista escolhido entre os mutuários (dados vêm da ficha)');
ok(status(fn()=>$DC->addGuarantor(req('/x',['nome'=>'Avalista','mutuario_id'=>9901],$ag1),$p6['id']))===422,'o mesmo avalista não entra duas vezes');
ok(collect(data($RC->show(req('/x',[],$admin),9901))['avalista_em'])->pluck('id')->contains($p6['id']),'a ficha do avalista mostra as propostas em que é avalista');
$PC->submit(req('/x',[],$ag1),$p6['id']);
data($RC->blacklist(req('/x',['motivo'=>'Fraude detectada'],$admin),9901));
$msg='';try{$PC->decide(req('/x',['decisao'=>'aprovar'],$admin),$p6['id']);}catch(HttpExceptionInterface $e){$msg=$e->getMessage();}
ok(str_contains($msg,'lista negra'),'não se aprova com um avalista na lista negra');
ok(data($PC->decide(req('/x',['decisao'=>'rejeitar','notas'=>'Avalista bloqueado'],$admin),$p6['id']))['estado']==='rejeitada','mas pode rejeitar-se');
$hist=data($RC->show(req('/x',[],$admin),9901))['historico_lista_negra'];
ok(count($hist)===2 && $hist[1]['removed_motivo']==='Dívida regularizada em acordo','histórico guarda entradas e saídas da lista negra');
$sr=Request::create('/x?q=Mutu','GET');$sr->setUserResolver(static fn()=>$admin);
$found=collect(data($RC->search($sr))['data']);
ok($found->firstWhere('id',9901)['lista_negra']===true,'pesquisa de avalista marca quem está na lista negra');

echo "\nMora por tratamento e periodicidade\n";
$lcDue=\Carbon\CarbonImmutable::parse('2026-01-31');
$lcPaid=\Carbon\CarbonImmutable::parse('2026-02-15');
$calcLate=static fn(array $rule,bool $adjust=false)=>\App\Services\LateCharge::calculate(
    $rule,$lcDue,$lcPaid,100000,120000,0,0,false,$adjust
);
$examples=[
    'fixo_unico'=>[['valor'=>'20.00'],2000],
    'fixo_periodico'=>[['valor'=>'20','periodicidade'=>'semanal','carencia'=>2],2000],
    'percentual_unico'=>[['taxa'=>'5','base'=>'capital'],5000],
    'percentual_periodico'=>[['taxa'=>'2','base'=>'saldo','periodicidade'=>'semanal','periodos_iniciados'=>true],7200],
    'escaloes'=>[['escaloes'=>[['dias'=>8,'valor'=>'35'],['dias'=>1,'valor'=>'10']]],3500],
    'misto'=>[['valor'=>'10','componente'=>'fixo','valor_recorrente'=>'2','periodicidade'=>'diaria','tecto'=>'35'],3500],
];
$simulator=new \App\Http\Controllers\Api\GenericSimulatorController();
$simParams=['tipo_calculo'=>'multa','montante'=>'1200.00','capital'=>'1000.00','vencimento'=>'2026-01-31','data_referencia'=>'2026-02-15'];
foreach ($examples as $type=>[$rule,$expected]) {
    $simulation=data($simulator->preview(req('/x',$simParams+['mora'=>['tipo'=>$type]+$rule],$user)));
    ok($simulation['mora_detalhe']['cobrado_centimos']===$expected
        && $simulation['total_estimado']===\App\Services\CashAccountLedger::amount(120000+$expected)
        && $simulation['movimento_registado']===false,'simulador de mora '.$type);
    ok($calcLate(['tipo'=>$type]+$rule)['cobrado_centimos']===$expected,'mora '.$type);
}
$tiers=['tipo'=>'escaloes','escaloes'=>[['dias'=>8,'valor'=>'35'],['dias'=>1,'valor'=>'10']],'acumular'=>true];
ok($calcLate($tiers)['cobrado_centimos']===4500,'escalões acumulam apenas os limiares atingidos');
ok($calcLate(['tipo'=>'fixo_periodico','valor'=>'10','periodicidade'=>'mensal','periodos_iniciados'=>true])['periodos']===1,
    'mês de calendário iniciado entre 31 de Janeiro e 15 de Fevereiro');
ok($calcLate(['tipo'=>'fixo_periodico','valor'=>'10','periodicidade'=>'quinzenal','carencia'=>2,'contar_desde_vencimento'=>true])['periodos']===1,
    'carência ultrapassada pode contar desde o vencimento');
ok($calcLate(['tipo'=>'fixo_periodico','valor'=>'10','periodicidade'=>'personalizada','dias_periodo'=>4])['periodos']===3,
    'periodicidade personalizada considera os períodos completos');
$dispensa=['tipo'=>'fixo_unico','valor'=>'20','decisao'=>'dispensar','motivo'=>'Autorização de ensaio'];
ok($err(fn()=>$calcLate($dispensa))===403 && $calcLate($dispensa,true)['calculado_centimos']===2000
    && $calcLate($dispensa,true)['cobrado_centimos']===0,'dispensa preserva o cálculo e exige permissão');
$reduzir=['tipo'=>'fixo_unico','valor'=>'20','decisao'=>'reduzir','valor_reduzido'=>'10','motivo'=>'Autorização de ensaio'];
ok($calcLate($reduzir,true)['cobrado_centimos']===1000,'redução cobra apenas o valor autorizado');

echo "== Conduta do cadastro ligada à lista negra\n";
$db->statement("ALTER TABLE Clientes ADD COLUMN Conduta VARCHAR(50) NULL");
$db->table('Clientes')->where('ID',7)->update(['Conduta'=>'Lista Negra']);
\App\Support\ClientRegistry::reset();
ok(\App\Support\ClientRegistry::blacklisted(7)!==null && collect(data($RC->index(req('/x',[],$ag1)))['data'])->pluck('client_id')->contains(7),'Conduta «Lista Negra» no cadastro conta como lista negra');
ok(status(fn()=>$PC->store(req('/x',array_replace($prop,['montante'=>120]),$ag1)))===422,'e bloqueia novas propostas');
$cv=data($RC->unblacklist(req('/x',['motivo'=>'Conduta revista pela gestão'],$admin),7));
ok($cv['lista_negra']===null && $db->table('Clientes')->where('ID',7)->value('Conduta')==='Padrão' && $cv['conduta']==='Padrão','voltar a Padrão muda a Conduta no cadastro e guarda o histórico');
$cb=data($RC->blacklist(req('/x',['motivo'=>'Fugiu com a mercadoria'],$admin),7));
ok($cb['lista_negra']!==null && $db->table('Clientes')->where('ID',7)->value('Conduta')==='Lista Negra','pôr na lista negra escreve «Lista Negra» na Conduta');
data($RC->unblacklist(req('/x',['motivo'=>'Acordo de pagamento cumprido'],$admin),7));
ok($db->table('Clientes')->where('ID',7)->value('Conduta')==='Padrão' && \App\Support\ClientRegistry::blacklisted(7)===null,'e volta a Padrão');

echo "== Incumprimento prolongado: lista negra automática com autorização\n";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate',['--path'=>'database/migrations/2026_11_26_000260_cis_blacklist_origin.php','--force'=>true]);
ok(Schema::hasColumn('cis_client_blacklist','origem') && Schema::hasColumn('cis_client_blacklist','dias_atraso'),'lista negra regista a origem e os dias de atraso');
$row=(array)$db->table('Clientes')->where('ID',7)->first();$row['ID']=9950;$row['Nome Completo']='Mutuário Muito Atrasado';$row['Conduta']='Padrão';
$db->table('Clientes')->insert($row);
$ct=(array)$db->table('Contractos')->orderBy('Ordem')->first();unset($ct['Ordem']);
$ct=array_replace($ct,['Nr do Contracto'=>'LD-0001','Nome do Cliente'=>'Mutuário Muito Atrasado','Nº de Cliente'=>9950,'Estado'=>'Pagamento Atrasado',
    'Data de Inicio'=>now('Africa/Maputo')->subDays(150)->toDateString(),'Data de Cobrança'=>now('Africa/Maputo')->subDays(120)->toDateString(),
    'Valor a Pagar'=>1000,'Valor de Juros'=>200]);
if(array_key_exists('Valor Pago',$ct))$ct['Valor Pago']=0;
// Colunas com ponto (ex.: «M. Pagamento»): INSERT directo com nomes entre crases.
$db->insert('INSERT INTO Contractos ('.implode(',',array_map(fn($k)=>'`'.$k.'`',array_keys($ct))).') VALUES ('.implode(',',array_fill(0,count($ct),'?')).')',array_values($ct));
\App\Support\Branches::reset();\App\Support\ClientRegistry::reset();
$ovr=function($u){$q=Request::create('/x','GET');$q->setUserResolver(static fn()=>$u);return data((new \App\Http\Controllers\Api\OverviewController())($q));};
$before=$ovr($admin);
$cd=data($RC->candidates(req('/x',[],$admin)));
$c9=collect($cd['candidatos'])->firstWhere('id',9950);
ok($cd['pode_decidir'] && $cd['dias']===90 && $c9 && $c9['dias']>=120 && abs($c9['valor_divida']-1000)<0.01,'ao entrar: mostra quem tem mais de 90 dias de atraso, com a dívida');
ok(data($RC->candidates(req('/x',[],$ag1)))['pode_decidir']===false,'só quem aprova propostas decide (autorização)');
$db->table('cis_company_settings')->updateOrInsert(['chave'=>'dias_lista_negra'],['valor'=>'200','updated_at'=>now(),'created_at'=>now()]);\App\Support\CompanySettings::forget();
ok(!collect(data($RC->candidates(req('/x',[],$admin)))['candidatos'])->pluck('id')->contains(9950),'o limite de dias é uma definição da empresa');
$db->table('cis_company_settings')->where('chave','dias_lista_negra')->delete();\App\Support\CompanySettings::forget();
ok(status(fn()=>$RC->autoBlacklist(req('/x',['clientes'=>[9950]],$ag1)))===403,'agente não transfere para a lista negra');
$tr=data($RC->autoBlacklist(req('/x',['clientes'=>[9950,9901]],$admin)));
$bl9=$db->table('cis_client_blacklist')->where('client_id',9950)->whereNull('removed_at')->first();
ok($tr['transferidos']===1 && $tr['ignorados']===1 && $bl9 && $bl9->origem==='automatica' && (int)$bl9->dias_atraso>=120 && str_contains($bl9->motivo,'Incumprimento prolongado'),'«Sim» transfere só quem passa do critério, com motivo de incumprimento prolongado');
ok($db->table('Clientes')->where('ID',9950)->value('Conduta')==='Lista Negra','a Conduta do cadastro passa a «Lista Negra»');
$after=$ovr($admin);
ok(abs(((float)$before['carteira']['valor_a_receber']-(float)$after['carteira']['valor_a_receber'])-1000)<0.01 && $after['lista_negra']['mutuarios']>=1 && (float)$after['lista_negra']['valor']>=1000,'o crédito sai da carteira corrente e aparece em «Crédito em lista negra»');
$li=data($RC->index(req('/x',[],$ag1)));
$r9=collect($li['data'])->firstWhere('client_id',9950);
ok($r9 && $r9['origem']==='automatica' && abs($r9['divida']-1000)<0.01 && $li['credito_lista_negra']['valor']>=1000,'a lista negra mostra a dívida de cada um e o total');
ok(!collect(data($RC->candidates(req('/x',[],$admin)))['candidatos'])->pluck('id')->contains(9950),'depois de transferido já não volta a ser proposto');

echo "== Avisos: três separadores (lista negra, atraso, a vencer) com dias\n";
$nv=function(string $k,int $pg=1)use($admin){$q=Request::create('/x','GET',['tipo'=>$k,'page'=>$pg]);$q->setUserResolver(static fn()=>$admin);return data((new \App\Http\Controllers\Api\ContractListController())->notices($q));};
// O contrato copiado do ensaio vinha «Finalizado»: um contrato em curso é que gera avisos.
DB::connection('mysql_legacy')->table('Contractos')->where('Nr do Contracto','LD-0001')->update(['Estado do Contrato'=>'Activo']);
$nb=$nv('lista_negra');
$ln=null;for($pg=1;$pg<=$nb['paginas']&&!$ln;$pg++)$ln=collect($nv('lista_negra',$pg)['linhas'])->firstWhere('contrato','LD-0001');
ok($nb['lista_negra']>=1 && $ln && (int)$ln['dias']>=120 && $nb['resumo']['lista_negra']['dias_max']>=120 && $nb['resumo']['lista_negra']['valor']>=1000,'separador «Lista negra» com os dias de atraso de cada prestação');
$na=$nv('atraso');
ok(!collect($na['linhas'])->pluck('contrato')->contains('LD-0001') && $na['lista_negra']===$nb['lista_negra'],'a lista negra não entra nos pagamentos em atraso');
ok(collect($na['linhas'])->every(fn($r)=>(int)$r['dias']>0),'em atraso: dias positivos (tantos dias em atraso)');
$nc=$nv('cobranca');
ok(collect($nc['linhas'])->every(fn($r)=>(int)$r['dias']<=0 && -(int)$r['dias']<=$nc['antecedencia']),'a vencer: faltam tantos dias (0 = vence hoje)');

} catch (\Throwable $e) {
    fwrite(STDERR,"ERRO NÃO ESPERADO: ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(2);
}
echo "\nResultado: $pass OK, $fail falha(s)\n";
exit($fail===0?0:1);
