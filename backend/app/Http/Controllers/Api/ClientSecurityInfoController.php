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
        // Aceita os formatos que a própria ficha devolve (HH:MM:SS e data com hora).
        $t = trim((string)$request->input('melhor_horario_contacto', ''));
        $dv = trim((string)$request->input('validade_documento', ''));
        $request->merge([
            'melhor_horario_contacto' => preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $mt) ? sprintf('%02d:%s', $mt[1], $mt[2]) : ($t === '' ? null : $t),
            'validade_documento' => preg_match('/^\d{4}-\d{2}-\d{2}/', $dv) ? substr($dv, 0, 10) : ($dv === '' ? null : $dv),
        ]);
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
        // Nenhum campo complementar é obrigatório; com dados de terceiros só se exige a confirmação.
        if ($hasThirdParty && !$validated['referencia_contacto_informado']) {
            return response()->json(['message' => 'Confirme (no separador Referências) que o mutuário foi informado sobre a finalidade dos dados da pessoa de referência.'], 422);
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
        // Só se gravam os campos que mudaram (menos permissões necessárias e histórico mais limpo).
        $current = DB::connection('mysql_legacy')->table('Clientes')->where('ID', $id)->limit(2)->get(self::FIELDS);
        abort_if($current->count() !== 1, $current->isEmpty() ? 404 : 409);
        $cur = (array)$current->first();
        $norm = function (string $f, $v) {
            if ($v === null) return null;
            $v = trim((string)$v);
            if ($v === '') return null;
            if ($f === 'validade_documento') return substr($v, 0, 10);
            if ($f === 'melhor_horario_contacto') return substr(strlen($v) === 5 ? $v . ':00' : $v, 0, 8);
            return $v;
        };
        $changes = array_filter($values, fn($v, $f) => $norm($f, $v) !== $norm($f, $cur[$f] ?? null), ARRAY_FILTER_USE_BOTH);
        if (!$changes) {
            return response()->json(['message' => 'Sem alterações nas informações complementares.'])->header('Cache-Control', 'no-store, private');
        }
        $db = DB::connection('mysql_editor');
        try {
            $db->transaction(function () use ($db, $id, $changes) {
                $rows = $db->table('Clientes')->where('ID', $id)->lockForUpdate()
                    ->limit(2)->get(['ID']);
                abort_if($rows->count() !== 1, $rows->isEmpty() ? 404 : 409);
                $db->table('Clientes')->where('ID', $id)->update($changes);
            }, 3);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $code = (int)($e->errorInfo[1] ?? 0);
            $fields = implode(', ', array_keys($changes));
            $msg = match (true) {
                in_array($code, [1142, 1143], true) => 'a conta de edição (cis_editor) não tem permissão para gravar estes campos ('.$fields.'). Na VPS execute: bash deploy/enable-secondary-editor.sh e depois bash deploy/refresh-private-preview.sh',
                in_array($code, [1044, 1045], true) => 'a conta de edição (cis_editor) não consegue entrar no MySQL. Verifique CLIENT_EDITOR_DB_PASSWORD em deploy/.env e execute bash deploy/enable-secondary-editor.sh',
                $code === 1054 => 'falta uma coluna complementar na tabela Clientes. Na VPS execute: bash deploy/activate-client-secondary-columns.sh',
                $code === 1406 => 'um dos campos é maior do que a coluna permite ('.$fields.'). Encurte o texto.',
                in_array($code, [1292, 1366], true) => 'valor inválido para a coluna (data ou hora). Verifique: '.$fields.'.',
                default => 'erro MySQL '.$code.' ao gravar ('.$fields.').',
            };
            return response()->json(['message' => $msg, 'codigo_mysql' => $code], 503);
        }
        return response()->json(['message' => 'Informações complementares actualizadas na ficha do mutuário.'])
            ->header('Cache-Control', 'no-store, private');
    }
}
