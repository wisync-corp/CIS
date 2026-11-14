<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractListController extends Controller
{
    // Apenas colunas e agregações conhecidas podem ser usadas em ORDER BY.
    private const SORT_COLUMNS = [
        'numero' => 'numero',
        'cliente' => 'cliente',
        'cliente_id' => 'cliente_id',
        'linhas_prestacoes' => 'linhas_prestacoes',
        'estado' => 'estado',
    ];

    public function states()
    {
        // Opções vêm de dados reais; nunca criar uma lista fictícia de estados.
        $values = DB::connection('mysql_legacy')->table('Contractos')
            ->selectRaw('TRIM(`Estado do Contrato`) AS estado')
            ->whereNotNull('Estado do Contrato')
            ->whereRaw('TRIM(`Estado do Contrato`) <> ?', [''])
            ->distinct()->orderBy('estado')->pluck('estado');

        return response()->json(['states' => $values])
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

        $builder = DB::connection('mysql_legacy')->table('Contractos')
            ->selectRaw('`Nr do Contracto` AS numero')
            ->selectRaw('MIN(`Nome do Cliente`) AS cliente')
            ->selectRaw('MIN(`Nº de Cliente`) AS cliente_id')
            ->selectRaw('COUNT(*) AS linhas_prestacoes')
            // Contratos com estados diferentes nas respectivas prestações são
            // identificados sem atribuir silenciosamente um estado arbitrário.
            ->selectRaw("CASE
                WHEN COUNT(DISTINCT COALESCE(NULLIF(TRIM(`Estado do Contrato`), ''), '__CIS_SEM_ESTADO__')) > 1
                THEN 'Estados divergentes'
                ELSE MAX(NULLIF(TRIM(`Estado do Contrato`), ''))
                END AS estado")
            ->groupBy('Nr do Contracto');

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $builder->where(function ($query) use ($like, $q) {
                $query->where('Nr do Contracto', 'like', $like)
                    ->orWhere('Nome do Cliente', 'like', $like);
                if (ctype_digit($q) && strlen($q) < 11) {
                    $query->orWhere('Nº de Cliente', (int)$q);
                }
            });
        }

        if ($state !== '') {
            // HAVING (e não WHERE) mantém TODAS as linhas/prestações na contagem.
            // Um contrato aparece quando pelo menos uma das suas linhas possui o estado.
            $builder->havingRaw('SUM(CASE WHEN TRIM(`Estado do Contrato`) = ? THEN 1 ELSE 0 END) > 0', [$state]);
        }

        return $builder->orderBy($sort, $direction)
            ->orderBy('numero', 'asc')->paginate(20)->withQueryString();
    }
}
