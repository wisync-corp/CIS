<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractClientLookupController extends Controller
{
    /**
     * Lista apenas ID, número cadastral, nome e cidade.
     * A consulta está protegida pelo middleware privado de Clientes e não modifica nada.
     */
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['sometimes','string','max:155']]);
        $term = trim($filters['q'] ?? '');

        $builder = DB::connection('mysql_legacy')->table('Clientes')
            ->select(['ID','Numeração','Nome Completo','Cidade']);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $builder->where(function ($query) use ($term,$like) {
                $query->where('Nome Completo','like',$like);
                if (ctype_digit($term) && strlen($term)<11) {
                    $query->orWhere('ID',(int) $term);
                }
            });
        }

        return response()->json($builder->orderBy('Nome Completo')->orderBy('ID')
            ->limit(30)->get())->header('Cache-Control','no-store, private');
    }
}
