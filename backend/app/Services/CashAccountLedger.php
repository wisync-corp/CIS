<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Regras partilhadas da Caixa para fluxos de CONTRATO (cobrança e desembolso).
 * Mesma semântica de CashPostingController: saldo = saldo inicial + movimentos da
 * conta com Nr acima do marcador; lock por ID antes de ler saldo ou escrever.
 */
final class CashAccountLedger
{
    public const FLAG='CIS_CASH_CONTRACT_FLOWS_APPROVED';

    public static function flag(string $name): bool
    {
        return filter_var(env($name,false),FILTER_VALIDATE_BOOLEAN);
    }

    /** Flags + esquema. A flag nova só é ligada pelo activador com permissões verificadas. */
    public static function requireReady(): void
    {
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED',self::FLAG] as $flag) {
            abort_unless(self::flag($flag),503,
                'Ligação de contratos à Caixa ainda não activada neste servidor.');
        }
        abort_unless(Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id')
            && Schema::hasColumn('Extracto','operation_key')
            && Schema::hasTable('cis_contractos_historico'),503,
            'Esquema da Caixa ou histórico de prestações não instalado.');
    }

    public static function cents(string $number,string $field='valor'): int
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$number)) {
            throw ValidationException::withMessages([$field=>'Montante inválido (até 2 casas decimais).']);
        }
        [$whole,$fraction]=array_pad(explode('.',$number,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }

    /** Valor FLOAT legado -> cêntimos (o valor real legado tem no máximo 2 casas). */
    public static function legacyCents(mixed $value): int
    {
        $f=(float)$value;
        abort_unless(is_finite($f),409,'Valor legado inválido.');
        return (int)round($f*100);
    }

    public static function amount(int $cents): string
    {
        $sign=$cents<0?'-':'';
        $cents=abs($cents);
        return $sign.intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    /** Bloqueia a conta (FOR UPDATE) e devolve [conta, saldo em cêntimos]. */
    public static function lockActive($db,int $id): array
    {
        $a=$db->table('cis_cash_accounts')->where('id',$id)->lockForUpdate()->first();
        abort_unless($a,404,'Conta da Caixa inexistente.');
        return [$a,self::balance($db,$a)];
    }

    public static function balance($db,object $a): int
    {
        abort_unless($a->status==='activa' && $a->currency==='MZN'
            && $a->opening_balance!==null && $a->cut_at!==null
            && $a->opening_extracto_nr!==null && $a->validated_at!==null,409,
            'A conta seleccionada não está activa com saldo inicial validado.');
        $sum=self::cents((string)$a->opening_balance);
        $moves=$db->table('Extracto')->where('account_id',$a->id)
            ->where('Nr','>',$a->opening_extracto_nr)
            ->get(['Valor','Tipo de Movimento']);
        foreach ($moves as $m) {
            $value=self::cents((string)$m->Valor);
            abort_unless(in_array($m->{'Tipo de Movimento'},['Entradas','Saídas'],true),409,
                'Existe um movimento com classificação desconhecida nesta conta.');
            $sum += $m->{'Tipo de Movimento'}==='Entradas'?$value:-$value;
        }
        abort_unless($sum>=0,409,'Saldo da conta inconsistente; requer conferência.');
        return $sum;
    }

    /**
     * Saldo da conta num dia passado e quanto se pode retirar com essa data.
     * Devolve [saldo no fim desse dia, disponível] em cêntimos. O disponível é o menor saldo
     * desse dia em diante: uma saída com data antiga não pode deixar nenhum dia seguinte negativo.
     */
    public static function availableOn($db,object $a,string $date): array
    {
        self::balance($db,$a); // valida o estado da conta
        $cut=substr((string)$a->cut_at,0,10);
        $start=self::cents((string)$a->opening_balance);
        $byDay=[];
        $moves=$db->table('Extracto')->where('account_id',$a->id)
            ->where('Nr','>',$a->opening_extracto_nr)
            ->get(['Valor','Tipo de Movimento','Data da Operação as d']);
        foreach ($moves as $m) {
            $day=substr((string)($m->d??''),0,10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day) || $day<$cut) $day=$cut;
            $v=self::cents((string)$m->Valor);
            $byDay[$day]=($byDay[$day]??0)+($m->{'Tipo de Movimento'}==='Entradas'?$v:-$v);
        }
        ksort($byDay);
        $atDate=$start;
        foreach ($byDay as $day=>$net) if ($day<=$date) $atDate+=$net;
        $run=$atDate; $min=$atDate;
        foreach ($byDay as $day=>$net) {
            if ($day<=$date) continue;
            $run+=$net; $min=min($min,$run);
        }
        return [$atDate,max(0,$min)];
    }

    /** A data tem de estar no período aberto da conta: depois da abertura e sem fecho diário nesse dia ou depois. */
    public static function requireDateOpen(object $a,string $date,string $what='lançar movimentos'): void
    {
        $cut=substr((string)($a->cut_at??''),0,10);
        abort_if($cut!=='' && $date<$cut,422,'A data é anterior à abertura da conta '.$a->code.' na Caixa ('
            .implode('/',array_reverse(explode('-',$cut))).').');
        try {
            $closed=\Illuminate\Support\Facades\DB::connection()->table('cis_cash_daily_closures')
                ->where('account_id',(int)$a->id)->where('closing_date','>=',$date)->max('closing_date');
        } catch (\Illuminate\Database\QueryException $e) {
            if ((int)($e->errorInfo[1]??0)===1146) return;
            throw $e;
        }
        abort_if($closed!==null,422,'A conta '.$a->code.' já tem fecho diário em '
            .implode('/',array_reverse(explode('-',substr((string)$closed,0,10)))).'; não é possível '.$what.' com data até esse dia.');
    }
}
