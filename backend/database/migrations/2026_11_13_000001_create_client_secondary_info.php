<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cis_client_secondary_info', function (Blueprint $t) {
            $t->unsignedBigInteger('client_id')->primary();
            $t->string('ponto_referencia', 255)->nullable();
            $t->string('local_trabalho_actividade', 180)->nullable();
            $t->string('fonte_rendimento_principal', 180)->nullable();
            $t->string('contacto_alternativo', 40)->nullable();
            $t->string('pessoa_referencia_nome', 180)->nullable();
            $t->string('pessoa_referencia_contacto', 40)->nullable();
            $t->string('melhor_horario_contacto', 100)->nullable();
            $t->unsignedBigInteger('updated_by');
            $t->timestamp('updated_at');
            $t->index('updated_by');
            // client_id é referência ao cadastro antigo, sem chave estrangeira
            // porque não conhecemos ainda os índices/motor da tabela legada.
        });
        Schema::create('cis_client_secondary_audit', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('actor_user_id');
            $t->json('fields_changed');
            $t->timestamp('changed_at');
            $t->index(['client_id','changed_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('cis_client_secondary_audit');
        Schema::dropIfExists('cis_client_secondary_info');
    }
};
