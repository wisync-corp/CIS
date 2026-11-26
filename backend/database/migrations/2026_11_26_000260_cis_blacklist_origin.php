<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lista negra: origem (manual ou automática por incumprimento prolongado) e dias de atraso na entrada. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_client_blacklist')) return;
        Schema::table('cis_client_blacklist', function (Blueprint $t) {
            if (!Schema::hasColumn('cis_client_blacklist', 'origem')) $t->string('origem', 12)->default('manual')->after('motivo');
            if (!Schema::hasColumn('cis_client_blacklist', 'dias_atraso')) $t->unsignedInteger('dias_atraso')->nullable()->after('origem');
            if (!Schema::hasColumn('cis_client_blacklist', 'valor_em_divida')) $t->decimal('valor_em_divida', 14, 2)->nullable()->after('dias_atraso');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cis_client_blacklist')) return;
        Schema::table('cis_client_blacklist', function (Blueprint $t) {
            foreach (['valor_em_divida', 'dias_atraso', 'origem'] as $c) if (Schema::hasColumn('cis_client_blacklist', $c)) $t->dropColumn($c);
        });
    }
};
