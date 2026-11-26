<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Payments\EmolaGateway;
use App\Support\Payments\GatewayResult;
use App\Support\Payments\MpesaGateway;
use App\Support\Permissions;
use App\Support\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Definições › Subscrição: plano do CIS, utilizadores, renovação e pagamentos M-Pesa / e-Mola.
 * Só o administrador (permissão «definicoes») gere. O valor é sempre calculado no servidor.
 */
final class SubscriptionController extends Controller
{
    private function ready(): void
    {
        abort_unless(Subscription::ready(), 503, 'Subscrição ainda não instalada. Na VPS execute: bash deploy/install-subscription.sh');
    }

    /** Modo de cobrança de cada operadora: api (integração directa), simulacao (testes) ou manual. */
    private static function mode(string $method): string
    {
        if (filter_var(config('cis_subscription.simulacao'), FILTER_VALIDATE_BOOL)) return 'simulacao';
        return ($method === 'mpesa' ? MpesaGateway::configured() : EmolaGateway::configured()) ? 'api' : 'manual';
    }

    private static function methods(): array
    {
        $out = [];
        foreach (['mpesa' => ['M-Pesa', 'Vodacom', '84 ou 85'], 'emola' => ['e-Mola', 'Movitel', '86 ou 87']] as $id => [$name, $op, $pref]) {
            $out[] = ['id' => $id, 'nome' => $name, 'operadora' => $op, 'prefixos' => $pref, 'modo' => self::mode($id),
                'numero_manual' => (string) config("cis_subscription.$id.numero_manual")];
        }
        return $out;
    }

    private static function present(object $p): array
    {
        return ['referencia' => $p->referencia, 'plano' => $p->plano, 'plano_nome' => Subscription::plan($p->plano)['nome'] ?? $p->plano,
            'utilizadores_extra' => (int) $p->utilizadores_extra, 'ciclo' => $p->ciclo, 'meses' => (int) $p->meses, 'valor' => (float) $p->valor,
            'metodo' => $p->metodo, 'modo' => $p->modo, 'telefone' => substr($p->telefone, 0, 2).'*****'.substr($p->telefone, -2),
            'estado' => $p->estado, 'comprovativo' => $p->comprovativo, 'operadora_transacao' => $p->operadora_transacao,
            'mensagem' => $p->operadora_mensagem, 'valido_de' => $p->valido_de, 'valido_ate' => $p->valido_ate,
            'criado_em' => (string) $p->created_at, 'confirmado_em' => $p->confirmado_em ? (string) $p->confirmado_em : null];
    }

    /** Estado resumido para o aviso no topo (qualquer utilizador autenticado). */
    public function summary(Request $r): JsonResponse
    {
        if (!Subscription::ready()) return response()->json(['instalado' => false]);
        $s = Subscription::current();
        return response()->json(['instalado' => true, 'plano' => $s->plano, 'plano_nome' => Subscription::plan($s->plano)['nome'] ?? $s->plano,
            'valido_ate' => $s->valido_ate, 'utilizadores' => Subscription::activeUsers(), 'limite' => Subscription::limit($s),
            'pode_gerir' => Permissions::can($r->user(), 'definicoes')] + Subscription::status($s))->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $s = Subscription::current();
        $plans = collect(Subscription::plans())->map(fn ($p, $id) => ['id' => $id] + $p)->values();
        $pay = DB::table('cis_subscription_payments')->orderByDesc('id')->limit(30)->get()->map(fn ($p) => self::present($p));
        return response()->json([
            'subscricao' => ['plano' => $s->plano, 'plano_nome' => Subscription::plan($s->plano)['nome'] ?? $s->plano, 'ciclo' => $s->ciclo,
                'utilizadores_extra' => (int) $s->utilizadores_extra, 'inicio' => $s->inicio, 'valido_ate' => $s->valido_ate,
                'limite' => Subscription::limit($s), 'utilizadores' => Subscription::activeUsers()] + Subscription::status($s),
            'planos' => $plans, 'modulos' => collect(config('cis_subscription.modulos'))->map(fn ($m, $id) => ['id' => $id] + $m)->values(),
            'metodos' => self::methods(), 'meses_gratis_anual' => (int) config('cis_subscription.meses_gratis_anual'),
            'dias_carencia' => (int) config('cis_subscription.dias_carencia'), 'taxa_implementacao' => config('cis_subscription.taxa_implementacao'),
            'pagamentos' => $pay,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function pay(Request $r): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $d = $r->validate([
            'plano' => ['required', Rule::in(array_keys(Subscription::plans()))],
            'utilizadores_extra' => ['required', 'integer', 'min:0', 'max:200'],
            'ciclo' => ['required', Rule::in(['mensal', 'anual'])],
            'metodo' => ['required', Rule::in(['mpesa', 'emola'])],
            'telefone' => ['required', 'string', 'max:20'],
        ]);
        $msisdn = Subscription::msisdn($d['telefone']);
        abort_if(!$msisdn, 422, 'Número de telemóvel inválido (9 dígitos, ex.: 84 123 4567).');
        $pref = substr($msisdn, 0, 2);
        abort_if($d['metodo'] === 'mpesa' && !in_array($pref, ['84', '85'], true), 422, 'A M-Pesa usa números Vodacom começados por 84 ou 85.');
        abort_if($d['metodo'] === 'emola' && !in_array($pref, ['86', '87'], true), 422, 'A e-Mola usa números Movitel começados por 86 ou 87.');
        $q = Subscription::quote($d['plano'], (int) $d['utilizadores_extra'], $d['ciclo']);
        $active = Subscription::activeUsers();
        abort_if($q['utilizadores'] < $active, 422, "Tem {$active} utilizadores activos e este plano só permite {$q['utilizadores']}. ".
            'Acrescente utilizadores extra ou desactive contas antes de mudar.');
        abort_if(DB::table('cis_subscription_payments')->where('estado', 'pendente')->where('modo', 'api')
            ->where('created_at', '>', now()->subMinutes(2))->exists(), 409, 'Já há um pagamento a decorrer. Aguarde a confirmação no telemóvel.');
        $mode = self::mode($d['metodo']);
        $ref = Subscription::newReference();
        DB::table('cis_subscription_payments')->insert(['referencia' => $ref, 'plano' => $d['plano'], 'utilizadores_extra' => (int) $d['utilizadores_extra'],
            'ciclo' => $d['ciclo'], 'meses' => $q['meses'], 'valor' => $q['total'], 'metodo' => $d['metodo'], 'modo' => $mode, 'telefone' => $msisdn,
            'estado' => 'pendente', 'iniciado_por' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $desc = 'CIS '.(Subscription::plan($d['plano'])['nome']).' '.$d['ciclo'].' '.$ref;
        $result = match ($mode) {
            'simulacao' => GatewayResult::ok('SIM'.$ref, 'Pagamento simulado (ambiente de testes).', 'SIM'),
            'api' => ($d['metodo'] === 'mpesa' ? new MpesaGateway() : new EmolaGateway())->charge($ref, $msisdn, $q['total'], $desc),
            default => GatewayResult::pending('Envie o valor para o número indicado e registe o ID da transacção do SMS. A WiSync confirma e o acesso é renovado.', 'manual'),
        };
        if ($result->estado === 'confirmado') {
            Subscription::confirm($ref, $mode === 'simulacao' ? 'simulação' : 'operadora '.$d['metodo'], $result->transacao);
        } elseif ($result->estado === 'falhado') {
            Subscription::fail($ref, (string) $result->codigo, $result->mensagem);
        } else {
            DB::table('cis_subscription_payments')->where('referencia', $ref)->update(['operadora_codigo' => $result->codigo, 'operadora_mensagem' => $result->mensagem, 'updated_at' => now()]);
        }
        $p = DB::table('cis_subscription_payments')->where('referencia', $ref)->first();
        return response()->json(['pagamento' => self::present($p), 'mensagem' => $result->mensagem], $result->estado === 'falhado' ? 422 : 201);
    }

    /** Modo manual: o administrador regista o ID da transacção que recebeu por SMS. */
    public function proof(Request $r, string $ref): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $d = $r->validate(['comprovativo' => ['required', 'string', 'min:6', 'max:60', 'regex:/^[A-Za-z0-9.\-]+$/']]);
        $n = DB::table('cis_subscription_payments')->where('referencia', $ref)->where('estado', 'pendente')->where('modo', 'manual')
            ->update(['comprovativo' => strtoupper($d['comprovativo']), 'operadora_mensagem' => 'Comprovativo registado: a aguardar confirmação da WiSync.', 'updated_at' => now()]);
        abort_if($n === 0, 404, 'Pagamento pendente não encontrado.');
        return response()->json(['pagamento' => self::present(DB::table('cis_subscription_payments')->where('referencia', $ref)->first())]);
    }

    public function cancel(Request $r, string $ref): JsonResponse
    {
        Permissions::require($r->user(), 'definicoes');
        $this->ready();
        $n = DB::table('cis_subscription_payments')->where('referencia', $ref)->where('estado', 'pendente')->where('modo', 'manual')
            ->whereNull('comprovativo')->update(['estado' => 'cancelado', 'updated_at' => now()]);
        abort_if($n === 0, 422, 'Só se cancela um pagamento manual pendente sem comprovativo.');
        return response()->json(['ok' => true]);
    }

    /** Callback assíncrono da e-Mola (código 22). Protegido por segredo partilhado. */
    public function emolaCallback(Request $r): JsonResponse
    {
        $secret = (string) config('cis_subscription.emola.callback_secret');
        abort_if($secret === '' || !hash_equals($secret, (string) ($r->header('X-Callback-Secret') ?? $r->query('token', ''))), 403);
        if (!Subscription::ready()) return response()->json(['ok' => false], 503);
        $ref = (string) ($r->input('transId') ?? $r->input('refNo') ?? '');
        $code = (string) ($r->input('errorCode') ?? $r->input('error') ?? '');
        $p = DB::table('cis_subscription_payments')->where('referencia', $ref)->where('metodo', 'emola')->first();
        if (!$p) return response()->json(['ok' => false, 'motivo' => 'referência desconhecida'], 404);
        if (in_array($code, ['0', '00'], true)) Subscription::confirm($ref, 'operadora emola', (string) ($r->input('requestId') ?? $ref));
        else Subscription::fail($ref, $code, EmolaGateway::message('0', $code));
        return response()->json(['ok' => true]);
    }
}
