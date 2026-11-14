<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ContractOpeningReviewController extends Controller
{
    /**
     * Validação de abertura SOMENTE LEITURA. Não atribui número de contrato,
     * não gera parcelas reais, não lança desembolsos e não modifica Extracto.
     * A revisão exacta de Contractos.frm é obrigatória antes de autorizar escrita.
     */
    public function validateDraft(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(filter_var(env('CIS_CLIENTES_PREVIEW_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503);
        abort_unless(filter_var(env('CIS_CONTRACTS_READ_APPROVED', false), FILTER_VALIDATE_BOOLEAN), 503);

        $values = $request->validate([
            'cliente_id' => ['required', 'integer', 'min:1'],
            'montante' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'tipo_juro' => ['required','in:Fixo,Variável,Composto'],
            'periodo_dias' => ['nullable','integer','in:15,30'],
            'taxa_informada' => ['nullable','numeric','between:0,100'],
            'taxa_multa' => ['required','numeric','between:0,100'],
            'prazo_meses' => ['required','integer','in:1,2,3'],
            'modalidade' => ['required','in:Mensal,Uma Parcela'],
            'forma_pagamento' => ['required','string','max:100'],
            'disponibilidade' => ['required','string','max:100'],
            'data_inicio' => ['required','date_format:Y-m-d'],
        ]);

        $rows = DB::connection('mysql_legacy')->table('Clientes')
            ->where('ID', $values['cliente_id'])->limit(2)
            ->get(['ID','Numeração','Nome Completo','Cidade']);
        abort_if($rows->count() !== 1, 422, 'Cliente inexistente ou identificador duplicado.');

        $issues = [];
        $rate = null;
        if ($values['tipo_juro'] === 'Fixo') {
            if (!isset($values['periodo_dias'])) {
                $issues[] = 'Seleccionar o período em dias para consultar a tabela de juros.';
            } elseif (!Schema::hasTable('cis_tabela_juros')) {
                $issues[] = 'Tabela de juros ainda não activada no MySQL.';
            } else {
                $prices = DB::table('cis_tabela_juros')
                    ->where('limite_inferior','<=',$values['montante'])
                    ->where('limite_superior','>=',$values['montante'])
                    ->where('periodo_referencia',$values['periodo_dias'])
                    ->get(['taxa']);
                if ($prices->count() !== 1) {
                    $issues[] = 'Não existe uma taxa única para este montante e período.';
                } else {
                    $rate = (string) $prices[0]->taxa;
                    if (isset($values['taxa_informada']) &&
                        abs((float)$values['taxa_informada'] - (float)$rate) > 0.0001) {
                        $issues[] = 'A taxa submetida não corresponde à tabela de preços.';
                    }
                }
            }
        } else {
            $issues[] = 'Regras de juros variáveis/compostos ainda não homologadas com o VBA.';
        }
        if ($values['tipo_juro'] === 'Variável' && $values['modalidade'] !== 'Uma Parcela') {
            $issues[] = 'Modalidade incompatível com a regra de preparação para juro variável.';
        }
        if ($values['tipo_juro'] === 'Composto' && $values['modalidade'] !== 'Mensal') {
            $issues[] = 'Modalidade incompatível com a regra de preparação para juro composto.';
        }

        // Sem taxa nem valor financeiro calculado de forma inferida.
        return response()->json([
            'cadastro_confirmado' => true,
            'cliente' => [
                'id' => $rows[0]->ID,
                'numero' => $rows[0]->{'Numeração'},
                'nome' => $rows[0]->{'Nome Completo'},
            ],
            'taxa_tabela' => $rate,
            'problemas' => $issues,
            'dados_verificados' => count($issues) === 0,
            'autorizado_para_gravar' => false,
            'pendencias' => [
                'Rever o ficheiro Contractos.frm original e confrontar fórmulas de juros/amortização.',
                'Homologar a geração exacta de prestações.',
                'Conciliar saldo de caixa e movimentos de desembolso no Extracto.',
                'Definir numeração segura de contratos e permissões de escrita.',
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
