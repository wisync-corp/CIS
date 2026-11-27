<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RhReportsController extends Controller
{
    public function index(Request $r)
    {
        Permissions::require($r->user(),'definicoes');
        abort_unless(Schema::hasTable('cis_rh_payroll')&&Schema::hasTable('cis_rh_payroll_periods'),503,'Instale as tabelas de RH na VPS.');
        $d=$r->validate(['period'=>['required','date_format:Y-m'],'company'=>['nullable','string','max:80']]);
        $month=CarbonImmutable::createFromFormat('!Y-m-d',$d['period'].'-01');
        $start=$month->toDateString();
        $previous=$month->subMonthNoOverflow()->toDateString();
        $end=$month->endOfMonth()->toDateString();
        $company=$d['company']??'';

        $payroll=DB::table('cis_rh_payroll as p')->join('cis_rh_employees as e','p.employee_id','=','e.id')
            ->when($company!=='',fn ($q)=>$q->where('e.company',$company));
        $summaries=(clone $payroll)->whereIn('p.period',[$start,$previous])
            ->selectRaw("p.period, COUNT(*) AS total,
                SUM(p.status = 'finalizado') AS finalized,
                SUM(p.status = 'alterado') AS modified,
                SUM(p.status = 'pendente') AS pending,
                SUM(CASE WHEN p.status = 'finalizado' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(p.calculation,'$.amounts.net')) AS DECIMAL(14,2)) ELSE 0 END) AS net,
                SUM(CASE WHEN p.status = 'finalizado' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(p.calculation,'$.amounts.base')) AS DECIMAL(14,2)) ELSE 0 END) AS base,
                SUM(CASE WHEN p.status = 'finalizado' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(p.calculation,'$.amounts.total_inss')) AS DECIMAL(14,2)) ELSE 0 END) AS inss,
                SUM(CASE WHEN p.status = 'finalizado' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(p.calculation,'$.amounts.irps')) AS DECIMAL(14,2)) ELSE 0 END) AS irps")
            ->groupBy('p.period')->get()->keyBy('period');
        $periods=DB::table('cis_rh_payroll_periods')->whereIn('period',[$start,$previous])->pluck('closed_at','period');

        $rows=(clone $payroll)->where('p.period',$start)->orderBy('e.company')->orderBy('e.name')->orderBy('p.id')
            ->paginate(30,['e.code','e.name','e.company','p.id','p.status','p.calculation'])
            ->through(function ($row) {
                $snapshot=json_decode($row->calculation,true,512,JSON_THROW_ON_ERROR);
                $row->amounts=$snapshot['amounts']??null;
                unset($row->calculation);
                return $row;
            });
        $people=DB::table('cis_rh_employees')->when($company!=='',fn ($q)=>$q->where('company',$company));
        $records=DB::table('cis_rh_records as r')->join('cis_rh_employees as e','e.id','=','r.employee_id')
            ->when($company!=='',fn ($q)=>$q->where('e.company',$company));
        $vacations=(clone $records)->where('r.kind','ferias')->where('r.review_status','aprovado')
            ->where('r.reference_date','<=',$end)->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.end')) >= ?",[$start])->count();

        $debt=(clone $records)->where('r.kind','indemnizacoes')->where('r.reference_date','<=',$end)
            ->selectRaw("COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.amount')) AS DECIMAL(14,2))),0) AS agreed")->first();
        $installments=(clone $records)->where('r.kind','prestacoes')
            ->join('cis_rh_records as parent','parent.id','=','r.parent_id')->where('parent.reference_date','<=',$end)
            ->selectRaw("COALESCE(SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.paid_on')) <= ? THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.amount')) AS DECIMAL(14,2)) ELSE 0 END),0) AS paid,
             COALESCE(SUM(CASE WHEN r.reference_date < ? AND (JSON_EXTRACT(r.details,'$.paid_on') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.paid_on')) > ?) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(r.details,'$.amount')) AS DECIMAL(14,2)) ELSE 0 END),0) AS overdue",
                [$end,$end,$end])->first();
        $balance=number_format((float)$debt->agreed-(float)$installments->paid,2,'.','');
        return response()->json([
            'period'=>$d['period'],'date_end'=>$end,'companies'=>DB::table('cis_rh_employees')->distinct()->orderBy('company')->pluck('company'),
            'current'=>self::summary($summaries->get($start),$start,$periods->get($start)),
            'previous'=>self::summary($summaries->get($previous),$previous,$periods->get($previous)),
            'employees'=>(clone $people)->where('status','activo')->count(),'approved_leave'=>$vacations,
            'indemnities'=>['agreed'=>$debt->agreed,'paid'=>$installments->paid,'balance'=>$balance,'overdue'=>$installments->overdue],
            'rows'=>$rows,
        ])->header('Cache-Control','no-store, private');
    }

    private static function summary(?object $row,string $period,?string $closed): array
    {
        return ['period'=>substr($period,0,7),'closed_at'=>$closed,
            'total'=>(int)($row->total??0),'finalized'=>(int)($row->finalized??0),
            'modified'=>(int)($row->modified??0),'pending'=>(int)($row->pending??0),
            'net'=>(string)($row->net??'0.00'),'base'=>(string)($row->base??'0.00'),
            'inss'=>(string)($row->inss??'0.00'),'irps'=>(string)($row->irps??'0.00')];
    }
}
