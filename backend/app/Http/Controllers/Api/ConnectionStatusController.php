<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ConnectionStatusController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
        $database = (string) env('LEGACY_DB_DATABASE', '');
        $approved = filter_var(env('CIS_FINANCIAL_TEST_APPROVED', false), FILTER_VALIDATE_BOOLEAN);
        $testName = preg_match('/(?:test|teste|staging|sandbox|replica)/i', $database)
            && !preg_match('/(?:prod|production|producao|produção)/iu', $database);
        $ca = (string) env('LEGACY_DB_SSL_CA', '');
        $tlsCaReady = $ca !== '' && is_readable($ca);
        $testGate = $approved && $testName && in_array(config('app.env'), ['local','testing','staging'], true);
        return response()->json([
            'backend' => 'operacional',
            'legacy_connection_configured' => (string) env('LEGACY_DB_HOST', '') !== ''
                && $database !== '' && (string) env('LEGACY_DB_USERNAME', '') !== '',
            'tls_ca_configured' => $tlsCaReady,
            'test_data_gate_open' => $testGate,
            'records_access' => $testGate ? 'sujeito a autorizacao e permissao MySQL' : 'bloqueado',
            'note' => 'Estado de configuracao apenas; nao testa TLS de sessao, grants ou conexao com dados reais.',
        ]);
    }
}
