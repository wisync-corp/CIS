<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('client_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('nome_completo', 155);
            $table->string('contacto', 30)->nullable();
            $table->string('email', 155)->nullable();
            $table->string('bairro', 155)->nullable();
            $table->string('cidade', 155)->nullable();
            $table->string('provincia', 155)->nullable();
            $table->string('estado')->default('rascunho');
            $table->timestamps();
            $table->index(['created_by', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('client_drafts'); }
};
