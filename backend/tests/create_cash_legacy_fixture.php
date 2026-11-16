<?php
// Somente CI SQLite descartável. Nunca executar numa base real.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
if (DB::getDriverName() !== 'sqlite') throw new RuntimeException('Fixture proibida fora de SQLite.');
if (Schema::hasTable('Extracto')) throw new RuntimeException('Extracto preexistente: fixture recusada.');
Schema::create('Extracto', function(Blueprint $t) {
  $t->id('Nr');
  $t->string('Tipo de Movimento',30);
  $t->string('Contracto')->nullable();
  $t->unsignedInteger('Parcela')->nullable();
  $t->string('usuario')->nullable();
  $t->decimal('Valor',18,2);
  $t->string('Descrição');
  $t->date('Data da Operação');
});
echo "OK: fixture Extracto vazia para CI SQLite.\n";
