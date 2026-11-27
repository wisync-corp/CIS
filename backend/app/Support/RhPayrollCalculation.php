<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class RhPayrollCalculation
{
    private static function value(array $parameters,string $key,mixed $fallback=null): mixed
    {
        $value=$parameters[$key]??$fallback;
        if ($value===null || $value==='') {
            throw ValidationException::withMessages(['rules'=>'Falta o parâmetro '.$key.' nas Definições de RH.']);
        }
        return $value;
    }

    public static function run(array $input,array $rules): array
    {
        $priority=['global'=>0,'empresa'=>1,'categoria'=>2,'colaborador'=>3];
        $params=[];
        $ranks=[];
        $applied=[];
        foreach ($rules['parametros'] as $row) {
            $code=$row['Codigo_Parametro'];
            $rank=$priority[strtolower($row['Nivel_Aplicacao'])];
            if (!isset($ranks[$code]) || $rank>$ranks[$code]) {
                $params[$code]=$row['Valor'];
                $ranks[$code]=$rank;
                $applied[$code]=$row;
            }
        }
        $v=fn ($key,$fallback=null)=>self::value($params,$key,$fallback);
        $days=(float)$v('BASE_DIAS_MES');
        $hours=(float)$v('BASE_HORAS_DIA');
        $worked=(float)$input['days_worked'];
        $rate=(float)$input['rate'];
        $bruto=round(match ($input['regime']) {
            'mensal'=>$worked<$days?round($rate/$days/$hours,2)*$hours*$worked:$rate,
            'hora'=>$rate*(float)$input['hours_worked'],
            'diario'=>$rate*$worked,
        },2);
        $extras=array_sum(array_map(fn ($key)=>(float)$input[$key],['overtime','allowances','bonus','other_earnings']));
        $absences=round($bruto/$days/$hours,2)*$hours*(float)$input['unjustified_absences'];
        $base=$bruto-$absences+$extras;
        $normalize=fn ($s)=>mb_strtoupper(trim((string)$s));
        $inssApplies=$normalize((bool)$input['contribute']?'Sim':'Não')===$normalize($v('INSS_CONDICAO_CANALIZAR'));
        $employeeInss=$inssApplies?round($base*(float)$v('INSS_TAXA_COLAB'),(int)$v('INSS_COLAB_ARRED_CASAS',$params['ARRED_CASAS_DEFAULT']??null)):0;
        $employerInss=$inssApplies?round($base*(float)$v('INSS_TAXA_EMP'),(int)$v('INSS_EMP_ARRED_CASAS')):0;
        $scale=null;
        $irps=0;
        if ($normalize($input['department'])!==$normalize($v('IRPS_ISENCAO_DEPARTAMENTO'))) {
            foreach ($rules['escaloes'] as $candidate) {
                if (round($base,2)>=(float)$candidate['Limite_Inferior'] &&
                    ($candidate['Limite_Superior']===null || round($base,2)<=(float)$candidate['Limite_Superior'])) {
                    $scale=$candidate;
                    break;
                }
            }
            if (!$scale) throw ValidationException::withMessages(['rules'=>'Sem escalão de IRPS aplicável. Confirme a tabela em vigor e a base salarial.']);
            $dependents=min((int)$input['dependents'],(int)$v('IRPS_DEPENDENTES_MAX'));
            $column=$dependents>=4?'Dependentes_4_Mais':'Dependentes_'.$dependents;
            $irps=round(($base-(float)$scale['Limite_Inferior'])*(float)$scale['Coeficiente_Adicional']+(float)$scale[$column],
                (int)$v('IRPS_ARRED_CASAS',$params['ARRED_CASAS_DEFAULT']??null));
        }
        $money=['gross'=>$bruto,'earnings'=>$extras,'absence_deduction'=>$absences,'base'=>$base,
            'employee_inss'=>$employeeInss,'employer_inss'=>$employerInss,'total_inss'=>$employeeInss+$employerInss,
            'irps'=>$irps,'net'=>$base-$employeeInss-$irps-(float)$input['other_deductions']];
        return ['amounts'=>array_map(fn ($n)=>number_format($n,2,'.',''),$money),
            'rules'=>['parameters'=>$applied,'tax_table'=>$rules['tabelaIRPS'],'scale'=>$scale,'reference'=>$rules['dataReferencia']]];
    }
}
