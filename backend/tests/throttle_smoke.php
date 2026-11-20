<?php
// Limite de pedidos: contador por utilizador E por rota; leituras com margem maior.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default'=>'array']);
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
function ok(bool $c,string $m){ if(!$c){fwrite(STDERR,"FALHOU: $m\n");exit(1);} echo "ok - $m\n"; }
$mw=app(\App\Http\Middleware\CisThrottle::class);
$user=new class { public function getAuthIdentifier(){ return 7; } };
$hit=function(string $method,string $uri,int $max) use ($mw,$user){
    $r=Request::create('/api/'.$uri,$method);
    $route=new Route([$method],$uri,fn()=>null); $route->bind($r); $r->setRouteResolver(fn()=>$route);
    $r->setUserResolver(fn()=>$user);
    try { $mw->handle($r,fn()=>response('ok'),(string)$max,'1'); return 200; }
    catch (\Illuminate\Http\Exceptions\ThrottleRequestsException $e) { return 429; }
};
// 100 leituras numa rota «throttle:15,1» não afectam outra rota «throttle:15,1».
for($i=0;$i<100;$i++) $hit('GET','cis/a',60);
ok($hit('GET','cis/b',15)===200,'contadores separados por rota');
// Leituras: mínimo de 120 por minuto mesmo com throttle:15,1.
$codes=[];for($i=0;$i<120;$i++) $codes[]=$hit('GET','cis/c',15);
ok(!in_array(429,$codes,true) && $hit('GET','cis/c',15)===429,'leituras: 120 por minuto por rota');
// Gravações mantêm o limite definido.
$codes=[];for($i=0;$i<5;$i++) $codes[]=$hit('POST','cis/d',5);
ok(!in_array(429,$codes,true) && $hit('POST','cis/d',5)===429,'gravações: limite da rota mantido');
// Mensagem em português.
$r=Request::create('/api/x','GET');
$h=app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render($r,new \Illuminate\Http\Exceptions\ThrottleRequestsException('Too Many Attempts.',null,['Retry-After'=>30]));
ok($h->getStatusCode()===429 && str_contains($h->getContent(),'Aguarde 30 s'),'mensagem 429 em português');
