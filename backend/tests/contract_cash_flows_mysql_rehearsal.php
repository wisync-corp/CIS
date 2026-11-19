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
foreach (['cis_contractos_historico','Contractos','Extracto','Clientes','cis_tabela_juros','cis_cash_accounts','users'] as $t) {
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
 `Estado do Contrato`) ON `cis_ci`.`Contractos` TO 'cis_contract_writer'@'%';
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
$r=data($col->collect(req('/x',['cobrar_multa'=>true,'valor_recebido'=>'2880.00','conta_id'=>$banco,
    'idempotency_key'=>'44444444-4444-4444-8444-444444444444']+$pay,$user),'C-0101',$late));
ok($r['registado'] && $r['saldo_conta_actual']==='22880.00','entrada na conta BIM-001 escolhida');

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
$err=function(callable $f){ try { $f(); return null; } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return $e->getStatusCode(); }
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
$gestor=clone $user;$gestor->role='gestor';
ok($err(fn()=>$col->collect(req('/x',$bp([]),$gestor),'C-0120',$bd))===403,'gestor não pode alterar a data de pagamento');
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
ok($ecv['total_linhas']===$openV->count() && collect($ecv['linhas'])->every(fn($l)=>$l['estado']==='Vencida'),'clientes: filtro Vencidas');
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

} catch (\Throwable $e) {
    fwrite(STDERR,"ERRO NÃO ESPERADO: ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(2);
}
echo "\nResultado: $pass OK, $fail falha(s)\n";
exit($fail===0?0:1);
