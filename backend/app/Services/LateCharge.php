<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class LateCharge
{
    private const TYPES = ['fixo_unico','fixo_periodico','percentual_unico','percentual_periodico','escaloes','misto','legado','sem_mora'];

    public static function rules(): array
    {
        return [
            'mora'=>['sometimes','array:tipo,valor,taxa,base,periodicidade,dias_periodo,carencia,contar_desde_vencimento,periodos_iniciados,tecto,escaloes,acumular,componente,valor_recorrente,decisao,valor_reduzido,motivo'],
            'mora.tipo'=>['required_with:mora','in:'.implode(',', self::TYPES)],
            'mora.valor'=>['required_if:mora.tipo,fixo_unico,fixo_periodico,misto','numeric','min:0','max:9999999','decimal:0,2'],
            'mora.taxa'=>['required_if:mora.tipo,percentual_unico,percentual_periodico','required_if:mora.componente,percentagem','numeric','min:0','max:100','decimal:0,4'],
            'mora.base'=>['required_with:mora.taxa','in:capital,saldo'],
            'mora.periodicidade'=>['required_if:mora.tipo,fixo_periodico,percentual_periodico,misto','in:diaria,semanal,quinzenal,mensal,personalizada'],
            'mora.dias_periodo'=>['required_if:mora.periodicidade,personalizada','integer','min:1','max:366'],
            'mora.carencia'=>['sometimes','integer','min:0','max:366'],
            'mora.contar_desde_vencimento'=>['sometimes','boolean'],
            'mora.periodos_iniciados'=>['sometimes','boolean'],
            'mora.tecto'=>['sometimes','numeric','min:0','max:9999999','decimal:0,2'],
            'mora.escaloes'=>['required_if:mora.tipo,escaloes','array','min:1','max:30'],
            'mora.escaloes.*'=>['array:dias,valor'],
            'mora.escaloes.*.dias'=>['required','integer','min:1','max:36600','distinct'],
            'mora.escaloes.*.valor'=>['required','numeric','min:0','max:9999999','decimal:0,2'],
            'mora.acumular'=>['sometimes','boolean'],
            'mora.componente'=>['required_if:mora.tipo,misto','in:fixo,percentagem'],
            'mora.valor_recorrente'=>['required_if:mora.componente,fixo','numeric','min:0','max:9999999','decimal:0,2'],
            'mora.decisao'=>['sometimes','in:integral,reduzir,dispensar'],
            'mora.valor_reduzido'=>['required_if:mora.decisao,reduzir','numeric','min:0.01','max:9999999','decimal:0,2'],
            'mora.motivo'=>['required_if:mora.decisao,reduzir,dispensar','string','max:200'],
        ];
    }

    public static function calculate(array $rule, CarbonImmutable $due, CarbonImmutable $paid, int $capital, int $balance, float $legacyRate, int $legacyFine, bool $whole, bool $mayAdjust): array
    {
        $type=$rule['tipo'];
        $decision=$rule['decisao']??'integral';
        abort_if($decision!=='integral'&&!$mayAdjust,403,'Sem permissão para reduzir ou dispensar a mora.');
        if ($legacyRate>0) {
            abort_unless($type==='legado',422,'Esta prestação tem uma regra de mora contratual.');
        } else {
            abort_if($type==='legado',422,'Esta prestação não tem uma taxa de mora contratual.');
        }
        $delay=max(0,(int)$due->diffInDays($paid,false));
        $grace=(int)($rule['carencia']??0);
        $origin=empty($rule['contar_desde_vencimento'])?$due->addDays($grace):$due;
        $days=$delay>$grace?max(0,(int)$origin->diffInDays($paid,false)):0;
        $base=($rule['base']??'saldo')==='capital'?$capital:$balance;
        $periods=0;
        if ($type==='legado') {
            $charge=$legacyFine;
        } elseif ($days===0 || $type==='sem_mora') {
            $charge=0;
        } else {
            $fixed=(float)($rule['valor']??0)*100;
            $pct=$base*(float)($rule['taxa']??0)/100;
            $periods=isset($rule['periodicidade'])?self::periods($rule,$origin,$paid,$days):1;
            $charge=match ($type) {
                'fixo_unico'=>$fixed,
                'fixo_periodico'=>$fixed*$periods,
                'percentual_unico'=>$pct,
                'percentual_periodico'=>$pct*$periods,
                'escaloes'=>self::tiers($rule,$days),
                'misto'=>$fixed+(($rule['componente']==='percentagem'?$pct:(float)$rule['valor_recorrente']*100)*$periods),
            };
            $charge=$whole?(int)ceil(round($charge/100,8))*100:(int)round($charge);
            if (isset($rule['tecto'])) {
                $cap=CashAccountLedger::cents((string)$rule['tecto']);
                $charge=min($charge,$whole?intdiv($cap,100)*100:$cap);
            }
        }
        $collected=match ($decision) {
            'dispensar'=>0,
            'reduzir'=>CashAccountLedger::cents((string)$rule['valor_reduzido']),
            default=>$charge,
        };
        if ($decision==='reduzir'&&($collected>=$charge||($whole&&$collected%100!==0))) {
            throw ValidationException::withMessages(['mora.valor_reduzido'=>'Indique um valor inferior à mora calculada e compatível com a precisão do contrato.']);
        }
        return [
            'tipo'=>$type,'regra'=>$rule,'dias_sujeitos'=>$type==='legado'?$delay:$days,'periodos'=>$type==='legado'?($delay>0?1:0):$periods,
            'base_centimos'=>$base,'calculado_centimos'=>$charge,'cobrado_centimos'=>$collected,
        ];
    }

    private static function periods(array $r, CarbonImmutable $origin, CarbonImmutable $paid, int $days): int
    {
        $started=!empty($r['periodos_iniciados']);
        if ($r['periodicidade']==='mensal') {
            $months=($paid->year-$origin->year)*12+$paid->month-$origin->month;
            if ($origin->addMonthsNoOverflow($months)->greaterThan($paid)) $months--;
            return $months+($started&&$origin->addMonthsNoOverflow($months)->lessThan($paid)?1:0);
        }
        $unit=match ($r['periodicidade']) {
            'diaria'=>1,'semanal'=>7,'quinzenal'=>15,'personalizada'=>(int)$r['dias_periodo'],
        };
        return intdiv($days+($started?$unit-1:0),$unit);
    }

    private static function tiers(array $r, int $days): float
    {
        $value=0;
        $highest=0;
        foreach ($r['escaloes'] as $tier) {
            $threshold=(int)$tier['dias'];
            if ($threshold>$days) continue;
            if (!empty($r['acumular'])) {
                $value+=(float)$tier['valor'];
            } elseif ($threshold>$highest) {
                $highest=$threshold;
                $value=(float)$tier['valor'];
            }
        }
        return $value*100;
    }
}
