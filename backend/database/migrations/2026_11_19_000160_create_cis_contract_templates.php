<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Modelos de contrato editáveis (com variáveis {…}); inclui o modelo de empréstimo da empresa. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_contract_templates')) {
            Schema::create('cis_contract_templates', function (Blueprint $t) {
                $t->id();
                $t->string('nome', 120)->unique();
                $t->longText('corpo');
                $t->boolean('padrao')->default(false);
                $t->string('updated_by', 120)->nullable();
                $t->timestamps();
            });
        }
        if (!DB::table('cis_contract_templates')->exists()) {
            DB::table('cis_contract_templates')->insert(['nome' => 'Contrato de empréstimo', 'padrao' => true,
                'corpo' => file_get_contents(__DIR__ . '/../../resources/contratos/modelo-emprestimo.txt'),
                'updated_by' => 'sistema', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_contract_templates');
    }
};
