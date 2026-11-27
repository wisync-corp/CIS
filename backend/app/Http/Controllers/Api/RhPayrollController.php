<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use App\Support\RhPayrollCalculation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RhPayrollController extends Controller
{
    private function gate(Request $r): void
    {
        Permissions::require($r->user(),'definicoes');
        abort_unless(Schema::hasTable('cis_rh_payroll_events'),503,'Instale as tabelas de folha salarial na VPS.');
    }

    public function index(Request $r)
    {
        $this->gate($r);
        $d=$r->validate(['period'=>['required','date_format:Y-m']]);
        $rows=DB::table('cis_rh_payroll as p')->join('cis_rh_employees as e','e.id','=','p.employee_id')
            ->where('p.period',$d['period'].'-01')->orderBy('e.name')->orderBy('p.id')
            ->paginate(30,['p.*','e.name','e.company','e.code'])->through(function ($row) {
                $row->input=json_decode($row->input,true,512,JSON_THROW_ON_ERROR);
                $row->calculation=json_decode($row->calculation,true,512,JSON_THROW_ON_ERROR);
                return $row;
            });
        return response()->json($rows)->header('Cache-Control','no-store, private');
    }

    private function inputs(Request $r): array
    {
        $money=['required','numeric','min:0','max:9999999999','decimal:0,2'];
        return $r->validate([
            'employee_id'=>['required','integer','exists:cis_rh_employees,id'],
            'period'=>['required','date_format:Y-m'],
            'days_worked'=>['required','numeric','min:0','max:31','decimal:0,2'],
            'hours_worked'=>['required','numeric','min:0','max:744','decimal:0,2'],
            'unjustified_absences'=>['required','numeric','min:0','max:31','decimal:0,2'],
            'overtime'=>$money,'allowances'=>$money,'bonus'=>$money,'other_earnings'=>$money,'other_deductions'=>$money,
            'contribute'=>['required','boolean'],'dependents'=>['required','integer','min:0','max:100'],
            'department'=>['required','string','max:80'],
        ]);
    }

    private function calculate(array $input): array
    {
        $employee=DB::table('cis_rh_employees')->where('id',$input['employee_id'])->first();
        abort_unless($employee,404,'Colaborador não encontrado.');
        $cut=CarbonImmutable::createFromFormat('!Y-m-d',$input['period'].'-20');
        $reference=$cut->toDateString();
        $start=$cut->subMonthNoOverflow()->addDay()->toDateString();
        abort_if($employee->admission>$reference,422,'A admissão é posterior ao período da folha.');
        $contracts=DB::table('cis_rh_records')->where('employee_id',$employee->id)->where('kind','contratos')
            ->where('reference_date','<=',$reference)->orderByDesc('reference_date')->get()
            ->filter(function ($row) use ($start) {
                $end=json_decode($row->details,true,512,JSON_THROW_ON_ERROR)['end']??null;
                return !$end || $end>=$start;
            })->values();
        abort_unless($contracts->count()===1,422,'O período exige um único contrato aplicável. Confira os contratos do colaborador.');
        $contract=$contracts->first();
        $details=json_decode($contract->details,true,512,JSON_THROW_ON_ERROR);
        $amendments=DB::table('cis_rh_records')->where('parent_id',$contract->id)->where('kind','adendas')
            ->where('reference_date','<=',$reference)->orderByDesc('reference_date')->orderByDesc('id')->limit(2)->get();
        abort_if($amendments->count()===2 && $amendments[0]->reference_date===$amendments[1]->reference_date,
            422,'Há duas adendas para a mesma data. Confirme qual se aplica.');
        $amendment=$amendments->first();
        $rate=$amendment?json_decode($amendment->details,true,512,JSON_THROW_ON_ERROR)['rate']:$details['rate'];
        $input+=['regime'=>$details['regime'],'rate'=>$rate,'contract_id'=>$contract->id,'amendment_id'=>$amendment?->id];
        $rules=RhSettingsController::rulesFor($reference,$employee->company,$employee->category,$employee->code);
        $result=RhPayrollCalculation::run($input,$rules);
        $payload=['input'=>$input]+$result;
        return $payload+['signature'=>hash_hmac('sha256',json_encode($payload,JSON_THROW_ON_ERROR),(string)config('app.key'))];
    }

    public function preview(Request $r)
    {
        $this->gate($r);
        return response()->json($this->calculate($this->inputs($r)))->header('Cache-Control','no-store, private');
    }

    public function save(Request $r)
    {
        $this->gate($r);
        $input=$this->inputs($r);
        $checks=$r->validate(['version'=>['nullable','integer','min:1'],'signature'=>['required','string','size:64']]);
        $expected=$checks['version']??null;
        try {
            $id=DB::transaction(function () use ($input,$r,$expected,$checks) {
                $existing=DB::table('cis_rh_payroll')->where('employee_id',$input['employee_id'])
                    ->where('period',$input['period'].'-01')->lockForUpdate()->first();
                abort_if($existing && ($existing->status==='finalizado'||$expected===null||(int)$existing->version!==(int)$expected),
                    409,'O lançamento foi alterado ou finalizado. Actualize a lista.');
                abort_if(!$existing&&$expected!==null,409,'O lançamento já não está disponível.');
                $result=$this->calculate($input);
                abort_unless(hash_equals($result['signature'],$checks['signature']),409,'O cálculo mudou. Reveja uma nova prévia.');
                $version=($existing?(int)$existing->version:0)+1;
                $row=['input'=>json_encode($result['input'],JSON_THROW_ON_ERROR),
                    'calculation'=>json_encode($result,JSON_THROW_ON_ERROR),'status'=>$existing?'alterado':'pendente',
                    'version'=>$version,'updated_at'=>now()];
                if ($existing) {
                    $id=(int)$existing->id;
                    DB::table('cis_rh_payroll')->where('id',$id)->update($row);
                } else {
                    $id=DB::table('cis_rh_payroll')->insertGetId($row+['employee_id'=>$input['employee_id'],
                        'period'=>$input['period'].'-01','created_at'=>now()]);
                }
                $this->event($id,$r,$row['status'],$version,$row['calculation']);
                return $id;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409,'O lançamento já existe. Actualize a lista.');
        }
        return response()->json(['id'=>$id],201);
    }

    public function finalize(Request $r,int $id)
    {
        $this->gate($r);
        $d=$r->validate(['version'=>['required','integer','min:1'],'confirm'=>['required','accepted']]);
        DB::transaction(function () use ($r,$d,$id) {
            $row=DB::table('cis_rh_payroll')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row,404,'Lançamento não encontrado.');
            abort_if($row->status==='finalizado'||(int)$row->version!==(int)$d['version'],409,'O estado do lançamento mudou.');
            $version=(int)$row->version+1;
            DB::table('cis_rh_payroll')->where('id',$id)->update(['status'=>'finalizado','version'=>$version,'updated_at'=>now()]);
            $this->event($id,$r,'finalizado',$version,$row->calculation);
        });
        return response()->json(['id'=>$id,'status'=>'finalizado']);
    }

    private function event(int $id,Request $r,string $action,int $version,string $snapshot): void
    {
        DB::table('cis_rh_payroll_events')->insert(['payroll_id'=>$id,'user_id'=>$r->user()->id,
            'action'=>$action,'version'=>$version,'snapshot'=>$snapshot,'created_at'=>now()]);
    }
}
