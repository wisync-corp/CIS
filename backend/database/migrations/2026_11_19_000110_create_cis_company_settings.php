<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Configurações da empresa (perfil, cobrança e multa, credit scoring, incentivos). Chave/valor; tabela nova do CIS. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cis_company_settings')) return;
        Schema::create('cis_company_settings', function (Blueprint $t) {
            $t->string('chave', 60)->primary();
            $t->longText('valor')->nullable();
            $t->string('updated_by', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_company_settings');
    }
};
