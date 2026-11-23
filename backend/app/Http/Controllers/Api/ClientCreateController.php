<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abertura de nova ficha de mutuário (INSERT em Clientes).
 * O número do mutuário (Numeração) e o ID são atribuídos pelo servidor, nunca pelo utilizador.
 * Usa a conta cis_editor, que precisa de INSERT em Clientes (deploy/enable-client-create.sh).
 */
final class ClientCreateController extends Controller
{
    /** Campos do formulário: os mesmos da ficha original editável. */
    public const FIELDS = ['Nome Completo', 'Nº de BI', 'Data de Emissão', 'Nacionalidade', 'Naturalidade',
        'Estado Civil', 'Contacto', 'Email', 'Provincia', 'Cidade', 'Bairro', 'Rua', 'Nº da Casa',
        'Nº de Quarterão', 'Conduta'];
    private const DATES = ['Data de Emissão'];
    private const LOCK = 'cis_clientes_nova_ficha';

    private function editor()
    {
        abort_unless(
            filter_var(env('CIS_CLIENTES_EDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            503,
            'Registo de mutuários não autorizado neste ambiente.'
        );
        return DB::connection('mysql_editor');
    }

    /** Estrutura real da tabela: tipo, obrigatoriedade, valor por omissão e auto-incremento. */
    private function columns($db): array
    {
        $out = [];
        foreach ($db->select("SELECT COLUMN_NAME n, DATA_TYPE t, IS_NULLABLE nul, COLUMN_DEFAULT d, EXTRA x,
                CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Clientes'") as $c) {
            $out[$c->n] = ['type' => strtolower((string) $c->t), 'nullable' => $c->nul === 'YES',
                'default' => $c->d, 'auto' => str_contains(strtolower((string) $c->x), 'auto_increment'),
                'len' => $c->len === null ? null : (int) $c->len];
        }
        return $out;
    }

    private function canInsert($db): bool
    {
        try {
            foreach ($db->select('SHOW GRANTS') as $g) {
                $line = (string) array_values((array) $g)[0];
                if (!preg_match('/ON\s+`?(\*|[^`.]+)`?\.`?(\*|Clientes)`?\s+TO/i', $line)) continue;
                if (preg_match('/\b(ALL PRIVILEGES|INSERT)\b/i', $line)) return true;
            }
        } catch (\Throwable) {
            return true; // sem SHOW GRANTS: o INSERT dirá se falta permissão
        }
        return false;
    }

    private static function numeric(string $type): bool
    {
        return in_array($type, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'decimal', 'double', 'float'], true);
    }

    /** Próximo número a partir do último: mantém prefixo, sufixo e zeros à esquerda (C-0099 → C-0100). */
    public static function increment(?string $last): string
    {
        $last = trim((string) $last);
        if ($last === '') return '1';
        if (!preg_match('/^(.*?)(\d+)(\D*)$/u', $last, $m)) return $last.'-1';
        $next = (string) ((int) $m[2] + 1);
        return $m[1].str_pad($next, strlen($m[2]), '0', STR_PAD_LEFT).$m[3];
    }

    private function nextNumber($db, array $cols)
    {
        if (!isset($cols['Numeração'])) return null;
        if (self::numeric($cols['Numeração']['type'])) {
            return (int) $db->table('Clientes')->max('Numeração') + 1;
        }
        // Texto: parte do registo mais recente com número; evita repetir um número já usado.
        $last = $db->table('Clientes')->whereNotNull('Numeração')->where('Numeração', '<>', '')
            ->orderByDesc('ID')->value('Numeração');
        $candidate = self::increment($last === null ? null : (string) $last);
        for ($i = 0; $i < 200 && $db->table('Clientes')->where('Numeração', $candidate)->exists(); $i++) {
            $candidate = self::increment($candidate);
        }
        return $candidate;
    }

    public function show(Request $request)
    {
        Permissions::require($request->user(), 'mutuarios.editar');
        $db = $this->editor();
        $cols = $this->columns($db);
        abort_if(!$cols, 503, 'Tabela Clientes não encontrada.');
        $required = [];
        foreach (self::FIELDS as $f) {
            if (isset($cols[$f]) && !$cols[$f]['nullable'] && $cols[$f]['default'] === null) $required[] = $f;
        }
        if (!in_array('Nome Completo', $required, true)) array_unshift($required, 'Nome Completo');
        return response()->json([
            'pronto' => $this->canInsert($db),
            'campos' => array_values(array_filter(self::FIELDS, fn ($f) => isset($cols[$f]))),
            'obrigatorios' => $required,
            'proximo_numero' => $this->nextNumber($db, $cols),
            'limites' => array_filter(array_map(fn ($f) => $cols[$f]['len'] ?? null, array_combine(self::FIELDS, self::FIELDS))),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        Permissions::require($user, 'mutuarios.editar');
        $input = $request->validate([
            'dados' => ['required', 'array'],
            'dados.*' => ['nullable', 'string', 'max:155'],
            'confirmar_duplicado' => ['sometimes', 'boolean'],
        ]);
        $values = [];
        foreach ($input['dados'] as $field => $value) {
            if (!in_array($field, self::FIELDS, true)) {
                throw ValidationException::withMessages(['dados' => 'Campo não autorizado: '.$field]);
            }
            $value = $value === null ? null : trim(preg_replace('/\s+/u', ' ', $value));
            $values[$field] = $value === '' ? null : $value;
        }
        if (mb_strlen((string) ($values['Nome Completo'] ?? '')) < 3) {
            throw ValidationException::withMessages(['Nome Completo' => 'Indique o nome completo (mínimo 3 letras).']);
        }
        if (($values['Contacto'] ?? null) !== null && !preg_match('/^\+?[0-9 ()\/-]{7,40}$/', $values['Contacto'])) {
            throw ValidationException::withMessages(['Contacto' => 'Contacto principal inválido: use só números (ex.: 84 123 4567).']);
        }
        if (($values['Email'] ?? null) !== null && !filter_var($values['Email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['Email' => 'Email inválido.']);
        }
        foreach (self::DATES as $d) {
            if (($values[$d] ?? null) === null) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $values[$d]) || !strtotime($values[$d])) {
                throw ValidationException::withMessages([$d => 'Data de emissão inválida.']);
            }
            if ($values[$d] > now('Africa/Maputo')->toDateString()) {
                throw ValidationException::withMessages([$d => 'A data de emissão não pode ser futura.']);
            }
        }

        $db = $this->editor();
        $cols = $this->columns($db);
        abort_if(!$cols, 503, 'Tabela Clientes não encontrada.');
        foreach ($values as $f => $v) {
            if (!isset($cols[$f])) { unset($values[$f]); continue; }
            if ($v !== null && $cols[$f]['len'] && mb_strlen($v) > $cols[$f]['len']) {
                throw ValidationException::withMessages([$f => "«{$f}» aceita no máximo {$cols[$f]['len']} caracteres."]);
            }
            if ($v !== null && self::numeric($cols[$f]['type'])) {
                $digits = preg_replace('/\D/', '', $v);
                if ($digits === '') throw ValidationException::withMessages([$f => "«{$f}» só aceita números."]);
                $values[$f] = $digits;
            }
        }
        foreach (self::FIELDS as $f) {
            if (isset($cols[$f]) && !$cols[$f]['nullable'] && $cols[$f]['default'] === null && ($values[$f] ?? null) === null) {
                throw ValidationException::withMessages([$f => "«{$f}» é obrigatório no cadastro original."]);
            }
        }

        // Duplicados: BI igual bloqueia; nome igual pede confirmação.
        if (($values['Nº de BI'] ?? null) !== null && isset($cols['Nº de BI'])) {
            $dup = $db->table('Clientes')->where('Nº de BI', $values['Nº de BI'])->first(['ID', 'Nome Completo']);
            if ($dup) {
                return response()->json(['message' => "Já existe um mutuário com este BI: {$dup->{'Nome Completo'}} (ID {$dup->ID}).",
                    'existente' => (int) $dup->ID], 409);
            }
        }
        if (empty($input['confirmar_duplicado'])) {
            $same = $db->table('Clientes')->where('Nome Completo', $values['Nome Completo'])->limit(3)->get(['ID', 'Cidade']);
            if ($same->isNotEmpty()) {
                return response()->json(['message' => 'Já existe mutuário com o mesmo nome. Confirme se é outra pessoa.',
                    'duplicado_nome' => $same->map(fn ($r) => ['id' => (int) $r->ID, 'cidade' => $r->Cidade])->values()], 409);
            }
        }

        $actor = mb_substr((string) $user->name, 0, 255);
        try {
            abort_unless((int) ($db->selectOne('SELECT GET_LOCK(?, 10) l', [self::LOCK])->l ?? 0) === 1, 423,
                'Outro registo está a ser gravado. Tente de novo dentro de instantes.');
            try {
                $result = $db->transaction(function () use ($db, $cols, $values, $actor, $user) {
                    $row = $values;
                    $numero = $this->nextNumber($db, $cols);
                    if ($numero !== null) $row['Numeração'] = $numero;
                    if (isset($cols['ID']) && !$cols['ID']['auto']) $row['ID'] = (int) $db->table('Clientes')->max('ID') + 1;
                    if (isset($cols['usuario'])) {
                        $row['usuario'] = self::numeric($cols['usuario']['type']) ? (int) $user->getKey()
                            : mb_substr($actor, 0, $cols['usuario']['len'] ?: 255);
                    }
                    // Colunas antigas obrigatórias sem valor por omissão e fora do formulário.
                    foreach ($cols as $name => $c) {
                        if (array_key_exists($name, $row) || $c['auto'] || $c['nullable'] || $c['default'] !== null) continue;
                        $row[$name] = self::numeric($c['type']) ? 0
                            : (in_array($c['type'], ['date', 'datetime', 'timestamp'], true) ? now('Africa/Maputo')->toDateString()
                            : ($c['type'] === 'time' ? '00:00:00' : ''));
                    }
                    if (isset($cols['ID']) && $cols['ID']['auto']) {
                        $id = (int) $db->table('Clientes')->insertGetId($row, 'ID');
                    } else {
                        $db->table('Clientes')->insert($row);
                        $id = (int) ($row['ID'] ?? $db->table('Clientes')->where('Numeração', $numero)->value('ID'));
                    }
                    try {
                        $db->table('cis_client_edit_audit')->insert([
                            'client_id' => $id, 'actor_user_id' => $user->getKey(), 'field_name' => '(nova ficha)',
                            'old_value' => null, 'new_value' => mb_substr((string) $numero.' · '.$values['Nome Completo'], 0, 255),
                            'changed_at' => now(),
                        ]);
                    } catch (\Throwable) {
                        // auditoria opcional: não impede o registo
                    }
                    return ['id' => $id, 'numero' => $numero];
                }, 3);
            } finally {
                $db->select('SELECT RELEASE_LOCK(?)', [self::LOCK]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $code = (int) ($e->errorInfo[1] ?? 0);
            $msg = in_array($code, [1142, 1143], true)
                ? 'sem permissão para criar fichas. Na VPS execute: bash deploy/enable-client-create.sh'
                : ($code === 1062 ? 'número de mutuário repetido; tente de novo.' : LegacyClienteEditController::mysqlMessage($e));
            return response()->json(['message' => 'Não registado: '.$msg], 503);
        }
        // Quem cadastra fica como agente do mutuário (carteira do agente).
        try { \App\Support\Portfolio::assignClient((int) $result['id'], (int) $user->getKey(), (int) $user->getKey()); } catch (\Throwable) {}
        return response()->json($result + ['mensagem' => 'Mutuário registado.'], 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
