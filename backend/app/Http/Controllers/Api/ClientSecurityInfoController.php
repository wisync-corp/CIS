<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClientSecurityInfoController extends Controller
{
    // Estes sete campos pertencem à tabela Clientes original; sem tabela secundária.
    private const FIELDS = [
        'ponto_referencia',
        'local_trabalho_actividade',
        'fonte_rendimento_principal',
        'contacto_alternativo',
        'pessoa_referencia_nome',
        'pessoa_referencia_contacto',
        'melhor_horario_contacto',
        'tipo_cliente',
        'genero',
        'profissao',
        'tipo_documento',
        'nuit',
        'local_emissao_documento',
        'validade_documento',
        'nome_pai',
        'nome_mae',
        'grau_parentesco_referencia',
    ];

    private function allowed(Request $request): void
    {
        abort_unless(filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN)
            && filter_var(env('CIS_CLIENTES_SECONDARY_APPROVED', false), FILTER_VALIDATE_BOOLEAN),
            503, 'Campos complementares ainda não activados.');
        $user = $request->user();
        abort_unless($user && $user->active && $user->role === 'administrador', 403);
    }

    public function show(Request $request, int $id)
    {
        $this->allowed($request);
        // Dois registos com mesmo ID tornam a consulta ambígua: falha fechada.
        $rows = DB::connection('mysql_legacy')->table('Clientes')
            ->where('ID', $id)->limit(2)->get(self::FIELDS);
        abort_if($rows->count() !== 1, $rows->isEmpty() ? 404 : 409);
        return response()->json($rows->first())->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, int $id)
    {
        $this->allowed($request);
        abort_unless(filter_var(env('CIS_CLIENTES_EDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN),
            503, 'Edição directa não activada.');
        $validated = $request->validate([
            'ponto_referencia' => ['nullable', 'string', 'max:255'],
            'local_trabalho_actividade' => ['nullable', 'string', 'max:180'],
            'fonte_rendimento_principal' => ['nullable', 'string', 'max:180'],
            'contacto_alternativo' => ['nullable', 'string', 'max:40'],
            'pessoa_referencia_nome' => ['nullable', 'string', 'max:180'],
            'pessoa_referencia_contacto' => ['nullable', 'string', 'max:40'],
            'melhor_horario_contacto' => ['nullable', 'date_format:H:i'],
            'tipo_cliente' => ['nullable', 'string', 'max:60'],
            'genero' => ['nullable', 'string', 'max:50'],
            'profissao' => ['nullable', 'string', 'max:150'],
            'tipo_documento' => ['nullable', 'string', 'max:80'],
            'nuit' => ['nullable', 'string', 'max:30'],
            'local_emissao_documento' => ['nullable', 'string', 'max:150'],
            'validade_documento' => ['nullable', 'date_format:Y-m-d'],
            'nome_pai' => ['nullable', 'string', 'max:180'],
            'nome_mae' => ['nullable', 'string', 'max:180'],
            'grau_parentesco_referencia' => ['nullable', 'string', 'max:100'],
            'referencia_contacto_informado' => ['required', 'boolean'],
        ]);
        $hasThirdParty = trim((string)($validated['pessoa_referencia_nome'] ?? '')) !== ''
            || trim((string)($validated['pessoa_referencia_contacto'] ?? '')) !== '';
        if ($hasThirdParty && (!$validated['referencia_contacto_informado']
            || trim((string)($validated['pessoa_referencia_nome'] ?? '')) === ''
            || trim((string)($validated['pessoa_referencia_contacto'] ?? '')) === '')) {
            return response()->json(['message' => 'Preencha nome e contacto da pessoa de referência e confirme que o cliente foi informado.'], 422);
        }

        // O utilizador mysql_editor dispõe de UPDATE apenas sobre os campos autorizados.
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $validated[$field] ?? null;
            $values[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        // MySQL TIME normaliza HH:MM para HH:MM:SS; DATE usa ISO YYYY-MM-DD.
        if ($values['melhor_horario_contacto'] !== null) {
            $values['melhor_horario_contacto'] .= ':00';
        }
        $db = DB::connection('mysql_editor');
        $db->transaction(function () use ($db, $id, $values) {
            $rows = $db->table('Clientes')->where('ID', $id)->lockForUpdate()
                ->limit(2)->get(['ID']);
            abort_if($rows->count() !== 1, $rows->isEmpty() ? 404 : 409);
            $db->table('Clientes')->where('ID', $id)->update($values);
        }, 3);
        return response()->json(['message' => 'Informações complementares actualizadas na ficha do cliente.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
