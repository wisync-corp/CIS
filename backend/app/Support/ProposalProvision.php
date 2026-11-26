<?php

namespace App\Support;

final class ProposalProvision
{
    public static function assess(int $id, array $history, int $amount): array
    {
        $class=$history['classificacao'];
        $key=match ($class) {
            'Baixo risco'=>'baixo',
            'Risco médio'=>'medio',
            'Alto risco'=>'alto',
            default=>'sem_historico',
        };
        $rate=CompanySettings::get('provisao_'.$key);
        $paid=(int)$history['prestacoes_pagas'];
        $late=(int)$history['pagas_em_atraso'];
        $factors=$paid===0
            ? ['Sem prestações pagas para calcular o score.']
            : [($paid-$late).' de '.$paid.' prestações pagas sem atraso.',
               'Limiares internos: baixo risco a partir de '.CompanySettings::get('score_baixo_risco')
               .'; risco médio a partir de '.CompanySettings::get('score_risco_medio').'.'];
        if ($history['contratos_em_atraso']>0) {
            $factors[]=$history['contratos_em_atraso'].' contrato(s) em atraso, até '.$history['max_dias_atraso']
                .' dias.'.($paid>0?' O score aplica uma penalização de 20 pontos.':'');
        }
        if ($history['contratos_abatidos']>0) $factors[]=$history['contratos_abatidos'].' contrato(s) abatido(s) no histórico.';
        $data=[
            'classe'=>$class,'score'=>$history['score'],'factores'=>$factors,
            'taxa'=>$rate===''?null:$rate,'montante'=>$amount,
            'provisao'=>$rate===''?null:number_format(round($amount*(float)$rate/100,2),2,'.',''),
        ];
        $version=$data;
        unset($version['montante'],$version['provisao']);
        return $data+['versao'=>hash('sha256',json_encode([$id,$version],JSON_THROW_ON_ERROR))];
    }
}
