<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use App\Support\RhPayrollCalculation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class RhPayrollController extends Controller
{
    private function gate(Request $r): void
    {
        Permissions::require($r->user(),'definicoes');
        abort_unless(Schema::hasTable('cis_rh_payroll_events') && Schema::hasTable('cis_rh_payroll_periods'),503,'Instale as tabelas de folha salarial na VPS.');
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
        $period=DB::table('cis_rh_payroll_periods')->where('period',$d['period'].'-01')->first();
        return response()->json($rows->toArray()+['period_status'=>!$period?'por_abrir':($period->closed_at?'fechado':'aberto')])->header('Cache-Control','no-store, private');
    }

    public function candidates(Request $r)
    {
        $this->gate($r);
        $d=$r->validate(['period'=>['required','date_format:Y-m']]);
        [$period,$start,$end]=self::periodDates($d['period']);
        $employees=DB::table('cis_rh_employees as e')->where('e.admission','<=',$end)
            ->whereNotExists(fn ($q)=>$q->selectRaw('1')->from('cis_rh_payroll as p')
                ->whereColumn('p.employee_id','e.id')->where('p.period',$period))
            ->orderBy('e.name')->orderBy('e.id')->paginate(30,['e.id','e.name','e.code','e.company','e.status']);
        $contracts=DB::table('cis_rh_records')->where('kind','contratos')
            ->whereIn('employee_id',$employees->pluck('id'))->where('reference_date','<=',$end)
            ->get(['employee_id','details'])->filter(fn ($row)=>self::contractOverlaps($row,$start))->groupBy('employee_id');
        $employees->through(function ($employee) use ($contracts) {
            $count=$contracts->get($employee->id,collect())->count();
            $employee->eligible=$employee->status==='activo'&&$count===1;
            $employee->reason=match (true) {
                $employee->status!=='activo'=>'Cadastro inactivo.',
                $count===0=>'Sem contrato aplicável ao período.',
                $count>1=>'Mais de um contrato aplicável ao período.',
                default=>null,
            };
            return $employee;
        });
        return response()->json($employees)->header('Cache-Control','no-store, private');
    }

    private static function periodDates(string $month): array
    {
        $cut=CarbonImmutable::createFromFormat('!Y-m-d',$month.'-20');
        return [$month.'-01',$cut->subMonthNoOverflow()->addDay()->toDateString(),$cut->toDateString()];
    }

    private static function contractOverlaps(object $row,string $start): bool
    {
        $end=json_decode($row->details,true,512,JSON_THROW_ON_ERROR)['end']??null;
        return !$end || $end>=$start;
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
        [,$start,$reference]=self::periodDates($input['period']);
        abort_if($employee->admission>$reference,422,'A admissão é posterior ao período da folha.');
        $contracts=DB::table('cis_rh_records')->where('employee_id',$employee->id)->where('kind','contratos')
            ->where('reference_date','<=',$reference)->orderByDesc('reference_date')->get()
            ->filter(fn ($row)=>self::contractOverlaps($row,$start))->values();
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
                $this->lockPeriod($input['period'].'-01');
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
            $period=DB::table('cis_rh_payroll')->where('id',$id)->value('period');
            abort_unless($period,404,'Lançamento não encontrado.');
            $this->lockPeriod($period);
            $row=DB::table('cis_rh_payroll')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row,404,'Lançamento não encontrado.');
            abort_if($row->status==='finalizado'||(int)$row->version!==(int)$d['version'],409,'O estado do lançamento mudou.');
            $version=(int)$row->version+1;
            DB::table('cis_rh_payroll')->where('id',$id)->update(['status'=>'finalizado','version'=>$version,'updated_at'=>now()]);
            $this->event($id,$r,'finalizado',$version,$row->calculation);
        });
        return response()->json(['id'=>$id,'status'=>'finalizado']);
    }

    public function openPeriod(Request $r)
    {
        $this->gate($r);
        $data=$r->validate(['period'=>['required','date_format:Y-m']]);
        $period=$data['period'].'-01';
        DB::transaction(function () use ($r,$period) {
            DB::table('cis_rh_payroll_periods')->insertOrIgnore(['period'=>$period,'opened_by'=>$r->user()->id,'opened_at'=>now()]);
            $this->lockPeriod($period);
        });
        return response()->json(['status'=>'aberto']);
    }

    public function closePeriod(Request $r)
    {
        $this->gate($r);
        $data=$r->validate(['period'=>['required','date_format:Y-m'],'confirm'=>['required','accepted']]);
        $period=$data['period'].'-01';
        DB::transaction(function () use ($r,$period) {
            $this->lockPeriod($period);
            $rows=DB::table('cis_rh_payroll')->where('period',$period);
            abort_unless((clone $rows)->exists(),422,'Abra lançamentos para este mês antes de o fechar.');
            abort_if($rows->where('status','!=','finalizado')->exists(),422,'Finalize todos os lançamentos antes do fecho mensal.');
            DB::table('cis_rh_payroll_periods')->where('period',$period)->update(['closed_at'=>now(),'closed_by'=>$r->user()->id]);
        });
        return response()->json(['status'=>'fechado']);
    }

    public function recalculatePending(Request $r)
    {
        $this->gate($r);
        $d=$r->validate(['period'=>['required','date_format:Y-m'],'after'=>['nullable','integer','min:0']]);
        $period=$d['period'].'-01';
        $ids=DB::table('cis_rh_payroll')->where('period',$period)->where('status','pendente')
            ->where('id','>',$d['after']??0)->orderBy('id')->limit(26)->pluck('id');
        $batch=$ids->take(25);
        $changed=0;
        $skipped=0;
        $issues=[];
        foreach ($batch as $id) {
            try {
                $result=DB::transaction(function () use ($r,$period,$id) {
                    $this->lockPeriod($period);
                    $row=DB::table('cis_rh_payroll')->where('id',$id)->lockForUpdate()->first();
                    if (!$row || $row->status!=='pendente') return 'skipped';
                    $input=json_decode($row->input,true,512,JSON_THROW_ON_ERROR);
                    unset($input['regime'],$input['rate'],$input['contract_id'],$input['amendment_id']);
                    $result=$this->calculate($input);
                    $snapshot=json_encode($result,JSON_THROW_ON_ERROR);
                    $previous=json_decode($row->calculation,true,512,JSON_THROW_ON_ERROR);
                    $comparable=$result;
                    unset($previous['signature'],$comparable['signature']);
                    if ($comparable==$previous) return 'skipped';
                    $version=(int)$row->version+1;
                    DB::table('cis_rh_payroll')->where('id',$id)->update(['version'=>$version,
                        'input'=>json_encode($result['input'],JSON_THROW_ON_ERROR),'calculation'=>$snapshot,'updated_at'=>now()]);
                    $this->event($id,$r,'recalculado',$version,$snapshot);
                    return 'changed';
                },3);
                if ($result==='changed') $changed++; else $skipped++;
            } catch (ValidationException $e) {
                $issues[]=['id'=>$id,'message'=>collect($e->errors())->flatten()->first()??'Cálculo inválido.'];
            } catch (HttpExceptionInterface $e) {
                if ($e->getStatusCode()!==422) throw $e;
                $issues[]=['id'=>$id,'message'=>$e->getMessage()];
            }
        }
        // A verificação também se aplica a meses sem lançamentos pendentes.
        if ($batch->isEmpty()) DB::transaction(fn ()=>$this->lockPeriod($period));
        return response()->json(['changed'=>$changed,'skipped'=>$skipped,'issues'=>$issues,
            'next'=>$ids->count()>25?$batch->last():null]);
    }

    public function restore(Request $r,int $id)
    {
        $this->gate($r);
        $data=$r->validate(['version'=>['required','integer','min:1'],'confirm'=>['required','accepted']]);
        DB::transaction(function () use ($r,$id,$data) {
            $period=DB::table('cis_rh_payroll')->where('id',$id)->value('period');
            abort_unless($period,404,'Lançamento não encontrado.');
            $this->lockPeriod($period);
            $row=DB::table('cis_rh_payroll')->where('id',$id)->lockForUpdate()->first();
            abort_unless($row->status==='alterado'&&(int)$row->version===(int)$data['version'],409,'Só pode repor a versão alterada em análise.');
            $initial=DB::table('cis_rh_payroll_events')->where('payroll_id',$id)->where('version',1)->value('snapshot');
            abort_unless($initial,409,'O lançamento não tem uma versão inicial disponível.');
            $snapshot=json_decode($initial,true,512,JSON_THROW_ON_ERROR);
            $version=(int)$row->version+1;
            DB::table('cis_rh_payroll')->where('id',$id)->update(['status'=>'pendente','version'=>$version,
                'input'=>json_encode($snapshot['input'],JSON_THROW_ON_ERROR),'calculation'=>$initial,'updated_at'=>now()]);
            $this->event($id,$r,'reposto',$version,$initial);
        });
        return response()->json(['status'=>'pendente']);
    }

    private function lockPeriod(string $period): void
    {
        $row=DB::table('cis_rh_payroll_periods')->where('period',$period)->lockForUpdate()->first();
        abort_unless($row,422,'Abra o mês antes de processar a folha.');
        abort_if($row->closed_at!==null,409,'O mês já foi fechado.');
    }

    private function event(int $id,Request $r,string $action,int $version,string $snapshot): void
    {
        DB::table('cis_rh_payroll_events')->insert(['payroll_id'=>$id,'user_id'=>$r->user()->id,
            'action'=>$action,'version'=>$version,'snapshot'=>$snapshot,'created_at'=>now()]);
    }
}
