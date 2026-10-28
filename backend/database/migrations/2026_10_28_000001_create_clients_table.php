<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('clients', function(Blueprint $table){
   $table->id();$table->foreignId('created_by')->constrained('users')->restrictOnDelete();
   $table->string('nome_completo',155);$table->string('numero_bi',50)->nullable();
   $table->string('nacionalidade',100)->nullable();$table->string('contacto',30)->nullable();
   $table->string('email',155)->nullable();$table->string('bairro',155)->nullable();
   $table->string('cidade',155)->nullable();$table->string('provincia',155)->nullable();
   $table->string('rua',155)->nullable();$table->string('numero_casa',50)->nullable();
   $table->string('numero_quarteirao',50)->nullable();$table->string('estado_civil',70)->nullable();
   $table->string('naturalidade',100)->nullable();$table->date('data_emissao_bi')->nullable();
   $table->string('conduta',100)->nullable();$table->timestamps();
   $table->index(['created_by','nome_completo']);
  });
 }
 public function down(): void {Schema::dropIfExists('clients');}
};
