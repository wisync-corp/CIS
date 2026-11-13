<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ClientSecurityInfoController extends Controller
{
    // Informação complementar: não duplicar morada, contacto principal ou BI.
    private const FIELDS = [
        'ponto_referencia',
        'local_trabalho_actividade',
        'fonte_rendimento_principal',
        'contacto_alternativo',
        'pessoa_referencia_nome',
        'pessoa_referencia_contacto',
        'melhor_horario_contacto',
    ];

    private function authorizeFeature(Request $request): void
    {
        abort_unless(
            filter_var(env('CIS_CLIENTES_SECONDARY_APPROVED', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            503,
            'Informações complementares ainda não activadas.'
        );
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
    }

    private function verifyLegacyClient(int $id): void
    {
        $ids = DB::connection('mysql_legacy')->table('Clientes')
            ->where('ID', $id)->limit(2)->pluck('ID');
        abort_if($ids->count() !== 1, $ids->isEmpty() ? 404 : 409,
            'Cliente inexistente ou ID duplicado.');
    }

    public function show(Request $request, int $id)
    {
        $this->authorizeFeature($request);
        $this->verifyLegacyClient($id);
        $row = DB::table('cis_client_secondary_info')
            ->where('client_id', $id)->first(self::FIELDS);
        return response()->json($row ?: (object) array_fill_keys(self::FIELDS, null))
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeFeature($request);
        $validated = $request->validate([
            'ponto_referencia' => ['nullable', 'string', 'max:255'],
            'local_trabalho_actividade' => ['nullable', 'string', 'max:180'],
            'fonte_rendimento_principal' => ['nullable', 'string', 'max:180'],
            'contacto_alternativo' => ['nullable', 'string', 'max:40'],
            'pessoa_referencia_nome' => ['nullable', 'string', 'max:180'],
            'pessoa_referencia_contacto' => ['nullable', 'string', 'max:40'],
            'melhor_horario_contacto' => ['nullable', 'string', 'max:100'],
            'referencia_contacto_informado' => ['required', 'boolean'],
        ]);

        // Guardar contacto de terceiro apenas após confirmar que o titular
        // foi informado sobre a finalidade e o uso permitido.
        $hasThirdParty = trim((string) ($validated['pessoa_referencia_nome'] ?? '')) !== ''
            || trim((string) ($validated['pessoa_referencia_contacto'] ?? '')) !== '';
        if ($hasThirdParty && !$validated['referencia_contacto_informado']) {
            return response()->json([
                'message' => 'Confirme que o cliente foi informado sobre a recolha do contacto da pessoa de referência.',
            ], 422);
        }
        if ($hasThirdParty && (trim((string) ($validated['pessoa_referencia_nome'] ?? '')) === ''
            || trim((string) ($validated['pessoa_referencia_contacto'] ?? '')) === '')) {
            return response()->json(['message' => 'Indique o nome e contacto da pessoa de referência.'], 422);
        }
        $this->verifyLegacyClient($id);
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $validated[$field] ?? null;
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        DB::transaction(function () use ($request, $id, $values) {
            $previous = DB::table('cis_client_secondary_info')
                ->where('client_id', $id)->lockForUpdate()->first();
            $old = $previous ? (array) $previous : [];
            $changed = array_values(array_filter(self::FIELDS,
                fn ($field) => ($old[$field] ?? null) !== $values[$field]));
            if ($changed === []) {
                return;
            }
            DB::table('cis_client_secondary_info')->updateOrInsert(
                ['client_id' => $id],
                array_merge($values, [
                    'updated_by' => (int) $request->user()->id,
                    'updated_at' => now(),
                ])
            );
            // Auditoria sem gravar dados pessoais repetidos no histórico.
            DB::table('cis_client_secondary_audit')->insert([
                'client_id' => $id,
                'actor_user_id' => (int) $request->user()->id,
                'fields_changed' => json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'changed_at' => now(),
            ]);
        }, 3);
        return response()->json(['message' => 'Informações complementares actualizadas.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
