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
        'capital' => 'capital',
        'prestacao_a_pagar' => 'prestacao_a_pagar',
        'situacao' => 'situacao',
    ];
    // Situação de pagamento do contrato (a partir das PRESTAÇÕES, não do Estado do Contrato):
    //  Pagamento Atrasado — há prestação por pagar marcada como atrasada ou com vencimento passado;
    //  Pendente           — há prestações por pagar, todas dentro do prazo;
    //  Finalizado         — não há prestações por pagar.
    public const SITUATIONS = ['Pendente','Pagamento Atrasado','Finalizado'];

    private function groupedContracts()
    {
        $today = now('Africa/Maputo')->toDateString();
        $open = "`Estado` IN ('Pendente','Pagamento Atrasado')";
        $late = "($open AND (`Estado`='Pagamento Atrasado' OR `Data de Cobrança` < ?))";
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
            // Capital do contrato = quantia creditada (montante emprestado).
            ->selectRaw('CAST(ROUND(MAX(`Quantia Creditada`),2) AS DECIMAL(18,2)) AS capital')
            // Prestação que deve ser paga = a próxima por pagar (vencimento mais antigo).
            ->selectRaw("CAST(SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN $open THEN ROUND(`Valor a Pagar`,2) END
                ORDER BY `Data de Cobrança`,`Nº da Parcela` SEPARATOR '|'),'|',1) AS DECIMAL(18,2)) AS prestacao_a_pagar")
            ->selectRaw("SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN $open THEN DATE_FORMAT(`Data de Cobrança`,'%Y-%m-%d') END
                ORDER BY `Data de Cobrança`,`Nº da Parcela` SEPARATOR '|'),'|',1) AS proximo_vencimento")
            ->selectRaw("CASE WHEN SUM(CASE WHEN $late THEN 1 ELSE 0 END)>0 THEN 'Pagamento Atrasado'
                WHEN SUM(CASE WHEN $open THEN 1 ELSE 0 END)>0 THEN 'Pendente'
                ELSE 'Finalizado' END AS situacao",[$today])
            ->groupBy('Nr do Contracto');
    }

    public function summary()
    {
        // Reutiliza EXACTAMENTE a mesma regra de estado da listagem.
        // Conta contratos distintos, e nunca as 679 linhas de prestações.
        $rows = DB::connection('mysql_legacy')->query()
            ->fromSub($this->groupedContracts(), 'contratos')
            ->select('estado')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('estado')
            ->orderBy('estado')
            ->get();
        $situations = DB::connection('mysql_legacy')->query()
            ->fromSub($this->groupedContracts(), 'contratos')
            ->select('situacao')->selectRaw('COUNT(*) AS total')
            ->groupBy('situacao')->pluck('total','situacao');

        $byState = $rows->map(fn ($row) => [
            'estado' => $row->estado,
            'total' => (int) $row->total,
        ])->values();

        return response()->json([
            'total' => (int) $byState->sum('total'),
            'por_estado' => $byState,
            'por_situacao' => collect(self::SITUATIONS)->map(fn ($s) => [
                'situacao' => $s, 'total' => (int) ($situations[$s] ?? 0),
            ])->values(),
        ])->header('Cache-Control', 'no-store, private');
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
            'situacao' => ['sometimes', 'string', 'in:Pendente,Pagamento Atrasado,Finalizado'],
            'sort' => ['sometimes', 'string', 'in:numero,cliente,cliente_id,linhas_prestacoes,estado,capital,prestacao_a_pagar,situacao'],
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
                $renamed = self::renamedLike($like);
                if ($renamed) $query->orWhereIn('Nr do Contracto', $renamed);
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
        if (($filters['situacao'] ?? '') !== '') {
            $results->where('situacao', $filters['situacao']);
        }

        return $results->orderBy($sort, $direction)
            ->orderBy('numero', 'asc')
            ->paginate(20)->withQueryString();
    }

    /** Contratos cujo número antigo (antes da numeração anual) corresponde à pesquisa. */
    private static function renamedLike(string $like): array
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('cis_contract_numbers')) return [];
            return \Illuminate\Support\Facades\DB::table('cis_contract_numbers')->where('numero_original', 'like', $like)->limit(50)->pluck('numero')->unique()->values()->all();
        } catch (\Throwable) { return []; }
    }
}
