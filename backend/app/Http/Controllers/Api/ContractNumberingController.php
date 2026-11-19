<?php

namespace App\Http\Controllers\Api;

use App\Services\ContractNumbering as CN;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Pré-visualização da numeração anual dos contratos (Definições › Numeração dos contratos). */
class ContractNumberingController
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'administrador', 403, 'Reservado ao administrador.');
        $p = strtoupper(trim((string)$request->query('prefixo', CN::prefix())));
        $d = (int)$request->query('digitos', CN::digits());
        abort_unless(preg_match('/^[A-Z0-9]{1,10}$/', $p) && $d >= 3 && $d <= 6, 422, 'Prefixo (1 a 10 letras/números) ou dígitos (3 a 6) inválidos.');
        try {
            $plan = CN::plan(DB::connection('mysql_legacy'), $p, $d);
        } catch (\Illuminate\Database\QueryException $e) {
            abort(503, 'Não foi possível ler os contratos para a pré-visualização.');
        }
        $sample = []; $seen = [];
        foreach ($plan['linhas'] as $l) {
            $seen[$l['ano']] = ($seen[$l['ano']] ?? 0) + 1;
            if ($seen[$l['ano']] <= 3) $sample[] = ['actual' => $l['atual'], 'novo' => $l['novo'], 'desembolso' => $l['data']];
        }
        $installed = false;
        try { $installed = Schema::hasTable('cis_contract_numbers'); } catch (\Throwable) {}
        return response()->json([
            'activa' => CN::active(), 'instalado' => $installed, 'prefixo' => $p, 'digitos' => $d,
            'exemplo' => CN::format((int)now('Africa/Maputo')->year, 1, $p, $d),
            'prefixo_gravado' => CN::prefix(), 'digitos_gravados' => CN::digits(),
            'resumo' => CN::summary($plan), 'amostra' => $sample, 'sem_data' => $plan['sem_data'],
            'total' => count($plan['linhas']), 'a_alterar' => count(array_filter($plan['linhas'], fn($l) => $l['atual'] !== $l['novo'])),
        ]);
    }
}
