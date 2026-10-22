<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientDraftController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('cis.consultar.todos');
        return DB::connection('sqlite')->table('client_drafts')
            ->where('created_by', $request->user()->id)
            ->select('id','nome_completo','contacto','email','bairro','cidade','provincia','estado','created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function store(Request $request)
    {
        Gate::authorize('cis.consultar.todos');
        // Apenas rascunhos num SQLite local. Não escreve na base MySQL legada.
        $input = $request->validate([
            'nome_completo' => ['required','string','min:3','max:155'],
            'contacto' => ['nullable','string','max:30'],
            'email' => ['nullable','email','max:155'],
            'bairro' => ['nullable','string','max:155'],
            'cidade' => ['nullable','string','max:155'],
            'provincia' => ['nullable','string','max:155'],
        ]);
        $input['created_by'] = $request->user()->id;
        $input['estado'] = 'rascunho';
        $input['created_at'] = now();
        $input['updated_at'] = now();
        $id = DB::connection('sqlite')->table('client_drafts')->insertGetId($input);
        return response()->json(['id'=>$id,'estado'=>'rascunho','mensagem'=>'Rascunho guardado localmente. Não foi enviado ao MySQL Cloud.'], 201);
    }
}
