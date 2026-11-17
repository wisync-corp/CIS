<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Escrituração do CAIXA GERAL no livro LEGADO Extracto.
 *
 * - Escolhe uma conexão MySQL própria com SELECT/INSERT no Extracto e SELECT
 *   em cis_cash_accounts; não permite UPDATE/DELETE em movimentos financeiros.
 * - Bloqueia contas por ID em ordem crescente antes de ler saldos/escrever.
 * - Chaves idempotentes únicas, com replay fiel, sem repetir lançamentos.
 * - Nenhum movimento de cobrança/desembolso usa este endpoint genérico.
 *
 * Desactivado por omissão: exige 2 flags explícitas. Só ligar depois de
 * ensaio sobre restauro MySQL real e verificação dos grants.
 */
final class CashPostingController extends Controller
{
    private function requireReady(Request $request): void
    {
        $u=$request->user();
        abort_unless($u && $u->active && $u->role==='administrador',403,
            'Movimentos de Caixa reservados ao administrador autorizado.');
        foreach (['CIS_PRIVATE_PREVIEW','CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED'] as $flag) {
            abort_unless(filter_var(env($flag,false),FILTER_VALIDATE_BOOLEAN),503,
                'Escrituração de Caixa não autorizada neste ambiente.');
        }
        abort_unless(Schema::hasTable('cis_cash_accounts')
            && Schema::hasColumn('Extracto','account_id')
            && Schema::hasColumn('Extracto','operation_key'),503,
            'Esquema financeiro não homologado.');
        abort_unless(!Schema::hasTable('cis_cash_movements') && !Schema::hasTable('cis_cash_links'),
            503,'Encontrado livro de movimentos paralelo.');
    }

    private function cents(string $number): int
    {
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$number)) {
            throw ValidationException::withMessages(['amount'=>'Montante inválido; limite 999 999 999,99 MT.']);
        }
        [$whole,$fraction]=array_pad(explode('.',$number,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
    private function amount(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }

    /** Mesmo connection handle dentro do lock, inclusive saldo e linhas novas. */
    private function balance($db, object $a): int
    {
        abort_unless($a->status==='activa' && $a->currency==='MZN'
            && $a->opening_balance!==null && $a->cut_at!==null
            && $a->opening_extracto_nr!==null && $a->validated_at!==null,409,
            'Conta sem saldo inicial e validação concluídos.');
        $sum=$this->cents((string)$a->opening_balance);
        $moves=$db->table('Extracto')->where('account_id',$a->id)
            ->where('Nr','>',$a->opening_extracto_nr)
            ->get(['Valor','Tipo de Movimento']);
        foreach ($moves as $m) {
            $value=$this->cents((string)$m->Valor);
            abort_unless(in_array($m->{'Tipo de Movimento'},['Entradas','Saídas'],true),409,
                'Existe um movimento com classificação desconhecida.');
            $sum += $m->{'Tipo de Movimento'}==='Entradas'?$value:-$value;
        }
        abort_unless($sum>=0,409,'Saldo da conta inconsistente; requer conferência.');
        return $sum;
    }

    private function writer()
    {
        $db=DB::connection('mysql_cash_ledger');
        abort_unless($db->getDatabaseName()===DB::connection()->getDatabaseName(),503,
            'Escritor aponta para outra base de dados.');
        return $db;
    }

    private function description(array $d): string
    {
        // A tabela Extracto não possui campos de categoria/comprovativo separados.
        // Usar descrição auditável, estável e limitada ao VARCHAR legado.
        return '[CAIXA:'.strtoupper($d['category']).'] '.trim($d['description'])
            .' | REF:'.trim($d['evidence_reference']);
    }

    private function transferDescription(array $d,int $source,int $destination,string $leg): string
    {
        return '[TRANSFER '.$leg.' '.$source.'>'.$destination.'] '
            .trim($d['description']).' | REF:'.trim($d['evidence_reference']);
    }

    /** Confere colisões de idempotência sem repetir registos. */
    private function same(object $row,array $expected): void
    {
        abort_unless((int)$row->account_id===(int)$expected['account_id']
            && (string)$row->{'Tipo de Movimento'}===$expected['Tipo de Movimento']
            && $this->cents((string)$row->Valor)===$this->cents((string)$expected['Valor'])
            && (string)$row->{'Descrição'}===$expected['Descrição']
            && (string)$row->usuario===$expected['usuario']
            && (string)($row->Contracto??'')===''
            && (int)($row->Parcela??0)===0,409,
            'A chave da operação já foi utilizada para dados diferentes.');
    }

    private function row(int $id,string $key,string $type,int $value,string $description,int $userId): array
    {
        return [
            'account_id'=>$id,
            'operation_key'=>$key,
            'Tipo de Movimento'=>$type,
            'Valor'=>$this->amount($value),
            'Data da Operação'=>now('Africa/Maputo')->format('Y-m-d'),
            'Descrição'=>$description,
            'Contracto'=>null,'Cliente'=>'CAIXA GERAL',
            'Parcela'=>0,'usuario'=>(string)$userId,
        ];
    }

    public function record(Request $request)
    {
        $this->requireReady($request);
        $d=$request->validate([
            'account_id'=>['required','integer','min:1'],
            'type'=>['required','in:entrada,saida'],
            'amount'=>['required','string'],
            'category'=>['required','string','regex:/^[\pL\pN _-]{2,32}$/u'],
            'description'=>['required','string','min:5','max:100'],
            'evidence_reference'=>['required','string','min:6','max:70'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $value=$this->cents($d['amount']);
        abort_unless($value>0,422,'Valor deve ser maior que zero.');
        $type=$d['type']==='entrada'?'Entradas':'Saídas';
        $key='cash:'.strtolower($d['idempotency_key']).':1';
        $db=$this->writer();
        return $db->transaction(function () use ($db,$d,$key,$type,$value,$request) {
            $a=$db->table('cis_cash_accounts')->where('id',$d['account_id'])->lockForUpdate()->first();
            abort_unless($a,404,'Conta inexistente.');
            $this->balance($db,$a); // exige estado activo, mesmo em replay
            $description=$this->description($d);
            $expected=$this->row((int)$a->id,$key,$type,$value,$description,(int)$request->user()->id);
            $already=$db->table('Extracto')->where('operation_key',$key)->first();
            if ($already) {
                $this->same($already,$expected);
                return response()->json([
                    'registered'=>true,'replayed'=>true,'extracto_nr'=>(int)$already->Nr,
                    'account_id'=>$a->id,'amount'=>$expected['Valor'],
                    'notice'=>'Movimento anteriormente confirmado; não foi criado outro lançamento.',
                ])->header('Cache-Control','no-store, private');
            }
            $before=$this->balance($db,$a);
            abort_unless($type==='Entradas'||$before>=$value,409,'Saldo insuficiente.');
            $nr=$db->table('Extracto')->insertGetId($expected,'Nr');
            $after=$before+($type==='Entradas'?$value:-$value);
            return response()->json([
                'registered'=>true,'replayed'=>false,'extracto_nr'=>(int)$nr,
                'account_id'=>$a->id,'amount'=>$expected['Valor'],
                'saldo_anterior'=>$this->amount($before),'saldo_actual'=>$this->amount($after),
                'notice'=>'Movimento lançado no Extracto com chave idempotente única.',
            ],201)->header('Cache-Control','no-store, private');
        },3);
    }

    public function transfer(Request $request)
    {
        $this->requireReady($request);
        $d=$request->validate([
            'source_account_id'=>['required','integer','min:1'],
            'destination_account_id'=>['required','integer','min:1','different:source_account_id'],
            'amount'=>['required','string'],
            'description'=>['required','string','min:5','max:100'],
            'evidence_reference'=>['required','string','min:6','max:70'],
            'confirmed_source_debit'=>['required','accepted'],
            'confirmed_destination_credit'=>['required','accepted'],
            'idempotency_key'=>['required','uuid'],
        ]);
        $value=$this->cents($d['amount']);
        abort_unless($value>0,422,'Valor deve ser maior que zero.');
        $key='cash:'.strtolower($d['idempotency_key']);
        $db=$this->writer();
        return $db->transaction(function () use ($db,$d,$key,$value,$request) {
            // Ordem de locking determinística evita deadlocks A->B / B->A.
            $ordered=[$d['source_account_id'],$d['destination_account_id']];
            sort($ordered,SORT_NUMERIC);
            $accounts=[];
            foreach ($ordered as $id) {
                $a=$db->table('cis_cash_accounts')->where('id',$id)->lockForUpdate()->first();
                abort_unless($a,404,'Conta de origem ou destino inexistente.');
                $accounts[$id]=$a;
            }
            $src=$accounts[$d['source_account_id']];
            $dst=$accounts[$d['destination_account_id']];
            $srcBalance=$this->balance($db,$src);
            $dstBalance=$this->balance($db,$dst);
            $user=(int)$request->user()->id;
            $out=$this->row((int)$src->id,$key.':1','Saídas',$value,
                $this->transferDescription($d,(int)$src->id,(int)$dst->id,'SAIDA'),$user);
            $in=$this->row((int)$dst->id,$key.':2','Entradas',$value,
                $this->transferDescription($d,(int)$src->id,(int)$dst->id,'ENTRADA'),$user);
            $existing=$db->table('Extracto')->whereIn('operation_key',[$key.':1',$key.':2'])
                ->get()->keyBy('operation_key');
            if ($existing->count()>0) {
                abort_unless($existing->count()===2,409,
                    'Transferência incompleta detectada; não foi feita segunda operação.');
                $this->same($existing[$key.':1'],$out);
                $this->same($existing[$key.':2'],$in);
                return response()->json([
                    'registered'=>true,'replayed'=>true,
                    'source_extracto_nr'=>$existing[$key.':1']->Nr,
                    'destination_extracto_nr'=>$existing[$key.':2']->Nr,
                    'amount'=>$out['Valor'],
                    'notice'=>'Transferência já registada; nenhuma perna foi duplicada.',
                ])->header('Cache-Control','no-store, private');
            }
            abort_unless($srcBalance>=$value,409,'Saldo insuficiente na conta de origem.');
            $nrOut=$db->table('Extracto')->insertGetId($out,'Nr');
            $nrIn=$db->table('Extracto')->insertGetId($in,'Nr');
            return response()->json([
                'registered'=>true,'replayed'=>false,
                'source_extracto_nr'=>$nrOut,'destination_extracto_nr'=>$nrIn,
                'amount'=>$out['Valor'],
                'source_balance'=>$this->amount($srcBalance-$value),
                'destination_balance'=>$this->amount($dstBalance+$value),
                'notice'=>'Transferência lançada atomicamente nas duas contas.',
            ],201)->header('Cache-Control','no-store, private');
        },3);
    }
}
