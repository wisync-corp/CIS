<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CompanySettings as CS;
use App\Support\CreditCharges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET cis/encargos: encargos do crédito em vigor (para propostas, abertura, cobrança e simulador). */
final class CreditChargesController extends Controller
{
    public function __invoke(Request $r): JsonResponse
    {
        abort_unless($r->user(), 401);
        $rule = CreditCharges::companyLateRule();
        return response()->json([
            'taxa_operacao' => CS::num('taxa_operacao'), 'taxa_operacao_minima' => CS::num('taxa_operacao_minima'),
            'taxa_renovacao' => CS::num('taxa_renovacao'), 'encargos_renovacao' => CreditCharges::renewalTreatment(),
            'renovacao_atraso_max_dias' => CreditCharges::renewalLateLimit(),
            'finalidades' => CreditCharges::purposes(),
            'mora' => ['automatica' => $rule !== null, 'regra' => $rule, 'descricao' => CreditCharges::describeLateRule($rule)],
        ])->header('Cache-Control', 'no-store, private');
    }
}
