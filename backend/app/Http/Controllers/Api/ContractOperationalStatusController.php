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
            'caixa_contratos'=>filter_var(env('CIS_CASH_CONTRACT_FLOWS_APPROVED',false),FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_CASH_WRITES_APPROVED',false),FILTER_VALIDATE_BOOLEAN),
        ];
        $checks=['coluna_estrategia'=>false,'conexao_financeira'=>false,
            'tabelas_transaccionais'=>false,'permissoes_abertura'=>false,'permissoes_cobranca'=>false,
            'historico_prestacoes'=>false,'permissoes_contas_caixa'=>false];
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
            $checks['historico_prestacoes']=$db->getSchemaBuilder()->hasTable('cis_contractos_historico');
            $checks['permissoes_contas_caixa']=$grants->contains(fn($grant)=>str_contains($grant,'CIS_CASH_ACCOUNTS'))
                && $grants->contains(fn($grant)=>str_contains($grant,'CIS_CONTRACTOS_HISTORICO'));

        } catch (\Throwable $e) {
            // Não revelar credenciais, detalhes de servidor ou SQL no endpoint.
        }
        // Pronto só com a ligação à Caixa activada (flag nova), histórico instalado
        // e permissões do escritor sobre contas e histórico. Flags antigas não bastam.
        $cashAccountDisbursementIntegrated=$flags['caixa_contratos']&&$checks['historico_prestacoes']
            &&$checks['permissoes_contas_caixa'];
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
            'nota'=>$cashAccountDisbursementIntegrated
                ?'Desembolsos e cobranças ligados a contas da Caixa, com histórico imutável das prestações.'
                :'Desembolso e cobrança reais exigem a activação da ligação à Caixa (deploy/activate-contract-cash-flows.sh).',
        ])->header('Cache-Control','no-store, private');
    }
}
