<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinancialReferenceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            503, 'Parametrizações disponíveis apenas na pré-visualização privada.');

        if (!Schema::hasTable('cis_tabela_juros') || !Schema::hasTable('cis_tipos_movimento')) {
            return response()->json([
                'message'=>'As duas tabelas de parametrização ainda não foram criadas no MySQL.',
            ], 503)->header('Cache-Control', 'no-store, private');
        }

        return response()->json([
            'juros' => DB::table('cis_tabela_juros')
                ->orderBy('limite_inferior')->orderBy('periodo_referencia')
                ->get(['limite_inferior', 'limite_superior', 'taxa', 'periodo_referencia']),
            'movimentos' => DB::table('cis_tipos_movimento')
                ->orderBy('id')->get(['tipo_movimento', 'descricao']),
            'unidade_periodo_confirmada' => false,
            'consulta_apenas' => true,
        ])->header('Cache-Control', 'no-store, private');
    }
}
