<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ContractProfileController extends Controller
{
    // Os nomes seguintes vêm do Contractos.frm e da tabela original.
    // Algumas versões não contêm Tipo de Juro; a API não deve inventar o campo.
    private const CONTRACT_FIELDS = [
        'Nr do Contracto', 'Nome do Cliente', 'Nº de Cliente',
        'Quantia Creditada', 'M. Pagamento', 'Prazo/Duração',
        '% de Juro', 'Tipo de Juro', '% de Multa',
        'Forma de Pagamento', 'Disponibilidade', 'Data de Inicio',
        'Cidade', 'Data Final', 'Nº de Prestações', 'Codigo Cliente',
        'Estado do Contrato', 'usuario'
    ];
    private const PAYMENT_FIELDS = [
        'Ordem', 'Nº da Parcela', 'Data de Cobrança',
        'Data de Pagamento', 'Capital', 'Amortização', 'Valor de Juros',
        'Valor de Multa', 'Valor a Pagar', 'Valor Pago', 'Estado',
        'Situação de Pagamento', 'Dias', 'Intervalo'
    ];

    public function show(Request $request, string $numero)
    {
        abort_if(trim($numero) === '' || mb_strlen($numero) > 155, 422, 'Número de contrato inválido.');
        $conn = DB::connection('mysql_legacy');
        $schema = Schema::connection('mysql_legacy')->getColumnListing('Contractos');
        $available = array_fill_keys($schema, true);
        foreach (['Nr do Contracto', 'Nº da Parcela', 'Ordem'] as $required) {
            abort_unless(isset($available[$required]), 503, 'Esquema legado de contratos incompatível.');
        }
        $columns = array_values(array_filter(array_unique(array_merge(
            self::CONTRACT_FIELDS, self::PAYMENT_FIELDS
        )), fn ($name) => isset($available[$name])));
        // Cada linha legada representa uma prestação; não somar quantias de contrato
        // repetidas nem recalcular saldo a partir de campos não reconciliados.
        $query = $conn->table('Contractos')->where('Nr do Contracto', $numero);
        $total = (clone $query)->count();
        abort_if($total === 0, 404, 'Contrato não encontrado.');
        abort_if($total > 500, 409, 'Contrato excede o limite de consulta segura.');
        $rows = $query->orderBy('Nº da Parcela')->orderBy('Ordem')->get($columns);
        $first = (array)$rows->first();
        // Um contrato nunca deve juntar mutuários diferentes no mesmo número.
        if (isset($available['Nº de Cliente'])) {
            $ids = $rows->pluck('Nº de Cliente')->filter(fn ($id) => $id !== null)->unique();
            abort_if($ids->count() > 1, 409, 'Contrato com identificadores de cliente divergentes.');
        }
        $detail = [];
        foreach (self::CONTRACT_FIELDS as $field) {
            if (array_key_exists($field, $first)) $detail[$field] = $first[$field];
        }
        $payments = $rows->map(function ($row) {
            $line = (array)$row;
            return array_intersect_key($line, array_flip(self::PAYMENT_FIELDS));
        })->values();
        return response()->json([
            'contract' => $detail, 'installments' => $payments,
            'installment_rows' => $total,
            'notice' => 'Valores originais do MySQL; não foram recalculados ou reconciliados.',
        ])->header('Cache-Control', 'no-store, private');
    }
}
