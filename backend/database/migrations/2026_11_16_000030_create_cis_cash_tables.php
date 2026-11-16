<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo híbrido aprovado:
 * Extracto = fonte única do movimento financeiro;
 * cis_cash_accounts = contas e saldos iniciais homologados;
 * cis_cash_links = associação 1:1 entre cada linha do Extracto e uma conta.
 *
 * NÃO migra Extracto/Contractos e NÃO imputa movimentos legados.
 * Instalar somente depois de backup e revisão na cópia de ensaio.
 */
return new class extends Migration {
    public function up(): void {
        // Falha segura se houver instalação prévia da versão independente.
        if (Schema::hasTable('cis_cash_movements') || Schema::hasTable('cis_cash_links')
            || Schema::hasTable('cis_cash_accounts')) {
            throw new RuntimeException('Esquema anterior ou parcial detectado: migração híbrida bloqueada. Rever manualmente sem perda de dados.');
        }
        if (!Schema::hasTable('Extracto')) {
            // CI SQLite cria Extracto somente como fixture vazia isolada.
            throw new RuntimeException('A tabela financeira Extracto deve existir antes de instalar a associação.');
        }
        Schema::create('cis_cash_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code',40)->unique();
            $t->string('name',150);
            $t->enum('type',['banco','numerario','carteira_movel']);
            $t->string('identifier',120)->nullable();
            $t->char('currency',3)->default('MZN');
            $t->enum('status',['em_preparacao','pendente_validacao','activa','suspensa'])->default('em_preparacao');
            $t->decimal('opening_balance',18,2)->nullable();
            $t->dateTime('cut_at')->nullable();
            $t->string('evidence_reference',255)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('validated_by')->nullable();
            $t->dateTime('validated_at')->nullable();
            $t->timestamps();
            $t->index(['status','currency']);
        });
        Schema::create('cis_cash_links', function (Blueprint $t) {
            $t->id();
            // O movimento financeiro existe apenas em Extracto.Nr.
            // Não criar uma segunda coluna de valor ou tipo de movimento.
            $t->unsignedBigInteger('extracto_nr')->unique();
            $t->foreignId('account_id')->constrained('cis_cash_accounts')->restrictOnDelete();
            $t->uuid('operation_id')->nullable()->index(); // transf.: duas pernas
            $t->string('idempotency_key',100)->unique();
            $t->enum('nature',['desembolso','recebimento','receita_geral','despesa_geral','transferencia','ajuste','estorno']);
            $t->string('category',90)->nullable();
            $t->string('evidence_reference',255)->nullable();
            $t->enum('confirmation',['manual','automatica'])->default('manual');
            $t->dateTime('confirmed_at');
            $t->unsignedBigInteger('confirmed_by');
            $t->timestamps();
            $t->index(['account_id','extracto_nr']);
            $t->index(['nature','confirmed_at']);
            // Sem FK física para Extracto na primeira etapa: a tabela é legado
            // e o tipo exacto de Nr / motor tem de ser validado na VPS.
            // Antes de confirmar um link, a API deve validar Extracto.Nr
            // e gravar os dois registos na MESMA transacção MySQL/InnoDB.
        });
    }
    public function down(): void {
        if (Schema::hasTable('cis_cash_links') && DB::table('cis_cash_links')->exists()) {
            throw new RuntimeException('Associacoes financeiras existentes: rollback destrutivo recusado.');
        }
        if (Schema::hasTable('cis_cash_accounts') && DB::table('cis_cash_accounts')->exists()) {
            throw new RuntimeException('Contas cadastradas: rollback destrutivo recusado.');
        }
        Schema::dropIfExists('cis_cash_links');
        Schema::dropIfExists('cis_cash_accounts');
    }
};
