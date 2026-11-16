<?php
// Apenas para CI SQLite descartável; simula esquema errado vazio da versão antiga.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
if(DB::getDriverName()!=='sqlite') throw new RuntimeException('Fixture proibida fora de SQLite.');
if(Schema::hasTable('cis_cash_accounts') || Schema::hasTable('cis_cash_movements')) throw new RuntimeException('Fixture nao limpa.');
Schema::create('cis_cash_accounts',function(Blueprint $t) {
  $t->id();
  $t->string('code',40)->unique(); $t->string('name',150);
  $t->string('type'); $t->string('identifier')->nullable(); $t->string('currency',3);
  $t->string('status'); $t->decimal('opening_balance',18,2)->nullable(); $t->dateTime('cut_at')->nullable();
  $t->string('evidence_reference')->nullable(); $t->unsignedBigInteger('created_by');
  $t->unsignedBigInteger('validated_by')->nullable(); $t->dateTime('validated_at')->nullable(); $t->timestamps();
});
Schema::create('cis_cash_movements',function(Blueprint $t) {
  $t->id();$t->unsignedBigInteger('account_id');$t->string('type');$t->decimal('amount',18,2);
  $t->timestamps();
});
DB::table('migrations')->insert(['migration'=>'2026_11_16_000030_create_cis_cash_tables','batch'=>2]);
echo "CI: esquema legado vazio criado.\n";
