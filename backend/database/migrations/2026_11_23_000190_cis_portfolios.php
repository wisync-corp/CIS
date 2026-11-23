<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carteiras: responsável (gestor de carteira) por contrato e agente que cadastrou cada mutuário.
 * Tabelas próprias do CIS; não altera Contractos nem Clientes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_contract_owner')) {
            Schema::create('cis_contract_owner', function (Blueprint $t) {
                $t->string('contrato', 50)->primary();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('assigned_by')->nullable();
                $t->timestamp('assigned_at')->useCurrent();
            });
        }
        if (!Schema::hasTable('cis_client_owner')) {
            Schema::create('cis_client_owner', function (Blueprint $t) {
                $t->unsignedBigInteger('client_id')->primary();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('assigned_by')->nullable();
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_client_owner');
        Schema::dropIfExists('cis_contract_owner');
    }
};
