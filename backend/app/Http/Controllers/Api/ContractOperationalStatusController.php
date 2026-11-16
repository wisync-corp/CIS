<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractOperationalStatusController extends Controller
{
    public function __invoke(Request $request)
    {
        $user=$request->user();
        abort_unless($user && $user->active && $user->role==='administrador',403);
        $flags=[
            'abertura'=>filter_var(env('CIS_NEW_CONTRACTS_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'semanal'=>filter_var(env('CIS_WEEKLY_CONTRACTS_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'formulas_adicionais'=>filter_var(env('CIS_EXTENDED_CALCULATIONS_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
            'cobranca'=>filter_var(env('CIS_COLLECTION_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
        ];
        $checks=['coluna_estrategia'=>false,'conexao_financeira'=>false,
            'tabelas_transaccionais'=>false];
        try {
            $db=DB::connection('mysql_contract_writer');
            $db->select('SELECT 1 AS ok');
            $checks['conexao_financeira']=true;
            $checks['coluna_estrategia']=$db->getSchemaBuilder()
                ->hasColumn('Contractos','Estratégia de Cálculo');
            $tables=$db->select("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('Contractos','Extracto')");
            $checks['tabelas_transaccionais']=count($tables)===2
                && collect($tables)->every(fn($table)=>strtoupper((string)$table->ENGINE)==='INNODB');
        } catch (\Throwable $e) {
            // Não revelar credenciais, detalhes de servidor ou SQL no endpoint.
        }
        $ready=$flags['abertura']&&collect($checks)->every(fn($ok)=>$ok);
        return response()->json([
            'abertura_desembolso_prontos'=>$ready,
            'cobranca_pronta'=>$ready&&$flags['cobranca'],
            'semanal_pronto'=>$ready&&$flags['semanal'],
            'formulas_adicionais_prontas'=>$ready&&$flags['formulas_adicionais'],
            'sinalizadores'=>$flags,
            'verificacoes'=>$checks,
            'nota'=>'Confirma apenas configuração e ligações; não confirma que uma transacção financeira real já foi executada.',
        ])->header('Cache-Control','no-store, private');
    }
}
