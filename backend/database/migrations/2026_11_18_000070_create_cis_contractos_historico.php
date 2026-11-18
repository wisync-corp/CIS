<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HISTÓRICO IMUTÁVEL DAS PRESTAÇÕES (Contractos).
 *
 * O VBA (ActualizarFinanceiroCobranca) sobrepõe Capital, Juros, Valor a Pagar,
 * % de Juro e Intervalo na mesma linha — renovação de juros e capitalização
 * apagam os valores anteriores. A partir daqui, TODA alteração financeira de uma
 * prestação feita pelo CIS grava, NA MESMA TRANSACÇÃO, a fotografia completa da
 * linha antes e depois, a operação, o utilizador, a conta e o movimento do Extracto.
 *
 * Só INSERT: o escritor não recebe UPDATE/DELETE e o instalador cria triggers
 * MySQL que recusam alteração ou eliminação (deploy/install-contract-history.sh).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('Contractos') || !Schema::hasTable('Extracto')) {
            throw new RuntimeException('Tabelas legadas ausentes. Histórico de prestações bloqueado.');
        }
        if (Schema::hasTable('cis_contractos_historico')) {
            throw new RuntimeException('Histórico já existe. Não recriar nem alterar automaticamente.');
        }
        Schema::create('cis_contractos_historico', function (Blueprint $t) {
            $t->id();
            $t->string('contract_number',40);
            $t->unsignedBigInteger('installment_ordem')->nullable();   // Contractos.Ordem
            $t->integer('installment_number')->nullable();             // Nº da Parcela
            // cobranca_total, desembolso, renovar_juros, capitalizar, actualizar_data,
            // prorrogar_15_30, pagamento_parcial, finalizar_contrato
            $t->string('operation',32);
            $t->json('before_snapshot')->nullable();   // linha completa antes (NULL em abertura)
            $t->json('after_snapshot')->nullable();    // linha completa depois
            $t->unsignedBigInteger('extracto_nr')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->decimal('amount',18,2)->nullable();
            $t->string('evidence_reference',120)->nullable();
            $t->string('reason',255)->nullable();
            $t->string('operation_key',120);
            $t->unsignedBigInteger('performed_by');
            $t->timestamp('performed_at');
            $t->unique(['operation_key','installment_ordem'],'cis_contr_hist_key_ordem_uq');
            $t->index(['contract_number','performed_at'],'cis_contr_hist_contract_idx');
            $t->index('installment_ordem','cis_contr_hist_ordem_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Histórico de prestações é permanente; reversão destrutiva não autorizada.');
    }
};
