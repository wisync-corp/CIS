<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Schema novo: nao modificar os movimentos antigos em Extracto.
        Schema::create('cis_cash_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 150);
            $t->enum('type', ['banco','numerario','carteira_movel']);
            $t->string('identifier', 120)->nullable();
            $t->string('currency', 3)->default('MZN');
            $t->enum('status', ['em_preparacao','pendente_validacao','activa','suspensa'])->default('em_preparacao');
            $t->decimal('opening_balance', 18, 2)->nullable();
            $t->dateTime('cut_at')->nullable();
            $t->string('evidence_reference', 255)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('validated_by')->nullable();
            $t->dateTime('validated_at')->nullable();
            $t->timestamps();
            $t->index(['status','currency']);
        });
        Schema::create('cis_cash_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained('cis_cash_accounts')->restrictOnDelete();
            $t->enum('type',['entrada','saida','transferencia_entrada','transferencia_saida','estorno_entrada','estorno_saida']);
            $t->decimal('amount',18,2);
            $t->string('reference',110)->unique();
            $t->string('category',90);
            $t->string('description',500);
            $t->string('contract_number',155)->nullable();
            $t->uuid('transfer_group')->nullable()->index();
            $t->dateTime('effective_at');
            $t->enum('status',['confirmado'])->default('confirmado');
            $t->string('evidence_reference',255)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->index(['account_id','effective_at']);
            $t->index(['contract_number','type']);
        });
    }
    public function down(): void {
        // Nunca apagar movimentos financeiros por rollback de deploy.
        if (Schema::hasTable('cis_cash_movements') && \Illuminate\Support\Facades\DB::table('cis_cash_movements')->exists()) {
            throw new \RuntimeException('Existem movimentos financeiros: remocao da tabela recusada.');
        }
        if (Schema::hasTable('cis_cash_accounts') && \Illuminate\Support\Facades\DB::table('cis_cash_accounts')->exists()) {
            throw new \RuntimeException('Existem contas: remocao da tabela recusada.');
        }
        Schema::dropIfExists('cis_cash_movements');
        Schema::dropIfExists('cis_cash_accounts');
    }
};
