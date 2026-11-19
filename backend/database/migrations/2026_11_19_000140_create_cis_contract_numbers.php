<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração anual dos contratos: cada número que um contrato já teve (ex.: C-0451) aponta para o
 * número actual (ex.: CAPTA-2026-0001). Permite pesquisar pelo número antigo e ler o
 * histórico imutável gravado com o número antigo.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cis_contract_numbers')) return;
        Schema::create('cis_contract_numbers', function (Blueprint $t) {
            $t->id();
            $t->string('numero_original', 50)->unique();
            $t->string('numero', 50)->index();
            $t->unsignedSmallInteger('ano');
            $t->unsignedInteger('sequencia');
            $t->date('data_desembolso')->nullable();
            $t->string('renumerado_por', 120)->nullable();
            $t->timestamps();
            $t->index(['ano', 'sequencia'], 'cis_contract_numbers_ano_seq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_contract_numbers');
    }
};
