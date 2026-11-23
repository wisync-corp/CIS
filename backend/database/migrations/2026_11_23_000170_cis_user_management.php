<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestão de utilizadores no CIS: função «financeiro», último acesso, troca obrigatória de
 * palavra-passe temporária e registo de auditoria das alterações a contas.
 * Idempotente: só acrescenta o que falta. Não toca em tabelas legadas.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('administrador','gestor','financeiro','agente','cobrador') NOT NULL");
        }
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'last_login_at')) $t->timestamp('last_login_at')->nullable();
            if (!Schema::hasColumn('users', 'must_change_password')) $t->boolean('must_change_password')->default(false);
            if (!Schema::hasColumn('users', 'telefone')) $t->string('telefone', 30)->nullable();
            if (!Schema::hasColumn('users', 'created_by')) $t->unsignedBigInteger('created_by')->nullable();
        });
        if (!Schema::hasTable('cis_user_audit')) {
            Schema::create('cis_user_audit', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->string('accao', 40);
                $t->text('detalhe')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_user_audit');
    }
};
