<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalogue = [
            'Entradas' => [
                'Receita operacional' => [
                    'Venda de bens', 'Prestação de serviços', 'Comissões recebidas',
                ],
                'Entrada não operacional' => [
                    'Aporte de capital', 'Financiamento recebido', 'Juros bancários recebidos',
                    'Venda de activos', 'Reembolsos recebidos',
                ],
            ],
            'Saídas' => [
                'Despesa operacional' => [
                    'Fornecedores', 'Rendas', 'Água e energia', 'Comunicações',
                    'Combustível', 'Transporte', 'Manutenção e reparações',
                    'Material de escritório', 'Seguros', 'Impostos e taxas', 'Serviços profissionais',
                ],
                'Investimento' => [
                    'Aquisição de equipamento', 'Aquisição de viaturas', 'Software e licenças',
                ],
                'Saída não operacional' => [
                    'Amortização de financiamento', 'Juros de financiamento',
                    'Comissões bancárias', 'Distribuição de dividendos', 'Reembolsos a clientes',
                ],
            ],
        ];

        $rows = [];
        foreach ($catalogue as $direction => $groups) {
            foreach ($groups as $group => $names) {
                foreach ($names as $name) {
                    $rows[] = [
                        'tipo_movimento' => $direction,
                        'descricao' => $name,
                        'grupo' => $group,
                        'activo' => true,
                        'protegido' => false,
                    ];
                }
            }
        }

        // Conserva a parametrização de cada instalação quando o tipo já existe.
        DB::table('cis_tipos_movimento')->upsert($rows, ['tipo_movimento', 'descricao'], ['tipo_movimento']);
    }

    public function down(): void
    {
        // Os tipos podem já estar em uso ou ter sido personalizados.
    }
};
