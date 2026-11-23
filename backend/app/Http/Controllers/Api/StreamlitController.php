<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AllowPrivateContractsPreview;
use App\Support\Branches;
use App\Support\Permissions;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class StreamlitController extends Controller
{
    private const COOKIE = 'cis_streamlit';

    public function session(Request $request)
    {
        Permissions::require($request->user(), 'relatorios');
        $token = $request->bearerToken();
        abort_unless($token, 401);

        return response()->noContent()->cookie(
            self::COOKIE, Crypt::encryptString($token), 0, '/dashboard', null,
            $request->isSecure(), true, false, 'strict'
        );
    }

    public function verify(Request $request)
    {
        $this->authenticate($request);
        return response()->noContent();
    }

    public function data(Request $request)
    {
        $user = $this->authenticate($request);
        return app(AllowPrivateContractsPreview::class)->handle($request, function () use ($user) {
            $db = DB::connection('mysql_legacy');
            $contracts = $db->table('Contractos');
            Branches::restrict($contracts, $user);
            $clientIds = (clone $contracts)->select('Nº de Cliente')->distinct();
            $fields = [
                'Ordem', 'Nr do Contracto', 'Nome do Cliente', 'Data de Inicio', 'Data de Cobrança',
                'Estado', 'Estado do Contrato', 'Prazo/Duração', 'Quantia Creditada', 'Valor Pago',
                'Valor a Pagar', 'Valor de Juros',
            ];
            $schema = $db->getSchemaBuilder();
            $contractColumns = $schema->getColumnListing('Contractos');
            $method = in_array('M. Pagamento', $contractColumns, true) ? 'M. Pagamento' : 'Forma de Pagamento';
            $contracts->select($fields);
            if (in_array($method, $contractColumns, true)) {
                $contracts->addSelect($method.' as M. Pagamento');
            } else {
                $contracts->selectRaw("NULL AS `M. Pagamento`");
            }

            $clients = $db->table('Clientes')->whereIn('ID', $clientIds)->select('Nome Completo');
            Branches::applyToClients($clients, Branches::scope($user));
            if ($schema->hasColumn('Clientes', 'Estado Civil')) {
                $clients->addSelect('Estado Civil');
            } else {
                $clients->selectRaw("NULL AS `Estado Civil`");
            }

            return response()->json([
                'contractos' => $contracts->get(),
                'clientes' => $clients->get(),
            ])->header('Cache-Control', 'no-store, private');
        });
    }

    private function authenticate(Request $request): object
    {
        $cookie = $request->cookie(self::COOKIE);
        abort_unless(is_string($cookie) && $cookie !== '', 401);
        try {
            $request->headers->set('Authorization', 'Bearer '.Crypt::decryptString($cookie));
        } catch (DecryptException) {
            abort(401);
        }

        $user = Auth::guard('sanctum')->user();
        abort_unless($user, 401);
        Permissions::require($user, 'relatorios');
        return $user;
    }
}
