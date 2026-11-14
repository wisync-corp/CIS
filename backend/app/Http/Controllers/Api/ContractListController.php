<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractListController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['sometimes','string','max:155']]);
        $q = trim($filters['q'] ?? '');
        $builder = DB::connection('mysql_legacy')->table('Contractos')
            ->selectRaw('`Nr do Contracto` AS numero')
            ->selectRaw('MIN(`Nome do Cliente`) AS cliente')
            ->selectRaw('MIN(`Nº de Cliente`) AS cliente_id')
            ->selectRaw('COUNT(*) AS linhas_prestacoes')
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
        return $builder->orderBy('Nr do Contracto')->paginate(20)->withQueryString();
    }
}
