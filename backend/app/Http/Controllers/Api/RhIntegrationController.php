<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RhIntegrationController extends Controller
{
    private const EMPLOYEES_READ='colaboradores:ler';

    private function ready(): void
    {
        abort_unless(Schema::hasTable('cis_rh_integration_events'),503,'Instale as integrações de RH na VPS.');
    }

    private function admin(Request $r): void
    {
        Permissions::require($r->user(),'definicoes');
        Permissions::require($r->user(),'utilizadores');
        $this->ready();
    }

    public function index(Request $r)
    {
        $this->admin($r);
        $list=DB::table('cis_rh_integrations')->orderByDesc('id')->paginate(20,
            ['id','name','company','scope','created_at','expires_at','revoked_at']);
        $events=DB::table('cis_rh_integration_events as e')->join('cis_rh_integrations as i','i.id','=','e.integration_id')
            ->leftJoin('users as u','u.id','=','e.user_id')->orderByDesc('e.id')->limit(30)
            ->get(['e.id','i.name','i.company','e.operation','e.row_count','e.created_at','u.name as user']);
        $companies=DB::table('cis_rh_employees')->distinct()->orderBy('company')->pluck('company');
        return response()->json(['accounts'=>$list,'events'=>$events,'companies'=>$companies])
            ->header('Cache-Control','no-store, private');
    }

    public function issue(Request $r)
    {
        $this->admin($r);
        $d=$r->validate(['name'=>['required','string','max:120'],
            'company'=>['required','string','max:80','exists:cis_rh_employees,company'],
            'valid_days'=>['required','integer','between:1,365']]);
        $secret='cis_rh_'.bin2hex(random_bytes(32));
        $id=DB::transaction(function () use ($r,$d,$secret) {
            $now=now();
            $id=DB::table('cis_rh_integrations')->insertGetId([
                'name'=>$d['name'],'company'=>$d['company'],'scope'=>self::EMPLOYEES_READ,
                'token_hash'=>hash('sha256',$secret),'created_by'=>$r->user()->id,
                'created_at'=>$now,'expires_at'=>$now->copy()->addDays((int)$d['valid_days']),
            ]);
            $this->event($id,'criar',(int)$r->user()->id);
            return $id;
        });
        return response()->json(['id'=>$id,'token'=>$secret],201)->header('Cache-Control','no-store, private');
    }

    public function revoke(Request $r,int $id)
    {
        $this->admin($r);
        DB::transaction(function () use ($r,$id) {
            $row=DB::table('cis_rh_integrations')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row,404,'Integração não encontrada.');
            if ($row->revoked_at!==null) return;
            DB::table('cis_rh_integrations')->where('id',$id)->update([
                'revoked_by'=>$r->user()->id,'revoked_at'=>now()]);
            $this->event($id,'revogar',(int)$r->user()->id);
        });
        return response()->noContent();
    }

    public function employees(Request $r)
    {
        $this->ready();
        $token=$r->bearerToken();
        abort_unless(is_string($token)&&preg_match('/^cis_rh_[a-f0-9]{64}$/D',$token),401,'Credencial de RH inválida.');
        $result=DB::transaction(function () use ($r,$token) {
            $account=DB::table('cis_rh_integrations')->where('token_hash',hash('sha256',$token))
                ->whereNull('revoked_at')->where('expires_at','>',now())->lockForUpdate()->first();
            abort_unless($account,401,'Credencial de RH inválida ou expirada.');
            abort_unless($account->scope===self::EMPLOYEES_READ,403,'A conta não tem permissão para consultar colaboradores.');
            $d=$r->validate(['after'=>['nullable','integer','min:0'],'limit'=>['nullable','integer','between:1,100']]);
            $limit=(int)($d['limit']??50);
            $rows=DB::table('cis_rh_employees')->whereRaw('BINARY company = ?',[$account->company])->where('id','>',$d['after']??0)
                ->orderBy('id')->limit($limit+1)->get(['id','code','name','company','category','status']);
            $page=$rows->take($limit)->values();
            $this->event((int)$account->id,'ler_colaboradores',null,$page->count());
            return ['data'=>$page,'next'=>$rows->count()>$limit?(int)$page->last()->id:null];
        });
        return response()->json($result)->header('Cache-Control','no-store, private');
    }

    private function event(int $id,string $operation,?int $actor,?int $count=null): void
    {
        DB::table('cis_rh_integration_events')->insert(['integration_id'=>$id,'user_id'=>$actor,
            'operation'=>$operation,'row_count'=>$count,'created_at'=>now()]);
    }
}
