<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encargos do crédito: categoria da finalidade nas propostas (as taxas e regras vivem em cis_company_settings). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cis_credit_proposals') && !Schema::hasColumn('cis_credit_proposals', 'finalidade_categoria')) {
            Schema::table('cis_credit_proposals', function (Blueprint $t) {
                $t->string('finalidade_categoria', 60)->nullable()->after('finalidade');
                $t->index('finalidade_categoria');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cis_credit_proposals', 'finalidade_categoria')) {
            Schema::table('cis_credit_proposals', function (Blueprint $t) { $t->dropIndex(['finalidade_categoria']); $t->dropColumn('finalidade_categoria'); });
        }
    }
};
