<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agências (branches). Tabelas próprias do CIS; não altera Contractos, Extracto nem as contas
 * da Caixa: contratos e contas ligam-se às agências por tabelas de correspondência.
 * Contratos sem correspondência pertencem à agência principal (Sede).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_branches')) {
            Schema::create('cis_branches', function (Blueprint $t) {
                $t->id();
                $t->string('codigo', 12)->unique();
                $t->string('nome', 120);
                $t->string('provincia', 60)->nullable();
                $t->string('cidade', 100)->nullable();
                $t->string('morada', 255)->nullable();
                $t->string('telefone', 30)->nullable();
                $t->string('email', 190)->nullable();
                $t->unsignedBigInteger('responsavel_id')->nullable();
                $t->boolean('principal')->default(false);
                $t->boolean('activo')->default(true);
                $t->timestamps();
            });
        }
        if (!Schema::hasColumn('users', 'branch_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
        }
        if (!Schema::hasTable('cis_contract_branch')) {
            Schema::create('cis_contract_branch', function (Blueprint $t) {
                $t->string('contrato', 50)->primary();
                $t->unsignedBigInteger('branch_id')->index();
                $t->unsignedBigInteger('assigned_by')->nullable();
                $t->timestamp('assigned_at')->useCurrent();
            });
        }
        if (!Schema::hasTable('cis_cash_account_branch')) {
            Schema::create('cis_cash_account_branch', function (Blueprint $t) {
                $t->unsignedBigInteger('account_id')->primary();
                $t->unsignedBigInteger('branch_id')->index();
            });
        }
        if (!DB::table('cis_branches')->exists()) {
            DB::table('cis_branches')->insert(['codigo' => 'SEDE', 'nome' => 'Sede', 'principal' => true, 'activo' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_cash_account_branch');
        Schema::dropIfExists('cis_contract_branch');
        Schema::dropIfExists('cis_branches');
    }
};
