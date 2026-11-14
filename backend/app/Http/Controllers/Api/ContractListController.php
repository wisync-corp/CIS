<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractListController extends Controller
{
    // O VBA original utiliza "Em Progresso" e "Finalizado" em Estado do Contrato.
    // O campo Estado da prestação NÃO pode determinar automaticamente o estado do contrato.
    private const SORT_COLUMNS = [
        'numero' => 'numero',
        'cliente' => 'cliente',
        'cliente_id' => 'cliente_id',
        'linhas_prestacoes' => 'linhas_prestacoes',
        'estado' => 'estado',
    ];

    private function groupedContracts()
    {
        // A agregação só afirma um estado quando TODAS as prestações do
        // contrato partilham o mesmo Estado do Contrato, incluindo nulos.
        // Valores divergentes são mostrados e não classificados arbitrariamente.
        return DB::connection('mysql_legacy')->table('Contractos')
            ->selectRaw('`Nr do Contracto` AS numero')
            ->selectRaw('MIN(`Nome do Cliente`) AS cliente')
            ->selectRaw('MIN(`Nº de Cliente`) AS cliente_id')
            ->selectRaw('COUNT(*) AS linhas_prestacoes')
            ->selectRaw("CASE
                WHEN COUNT(DISTINCT COALESCE(NULLIF(TRIM(`Estado do Contrato`), ''), '__CIS_VAZIO__')) > 1
                  THEN 'Estados divergentes'
                ELSE COALESCE(MAX(NULLIF(TRIM(`Estado do Contrato`), '')), 'Sem estado')
                END AS estado")
            ->groupBy('Nr do Contracto');
    }

    public function states()
    {
        // Estados de contrato agregados (não estados individuais de parcelas).
        $rows = DB::connection('mysql_legacy')
            ->query()->fromSub($this->groupedContracts(), 'contratos')
            ->select('estado')->distinct()->orderBy('estado')->pluck('estado');

        return response()->json(['states' => $rows])
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:155'],
            'estado' => ['sometimes', 'string', 'max:155'],
            'sort' => ['sometimes', 'string', 'in:numero,cliente,cliente_id,linhas_prestacoes,estado'],
            'direction' => ['sometimes', 'string', 'in:asc,desc'],
        ]);

        $q = trim($filters['q'] ?? '');
        $state = trim($filters['estado'] ?? '');
        $sort = self::SORT_COLUMNS[$filters['sort'] ?? 'numero'];
        $direction = $filters['direction'] ?? 'asc';
        $builder = $this->groupedContracts();

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $builder->where(function ($query) use ($like, $q) {
                $query->where('Nr do Contracto', 'like', $like)
                    ->orWhere('Nome do Cliente', 'like', $like);
                if (ctype_digit($q) && strlen($q) < 11) {
                    $query->orWhere('Nº de Cliente', (int) $q);
                }
            });
        }

        // WHERE na subconsulta agregada garante que um contrato Finalizado
        // NÃO apareça como Em Progresso por causa de uma só parcela antiga.
        $results = DB::connection('mysql_legacy')->query()
            ->fromSub($builder, 'contratos');
        if ($state !== '') {
            $results->where('estado', $state);
        }

        return $results->orderBy($sort, $direction)
            ->orderBy('numero', 'asc')
            ->paginate(20)->withQueryString();
    }
}
