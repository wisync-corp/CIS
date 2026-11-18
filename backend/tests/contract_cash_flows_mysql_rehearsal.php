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
$ov=data((new \App\Http\Controllers\Api\OverviewController())());
// Pagas: C-0100 (1150,50; juros 150,50) e C-0101 (2880; juros 800; multa 80).
ok($ov['recebimentos']['juros']==='950.50' && $ov['recebimentos']['multas']==='80.00','juros 950,50 e multas 80,00 recebidos');
ok($ov['recebimentos']['total_recebido']==='4030.50' && $ov['recebimentos']['capital_recuperado']==='3000.00','recebido 4030,50 = capital 3000 + juros + multa');
ok($ov['recebimentos']['juros_mes']==='950.50','juros do mês contam os pagamentos de hoje');
ok($ov['carteira']['prestacoes_vencidas']===0 && $ov['carteira']['contratos_activos']===3,'0 vencidas; 3 contratos por receber (C-0102, C-0103, novo)');
ok((float)$ov['carteira']['capital_em_carteira']>=5000,'capital em carteira inclui o novo desembolso de 3000');
ok($ov['caixa']['disponivel']===true && $ov['caixa']['saldo_total']==='21130.50','saldo da Caixa = 1250,50 + 19880,00');
ok($ov['caixa']['entradas_mes']==='4030.50' && $ov['caixa']['desembolsos_mes']==='3000.00','entradas 4030,50 e desembolsos 3000,00 no mês');
$db->table('Contractos')->where('Ordem',$dupe)->update(['Data de Cobrança'=>now($tz)->subDays(3)->toDateString()]);
$ov=data((new \App\Http\Controllers\Api\OverviewController())());
ok($ov['carteira']['prestacoes_vencidas']===1 && $ov['carteira']['valor_vencido']==='1150.50' && $ov['carteira']['contratos_em_atraso']===1,'prestação com vencimento passado conta como vencida (1150,50)');

echo "== Lista de contratos: capital, prestação a pagar e situação\n";
$list=new \App\Http\Controllers\Api\ContractListController();
$sum=data($list->summary());
$bySit=collect($sum['por_situacao'])->pluck('total','situacao');
ok($bySit['Finalizado']===2 && $bySit['Pagamento Atrasado']===1 && $bySit['Pendente']===2,'separadores: 2 finalizados, 1 atrasado, 2 pendentes');
$late=collect($list->index(Request::create('/x','GET',['situacao'=>'Pagamento Atrasado']))->items());
ok($late->count()===1 && $late[0]->numero==='C-0102','filtro «Pagamento atrasado» devolve só C-0102');
ok((string)$late[0]->prestacao_a_pagar==='1150.50' && $late[0]->proximo_vencimento===now($tz)->subDays(3)->toDateString()
    && (string)$late[0]->capital==='1000.00','C-0102: capital 1000,00, prestação 1150,50 e vencimento correcto');
$fin=collect($list->index(Request::create('/x','GET',['situacao'=>'Finalizado']))->items());
ok($fin->count()===2 && $fin->every(fn($r)=>$r->prestacao_a_pagar===null),'finalizados sem prestação a pagar');

echo "== Leitura do histórico pela API\n";
$hh=data($col->history(tap(Request::create('/x','GET'),fn($r)=>$r->setUserResolver(fn()=>$user)),'C-0100'));
ok($hh['instalado']===true && count($hh['data'])===1 && $hh['data'][0]['antes']['Estado']==='Pendente','endpoint devolve antes/depois');

} catch (\Throwable $e) {
    fwrite(STDERR,"ERRO NÃO ESPERADO: ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(2);
}
echo "\nResultado: $pass OK, $fail falha(s)\n";
exit($fail===0?0:1);
