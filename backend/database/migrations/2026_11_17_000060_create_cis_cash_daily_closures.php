<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecho diário da Caixa Padrão: registo de conferência, NÃO movimento.
 * NÃO mexe no Extracto nem nas contas. Não substitui reconciliação bancária.
 * Um fecho por conta e data; preservação de discrepâncias e comprovativo.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_cash_accounts')
            || !Schema::hasTable('Extracto')
            || !Schema::hasColumn('Extracto','account_id')
            || !Schema::hasColumn('Extracto','operation_key')) {
            throw new RuntimeException('Base de Caixa incompatível. A migração de fecho foi bloqueada.');
        }
        if (Schema::hasTable('cis_cash_daily_closures')) {
            throw new RuntimeException('Fechos já existem. Não sobrescrever ou alterar automaticamente.');
        }
        Schema::create('cis_cash_daily_closures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->date('closing_date');
            $t->char('currency',3)->default('MZN');
            $t->decimal('opening_amount',18,2);
            $t->decimal('entries_amount',18,2);
            $t->decimal('exits_amount',18,2);
            $t->decimal('expected_balance',18,2);
            $t->decimal('observed_balance',18,2);
            $t->decimal('difference_amount',18,2);
            $t->unsignedBigInteger('movement_count')->default(0);
            $t->unsignedBigInteger('ledger_max_nr');
            $t->string('verification_method',32); // contagem_numerario, extracto_bancario, comprovativo_carteira
            $t->string('evidence_reference',180);
            $t->string('difference_reason',255)->nullable();
            $t->unsignedBigInteger('closed_by');
            $t->timestamp('closed_at');
            $t->timestamps();
            $t->unique(['account_id','closing_date'],'cis_cash_close_account_date_uq');
            $t->index(['closing_date','closed_by'],'cis_cash_close_date_actor_idx');
        });
    }

    public function down(): void
    {
        // Fechos são documentos financeiros de controlo; reversão destrutiva não autorizada.
        throw new RuntimeException('Não eliminar histórico de fechos automaticamente.');
    }
};
