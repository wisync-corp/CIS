<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parametrização editável nas Definições: grupo/activo/protegido nos tipos de movimento
 * e registo das alterações (tabela de preços e tipos). Só tabelas cis_*.
 */
return new class extends Migration {
    private const GRUPOS = [
        'Receita de Juros'=>'Receita operacional','Expansão da Carteira'=>'Entrada não operacional','Junta'=>'Entrada não operacional',
        'Crédito Terceiros'=>'Entrada não operacional','Desembolso de Empréstimo'=>'Operação de crédito','Aumento de Crédito'=>'Operação de crédito',
        'Salários'=>'Despesa operacional','Retirada de M-pesa'=>'Saída não operacional','Ajuste'=>'Saída não operacional','Retirada Pessoal'=>'Saída não operacional',
    ];
    private const PROTEGIDOS = ['Receita de Juros','Desembolso de Empréstimo','Aumento de Crédito'];

    public function up(): void
    {
        if (Schema::hasTable('cis_tipos_movimento')) {
            Schema::table('cis_tipos_movimento', function (Blueprint $t) {
                if (!Schema::hasColumn('cis_tipos_movimento', 'grupo')) $t->string('grupo', 40)->nullable();
                if (!Schema::hasColumn('cis_tipos_movimento', 'activo')) $t->boolean('activo')->default(true);
                if (!Schema::hasColumn('cis_tipos_movimento', 'protegido')) $t->boolean('protegido')->default(false);
            });
            foreach (self::GRUPOS as $d => $g) DB::table('cis_tipos_movimento')->where('descricao', $d)->whereNull('grupo')->update(['grupo' => $g]);
            DB::table('cis_tipos_movimento')->whereIn('descricao', self::PROTEGIDOS)->update(['protegido' => true]);
        }
        if (!Schema::hasTable('cis_parametrizacao_log')) {
            Schema::create('cis_parametrizacao_log', function (Blueprint $t) {
                $t->id();
                $t->string('tabela', 40);
                $t->string('accao', 40);
                $t->json('antes')->nullable();
                $t->json('depois')->nullable();
                $t->string('por', 120)->nullable();
                $t->timestamp('em')->useCurrent();
                $t->index(['tabela', 'em']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_parametrizacao_log');
    }
};
