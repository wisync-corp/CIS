<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
class ClientController extends Controller {
 private const FIELDS=['nome_completo','numero_bi','nacionalidade','contacto','email','bairro','cidade','provincia','rua','numero_casa','numero_quarteirao','estado_civil','naturalidade','data_emissao_bi','conduta'];
 private function allowed(): void {Gate::authorize('cis.consultar.todos');}
 private function validateData(Request $request): array {
  return $request->validate([
   'nome_completo'=>['required','string','min:3','max:155'],
   'numero_bi'=>['nullable','string','max:50'],'nacionalidade'=>['nullable','string','max:100'],
   'contacto'=>['nullable','string','max:30'],'email'=>['nullable','email','max:155'],
   'bairro'=>['nullable','string','max:155'],'cidade'=>['nullable','string','max:155'],
   'provincia'=>['nullable','string','max:155'],'rua'=>['nullable','string','max:155'],
   'numero_casa'=>['nullable','string','max:50'],'numero_quarteirao'=>['nullable','string','max:50'],
   'estado_civil'=>['nullable','string','max:70'],'naturalidade'=>['nullable','string','max:100'],
   'data_emissao_bi'=>['nullable','date_format:Y-m-d'],'conduta'=>['nullable','string','max:100'],
  ]);
 }
 public function index(Request $request) {
  $this->allowed();
  $input=$request->validate(['q'=>['nullable','string','max:155']]);
  $query=DB::connection('sqlite')->table('clients');
  // Administradores activos consultam todos os clientes locais; outros perfis mantêm escopo próprio.
  if ($request->user()->role !== 'administrador') {
   $query->where('created_by',$request->user()->id);
  }
  if(!empty($input['q'])) { $term=str_replace(['%','_'],['\\%','\\_'],trim($input['q']));$query->where('nome_completo','like','%'.$term.'%'); }
  return $query->orderByDesc('id')->paginate(20);
 }
 public function store(Request $request) {
  $this->allowed();$data=$this->validateData($request);
  $data['created_by']=$request->user()->id;$data['created_at']=now();$data['updated_at']=now();
  $id=DB::connection('sqlite')->table('clients')->insertGetId($data);
  return response()->json(['id'=>$id,'message'=>'Cliente registado no CIS local.'],201);
 }
 public function update(Request $request,int $id) {
  $this->allowed();$data=$this->validateData($request);$data['updated_at']=now();
  $updated=DB::connection('sqlite')->table('clients')->where('id',$id)->where('created_by',$request->user()->id)->update($data);
  abort_if(!$updated,404,'Cliente não encontrado.');
  return response()->json(['message'=>'Cliente actualizado.']);
 }
}
