<?php

namespace App\Services;

/**
 * Histórico imutável das prestações: fotografia ANTES/DEPOIS de cada alteração.
 * Tem de ser chamado DENTRO da mesma transacção da alteração em Contractos.
 * Se a gravação do histórico falhar, a transacção inteira é revertida.
 */
final class ContractHistory
{
    /** Linha completa como array JSON-seguro (FLOAT -> string, datas -> texto). */
    public static function snapshot(?object $row): ?array
    {
        if ($row===null) {
            return null;
        }
        $out=[];
        foreach ((array)$row as $k=>$v) {
            $out[$k]=is_float($v)?rtrim(rtrim(number_format($v,6,'.',''),'0'),'.'):$v;
        }
        return $out;
    }

    public static function record($db,array $e): int
    {
        foreach (['contract_number','operation','operation_key','performed_by'] as $required) {
            abort_unless(isset($e[$required]) && $e[$required]!=='',500,'Histórico incompleto; operação cancelada.');
        }
        $json=fn($v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $after=self::snapshot($e['after']??null);
        if (isset($e['late_charge'])) $after['Regra de mora']=$e['late_charge'];
        return (int)$db->table('cis_contractos_historico')->insertGetId([
            'contract_number'=>(string)$e['contract_number'],
            'installment_ordem'=>$e['installment_ordem']??null,
            'installment_number'=>$e['installment_number']??null,
            'operation'=>(string)$e['operation'],
            'before_snapshot'=>$json(self::snapshot($e['before']??null)),
            'after_snapshot'=>$json($after),
            'extracto_nr'=>$e['extracto_nr']??null,
            'account_id'=>$e['account_id']??null,
            'amount'=>$e['amount']??null,
            'evidence_reference'=>isset($e['evidence_reference'])?mb_substr((string)$e['evidence_reference'],0,120):null,
            'reason'=>isset($e['reason'])?mb_substr((string)$e['reason'],0,255):null,
            'operation_key'=>(string)$e['operation_key'],
            'performed_by'=>(int)$e['performed_by'],
            'performed_at'=>now('Africa/Maputo')->format('Y-m-d H:i:s'),
        ]);
    }
}
