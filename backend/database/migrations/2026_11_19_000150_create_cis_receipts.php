<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Numeração anual dos recibos (REC-ANO-SEQUÊNCIA): cada recebimento recebe um número fixo. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cis_receipts')) return;
        Schema::create('cis_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('origem_chave', 40)->unique(); // E:<Nr do Extracto> ou P:<Ordem da prestação>
            $t->unsignedSmallInteger('ano');
            $t->unsignedInteger('sequencia');
            $t->string('numero', 40)->unique();
            $t->string('contrato_original', 50)->nullable();
            $t->date('data')->nullable();
            $t->decimal('valor', 18, 2)->default(0);
            $t->timestamps();
            $t->unique(['ano', 'sequencia'], 'cis_receipts_ano_seq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_receipts');
    }
};
