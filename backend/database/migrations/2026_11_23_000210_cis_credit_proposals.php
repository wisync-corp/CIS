<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propostas de crédito com aprovação.
 * rascunho → submetida → aprovada | rejeitada | devolvida (→ submetida) ; aprovada → desembolsada | expirada ; cancelada.
 * cis_credit_proposal_events: registo imutável de cada passo (quem, quando, notas).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_credit_proposals')) {
            Schema::create('cis_credit_proposals', function (Blueprint $t) {
                $t->id();
                $t->string('numero', 30)->unique();
                $t->unsignedBigInteger('client_id')->index();
                $t->string('client_name', 160);
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->string('status', 20)->default('rascunho')->index();
                // Pedido
                $t->unsignedInteger('montante');
                $t->string('tipo_juro', 20);
                $t->string('estrategia', 90);
                $t->unsignedSmallInteger('periodo_dias');
                $t->string('prazo', 20);
                $t->string('modalidade', 20);
                $t->decimal('taxa', 8, 4)->nullable();
                $t->string('finalidade', 255);
                // Análise
                $t->decimal('rendimento_mensal', 14, 2)->default(0);
                $t->decimal('despesas_mensais', 14, 2)->default(0);
                $t->decimal('outras_dividas_mensais', 14, 2)->default(0);
                $t->string('actividade', 160)->nullable();
                $t->text('garantias')->nullable();
                $t->text('observacoes')->nullable();
                $t->json('analise')->nullable(); // fotografia do cálculo (prestação, esforço, score) na submissão
                // Decisão
                $t->unsignedInteger('montante_aprovado')->nullable();
                $t->decimal('taxa_aprovada', 8, 4)->nullable();
                $t->text('decisao_notas')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->date('valida_ate')->nullable();
                $t->string('contract_number', 50)->nullable()->index();
                $t->unsignedBigInteger('created_by')->index();
                $t->timestamp('submitted_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('cis_credit_proposal_events')) {
            Schema::create('cis_credit_proposal_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('proposal_id')->index();
                $t->string('accao', 30);
                $t->string('de', 20)->nullable();
                $t->string('para', 20)->nullable();
                $t->text('notas')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_credit_proposal_events');
        Schema::dropIfExists('cis_credit_proposals');
    }
};
