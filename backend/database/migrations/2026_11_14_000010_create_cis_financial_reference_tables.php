<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabelas de parametrização CIS; NÃO alterar Contractos, Extracto ou Clientes.
        // 'periodo_referencia' preserva o número original da coluna Period.
        // Não assumir dias ou meses até à confirmação da unidade.
        Schema::create('cis_tabela_juros', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('limite_inferior');
            $table->unsignedInteger('limite_superior');
            $table->decimal('taxa', 8, 2);
            $table->unsignedSmallInteger('periodo_referencia');
            $table->unique(['limite_inferior', 'limite_superior', 'periodo_referencia'], 'cis_juros_intervalo_periodo_unique');
        });

        Schema::create('cis_tipos_movimento', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo_movimento', ['Entradas', 'Saídas']);
            $table->string('descricao', 150);
            $table->unique(['tipo_movimento', 'descricao'], 'cis_movimento_tipo_descricao_unique');
        });

        // Transcrição literal dos 16 registos visíveis na Table4 fornecida pelo utilizador.
        DB::table('cis_tabela_juros')->insert([
            ['limite_inferior'=>0, 'limite_superior'=>3000, 'taxa'=>20, 'periodo_referencia'=>15],
            ['limite_inferior'=>0, 'limite_superior'=>3000, 'taxa'=>25, 'periodo_referencia'=>30],
            ['limite_inferior'=>3001, 'limite_superior'=>5000, 'taxa'=>20, 'periodo_referencia'=>15],
            ['limite_inferior'=>3001, 'limite_superior'=>5000, 'taxa'=>25, 'periodo_referencia'=>30],
            ['limite_inferior'=>5001, 'limite_superior'=>8000, 'taxa'=>25, 'periodo_referencia'=>15],
            ['limite_inferior'=>5001, 'limite_superior'=>8000, 'taxa'=>30, 'periodo_referencia'=>30],
            ['limite_inferior'=>8001, 'limite_superior'=>10000, 'taxa'=>25, 'periodo_referencia'=>15],
            ['limite_inferior'=>8001, 'limite_superior'=>10000, 'taxa'=>30, 'periodo_referencia'=>30],
            ['limite_inferior'=>10001, 'limite_superior'=>12000, 'taxa'=>30, 'periodo_referencia'=>15],
            ['limite_inferior'=>10001, 'limite_superior'=>12000, 'taxa'=>30, 'periodo_referencia'=>30],
            ['limite_inferior'=>12001, 'limite_superior'=>15000, 'taxa'=>30, 'periodo_referencia'=>15],
            ['limite_inferior'=>12001, 'limite_superior'=>15000, 'taxa'=>30, 'periodo_referencia'=>30],
            ['limite_inferior'=>15001, 'limite_superior'=>30000, 'taxa'=>30, 'periodo_referencia'=>15],
            ['limite_inferior'=>15001, 'limite_superior'=>30000, 'taxa'=>30, 'periodo_referencia'=>30],
            ['limite_inferior'=>30001, 'limite_superior'=>50000, 'taxa'=>40, 'periodo_referencia'=>15],
            ['limite_inferior'=>50001, 'limite_superior'=>100000, 'taxa'=>30, 'periodo_referencia'=>15],
        ]);

        // Tabela de movimentos (10 linhas) da mesma captura; parâmetros de referência.
        // Não lançar movimentos no Extracto automaticamente.
        DB::table('cis_tipos_movimento')->insert([
            ['tipo_movimento'=>'Entradas', 'descricao'=>'Receita de Juros'],
            ['tipo_movimento'=>'Entradas', 'descricao'=>'Expansão da Carteira'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Desembolso de Empréstimo'],
            ['tipo_movimento'=>'Entradas', 'descricao'=>'Junta'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Aumento de Crédito'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Retirada de M-pesa'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Salários'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Ajuste'],
            ['tipo_movimento'=>'Saídas', 'descricao'=>'Retirada Pessoal'],
            ['tipo_movimento'=>'Entradas', 'descricao'=>'Crédito Terceiros'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cis_tipos_movimento');
        Schema::dropIfExists('cis_tabela_juros');
    }
};
