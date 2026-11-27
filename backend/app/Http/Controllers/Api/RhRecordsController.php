<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RhRecordsController extends Controller
{
    private const KINDS=['contratos','adendas','ferias','indemnizacoes','prestacoes'];
    private const MONEY=['required','numeric','min:0','max:9999999999','decimal:0,2'];

    private function gate(Request $r): void
    {
        Permissions::require($r->user(),'definicoes');
        abort_unless(Schema::hasTable('cis_rh_record_audit'),503,'Instale as tabelas de Recursos Humanos na VPS.');
    }

    public function index(Request $r)
    {
        $this->gate($r);
        $filters=$r->validate([
            'kind'=>['nullable','in:'.implode(',',self::KINDS)],
            'q'=>['nullable','string','max:160'],
        ]);
        $query=DB::table('cis_rh_employees');
        $search=$filters['q']??'';
        if ($search!=='') {
            $query->where(fn ($q)=>$q->where('name','like','%'.$search.'%')->orWhere('code','like','%'.$search.'%'));
        }
        $employees=$query->orderBy('name')->orderBy('id')->paginate(25,['id','name','code','company','category','admission','status']);
        if (Permissions::can($r->user(),'utilizadores') && Schema::hasTable('cis_rh_user_links')) {
            $links=DB::table('cis_rh_user_links as l')->join('users as u','u.id','=','l.user_id')
                ->whereIn('l.employee_id',$employees->pluck('id'))
                ->get(['l.employee_id','u.id','u.name','u.email','u.active'])->keyBy('employee_id');
            $employees->through(function ($employee) use ($links) {
                $employee->user=$links[$employee->id]??null;
                return $employee;
            });
        }
        $records=null;
        if (isset($filters['kind'])) {
            $records=DB::table('cis_rh_records as r')->join('cis_rh_employees as e','e.id','=','r.employee_id')
                ->where('r.kind',$filters['kind'])
                ->when($search!=='',fn ($q)=>$q->where(fn ($match)=>$match
                    ->where('e.name','like','%'.$search.'%')->orWhere('e.code','like','%'.$search.'%')))
                ->orderByDesc('r.reference_date')->orderByDesc('r.id')
                ->paginate(25,['r.id','r.employee_id','r.parent_id','r.reference_date','r.details','e.name','e.company','e.code'])
                ->through(function ($row) {
                    $row->details=json_decode($row->details,true,512,JSON_THROW_ON_ERROR);
                    return $row;
                });
        }
        return response()->json(['employees'=>$employees,'records'=>$records,'totals'=>[
            'colaboradores'=>DB::table('cis_rh_employees')->count(),
            ...DB::table('cis_rh_records')->selectRaw('kind, COUNT(*) AS total')->groupBy('kind')->pluck('total','kind')->all(),
        ]])->header('Cache-Control','no-store, private');
    }

    public function parents(Request $r)
    {
        $this->gate($r);
        $d=$r->validate(['employee_id'=>['required','integer'],'kind'=>['required','in:contratos,indemnizacoes']]);
        return response()->json(DB::table('cis_rh_records')->where('employee_id',$d['employee_id'])
            ->where('kind',$d['kind'])->orderByDesc('reference_date')->get(['id','reference_date']));
    }

    public function employee(Request $r)
    {
        $this->gate($r);
        $d=$r->validate([
            'company'=>['required','string','max:80'],'code'=>['required','string','max:32'],
            'name'=>['required','string','max:160'],'category'=>['nullable','string','max:80'],
            'admission'=>['required','date_format:Y-m-d'],'status'=>['required','in:activo,inactivo'],
        ]);
        $d['category']=$d['category']??'';
        try {
            $id=DB::transaction(function () use ($d,$r) {
                $id=DB::table('cis_rh_employees')->insertGetId($d+['created_at'=>now(),'updated_at'=>now()]);
                $this->audit($r,$id,null,'colaborador_criado',$d);
                return $id;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409,'O código de colaborador já existe nesta empresa.');
        }

        return response()->json(['id'=>$id],201);
    }

    public function record(Request $r,string $kind)
    {
        $this->gate($r);
        abort_unless(in_array($kind,self::KINDS,true),404);
        $parentKind=match ($kind) {'adendas'=>'contratos','prestacoes'=>'indemnizacoes',default=>null};
        $fields=match ($kind) {
            'contratos'=>['end'=>['nullable','date_format:Y-m-d','after_or_equal:reference_date'],
                'regime'=>['required','in:mensal,diario,hora'],'rate'=>self::MONEY],
            'adendas'=>['text'=>['required','string','max:2000'],'rate'=>self::MONEY],
            'ferias'=>['end'=>['required','date_format:Y-m-d','after_or_equal:reference_date'],'text'=>['nullable','string','max:500']],
            'indemnizacoes'=>['amount'=>self::MONEY,'text'=>['required','string','max:500']],
            'prestacoes'=>['amount'=>self::MONEY,'text'=>['nullable','string','max:500']],
        };
        $d=$r->validate([
            'employee_id'=>['required','integer','exists:cis_rh_employees,id'],
            'parent_id'=>[$parentKind?'required':'prohibited','nullable','integer'],
            'reference_date'=>['required','date_format:Y-m-d'],
            ...$fields,
        ]);
        $id=DB::transaction(function () use ($r,$kind,$parentKind,$d) {
            $employee=(int)$d['employee_id'];
            $parent=$d['parent_id']??null;
            if ($parentKind) {
                $existing=DB::table('cis_rh_records')->where('id',$parent)->where('kind',$parentKind)
                    ->where('employee_id',$employee)->lockForUpdate()->first();
                abort_unless($existing,422,'O registo associado deve pertencer ao mesmo colaborador.');
                abort_if($d['reference_date']<$existing->reference_date,422,'A data é anterior ao registo associado.');
            }
            if ($kind==='prestacoes') {
                $previous=DB::table('cis_rh_records')->where('parent_id',$parent)->where('kind','prestacoes')->pluck('details')
                    ->sum(fn ($json)=>(int)round((float)json_decode($json,true,512,JSON_THROW_ON_ERROR)['amount']*100));
                $limit=(int)round((float)json_decode($existing->details,true,512,JSON_THROW_ON_ERROR)['amount']*100);
                abort_if($previous+(int)round((float)$d['amount']*100)>$limit,422,'O total das prestações excede a indemnização.');
            }
            $details=array_diff_key($d,array_flip(['employee_id','parent_id','reference_date']));
            $id=DB::table('cis_rh_records')->insertGetId(['employee_id'=>$employee,'parent_id'=>$parent,'kind'=>$kind,
                'reference_date'=>$d['reference_date'],'details'=>json_encode($details,JSON_THROW_ON_ERROR),
                'created_at'=>now(),'updated_at'=>now()]);
            $this->audit($r,$employee,$id,$kind.'_criado',$d);
            return $id;
        });
        return response()->json(['id'=>$id],201);
    }

    private function canLink(Request $r): void
    {
        $this->gate($r);
        Permissions::require($r->user(),'utilizadores');
        abort_unless(Schema::hasTable('cis_rh_user_links'),503,'Instale a associação de utilizadores de RH na VPS.');
    }

    public function userOptions(Request $r,int $employee)
    {
        $this->canLink($r);
        abort_unless(DB::table('cis_rh_employees')->where('id',$employee)->exists(),404,'Colaborador não encontrado.');
        $d=$r->validate(['q'=>['nullable','string','max:160']]);
        $q=trim($d['q']??'');
        $list=DB::table('users as u')->leftJoin('cis_rh_user_links as l','l.user_id','=','u.id')
            ->where('u.active',true)->whereNull('l.employee_id')
            ->when($q!=='',fn ($query)=>$query->where(fn ($match)=>$match->where('u.name','like','%'.$q.'%')
                ->orWhere('u.email','like','%'.$q.'%')))
            ->orderBy('u.name')->orderBy('u.id')->limit(25)->get(['u.id','u.name','u.email']);
        return response()->json(['users'=>$list])->header('Cache-Control','no-store, private');
    }

    public function linkUser(Request $r,int $employee)
    {
        $this->canLink($r);
        $d=$r->validate(['user_id'=>['present','nullable','integer','min:1'],
            'previous_user_id'=>['present','nullable','integer','min:1']]);
        $next=$d['user_id']===null?null:(int)$d['user_id'];
        $expected=$d['previous_user_id']===null?null:(int)$d['previous_user_id'];
        try {
            DB::transaction(function () use ($r,$employee,$next,$expected) {
                $person=DB::table('cis_rh_employees')->where('id',$employee)->lockForUpdate()->first();
                abort_unless($person,404,'Colaborador não encontrado.');
                $current=DB::table('cis_rh_user_links')->where('employee_id',$employee)->value('user_id');
                $previous=$current===null?null:(int)$current;
                abort_if($previous!==$expected,409,'A associação mudou. Actualize a lista de colaboradores.');
                if ($previous===$next) return;
                if ($next===null) {
                    DB::table('cis_rh_user_links')->where('employee_id',$employee)->delete();
                } else {
                    abort_unless($person->status==='activo',422,'Só pode associar uma conta a um colaborador activo.');
                    $account=DB::table('users')->where('id',$next)->lockForUpdate()->first(['id','active']);
                    abort_unless($account && $account->active,422,'Seleccione uma conta activa do CIS.');
                    abort_if(DB::table('cis_rh_user_links')->where('user_id',$next)->exists(),409,
                        'Esta conta já está associada a outro colaborador.');
                    if ($previous===null) {
                        DB::table('cis_rh_user_links')->insert(['employee_id'=>$employee,'user_id'=>$next,'created_at'=>now(),'updated_at'=>now()]);
                    } else {
                        DB::table('cis_rh_user_links')->where('employee_id',$employee)->update(['user_id'=>$next,'updated_at'=>now()]);
                    }
                }
                $this->audit($r,$employee,null,'utilizador_associado',['anterior'=>$previous,'novo'=>$next]);
            },3);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409,'Esta conta já está associada a outro colaborador.');
        }
        return response()->json(['user_id'=>$next]);
    }

    private function audit(Request $r,int $employee,?int $record,string $action,array $snapshot): void
    {
        DB::table('cis_rh_record_audit')->insert(['employee_id'=>$employee,'record_id'=>$record,
            'user_id'=>$r->user()->id,'source'=>'cis','action'=>$action,'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),
            'created_at'=>now()]);
    }
}
