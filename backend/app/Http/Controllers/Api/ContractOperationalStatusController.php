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
            'tabelas_transaccionais'=>false,'permissoes_abertura'=>false,'permissoes_cobranca'=>false];
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
            $grants=collect($db->select('SHOW GRANTS'))->flatMap(
                fn($item)=>array_values((array)$item))->map(fn($grant)=>strtoupper((string)$grant));
            $hasInsert=$grants->contains(fn($grant)=>str_contains($grant,'INSERT')
                || str_contains($grant,'ALL PRIVILEGES'));
            $hasUpdate=$grants->contains(fn($grant)=>str_contains($grant,'UPDATE')
                || str_contains($grant,'ALL PRIVILEGES'));
            $checks['permissoes_abertura']=$hasInsert;
            $checks['permissoes_cobranca']=$hasUpdate;

        } catch (\Throwable $e) {
            // Não revelar credenciais, detalhes de servidor ou SQL no endpoint.
        }
        // O desembolso legado foi bloqueado ate integrar conta e movimento da Caixa.
        // Nao apresentar o endpoint como pronto por flags antigas.
        $cashAccountDisbursementIntegrated=false;
        $ready=$cashAccountDisbursementIntegrated&&$flags['abertura']&&$checks['conexao_financeira']
            &&$checks['coluna_estrategia']&&$checks['tabelas_transaccionais']
            &&$checks['permissoes_abertura'];
        return response()->json([
            'abertura_desembolso_prontos'=>$ready,
            'caixa_desembolso_integrado'=>$cashAccountDisbursementIntegrated,
            'cobranca_pronta'=>$ready&&$flags['cobranca']&&$checks['permissoes_cobranca'],
            'semanal_pronto'=>$ready&&$flags['semanal'],
            'formulas_adicionais_prontas'=>$ready&&$flags['formulas_adicionais'],
            'sinalizadores'=>$flags,
            'verificacoes'=>$checks,
            'nota'=>'Abertura com desembolso bloqueada ate associar atomicamente conta activa e movimento de Caixa. A verificacao de flags nao autoriza nova concessao.',
        ])->header('Cache-Control','no-store, private');
    }
}
