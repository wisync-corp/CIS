<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LegacyClienteEditController extends Controller
{
    // Toda a ficha original é editável, excepto o número do mutuário (Numeração), o ID interno e o
    // utilizador associado. Cada coluna só grava se a conta cis_editor tiver permissão UPDATE nela
    // (deploy/enable-client-full-edit.sh); as restantes aparecem como «necessita activação».
    private const FIELDS = ['Nome Completo', 'Bairro', 'Cidade', 'Provincia', 'Rua',
        'Contacto', 'Email', 'Nº de BI', 'Data de Emissão', 'Nacionalidade', 'Naturalidade',
        'Estado Civil', 'Nº da Casa', 'Nº de Quarterão', 'Conduta'];
    private const DATES = ['Data de Emissão'];
    private const MAX = 155;

    private function editor()
    {
        abort_unless(
            filter_var(env('CIS_CLIENTES_EDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            503,
            'Edição de mutuários não autorizada neste ambiente.'
        );
        return DB::connection('mysql_editor');
    }

    /** Colunas de FIELDS que existem e em que a conta de edição tem UPDATE (SHOW GRANTS). */
    private function writable($db): array
    {
        $cols = [];
        $all = false;
        foreach ($db->select('SHOW GRANTS') as $g) {
            $line = (string) array_values((array) $g)[0];
            if (!preg_match('/ON\s+`?(\*|[^`.]+)`?\.`?(\*|Clientes)`?\s+TO/i', $line)) continue;
            if (preg_match('/\b(ALL PRIVILEGES|UPDATE)\b(?!\s*\()/i', $line)) $all = true;
            if (preg_match_all('/UPDATE\s*\(([^)]*)\)/i', $line, $m)) {
                foreach ($m[1] as $list) foreach (explode(',', $list) as $c) $cols[] = trim($c, " `");
            }
        }
        return array_values(array_filter(self::FIELDS, fn ($f) => $all || in_array($f, $cols, true)));
    }

    private static function str($v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    public function show(Request $request, int $id)
    {
        $db = $this->editor();
        $have = array_flip($db->getSchemaBuilder()->getColumnListing('Clientes'));
        $fields = array_values(array_filter(self::FIELDS, fn ($f) => isset($have[$f])));
        $records = $db->table('Clientes')->where('ID', $id)->limit(2)
            ->get(array_merge(['ID'], $fields));
        abort_if($records->count() !== 1, $records->isEmpty() ? 404 : 409,
            'ID de mutuário inexistente ou não exclusivo.');
        try { $writable = $this->writable($db); } catch (\Throwable) { $writable = ['Nome Completo', 'Bairro', 'Cidade', 'Provincia', 'Rua']; }
        return response()->json(['registo' => $records->first(),
            'editaveis' => array_values(array_intersect($writable, $fields)),
            'protegidos' => ['Numeração', 'ID', 'usuario']]);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*' => ['nullable', 'string', 'max:'.self::MAX],
            'original' => ['required', 'array'],
            'original.*' => ['nullable', 'string', 'max:'.self::MAX],
        ]);
        $changes = $validated['changes'];
        if (count($changes) > count(self::FIELDS)) {
            throw ValidationException::withMessages(['changes' => 'Demasiados campos para edição.']);
        }
        $original = $validated['original'];
        foreach ($changes as $field => $value) {
            if (!in_array($field, self::FIELDS, true) || !array_key_exists($field, $original)) {
                throw ValidationException::withMessages(['changes' => 'Campo não autorizado.']);
            }
            if ($field === 'Nome Completo' && (!is_string($value) || mb_strlen(trim($value)) < 3)) {
                throw ValidationException::withMessages(['changes' => 'O nome deve ter pelo menos 3 caracteres.']);
            }
            $value = $value === null ? null : trim($value);
            if ($value === '') $value = null;
            if ($field === 'Contacto' && $value !== null && !preg_match('/^\+?[0-9 ()\/-]{7,40}$/', $value)) {
                throw ValidationException::withMessages(['changes' => 'Contacto principal inválido: use só números (ex.: 84 123 4567).']);
            }
            if ($field === 'Email' && $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['changes' => 'Email inválido.']);
            }
            if (in_array($field, self::DATES, true) && $value !== null) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) throw ValidationException::withMessages(['changes' => 'Data de emissão inválida.']);
                $value = substr($value, 0, 10);
            }
            $changes[$field] = $value;
            if (!is_null($original[$field]) && !is_string($original[$field])) {
                throw ValidationException::withMessages(['original' => 'Valor original inválido.']);
            }
        }
        $db = $this->editor();
        try { $writable = $this->writable($db); } catch (\Throwable) { $writable = self::FIELDS; }
        $blocked = array_values(array_diff(array_keys($changes), $writable));
        if ($blocked) {
            return response()->json(['message' => 'Sem permissão de gravação para: '.implode(', ', $blocked)
                .'. Na VPS execute: bash deploy/enable-client-full-edit.sh e depois bash deploy/refresh-private-preview.sh'], 503);
        }
        try { $result = $db->transaction(function () use ($db, $id, $changes, $original, $request) {
            // Lock only the row to guard against concurrent changes and validate unique ID.
            $records = $db->table('Clientes')->where('ID', $id)->lockForUpdate()
                ->limit(2)->get(array_merge(['ID'], array_keys($changes)));
            abort_if($records->count() !== 1, $records->isEmpty() ? 404 : 409,
                'ID de mutuário inexistente ou não exclusivo.');
            $current = (array) $records->first();
            $diff = [];
            // Comparação como texto (números e datas podem vir com outro tipo/formato).
            $norm = fn ($f, $v) => $v === null || $v === '' ? null : (in_array($f, self::DATES, true) ? substr((string) $v, 0, 10) : (string) $v);
            foreach ($changes as $field => $newValue) {
                $old = self::str($current[$field]);
                if ($norm($field, $original[$field] ?? null) !== $norm($field, $old)) {
                    abort(409, 'Este mutuário foi modificado entretanto. Actualize a página antes de guardar.');
                }
                if ($norm($field, $old) !== $norm($field, $newValue)) {
                    $diff[$field] = ['before' => $old, 'after' => $newValue];
                }
            }
            if (!$diff) return ['updated' => false];
            $db->table('Clientes')->where('ID', $id)->update(
                array_map(fn ($v) => $v['after'], $diff)
            );
            foreach ($diff as $field => $values) {
                $db->table('cis_client_edit_audit')->insert([
                    'client_id' => $id, 'actor_user_id' => $request->user()->getKey(),
                    'field_name' => $field, 'old_value' => $values['before'],
                    'new_value' => $values['after'], 'changed_at' => now(),
                ]);
            }
            return ['updated' => true, 'fields_changed' => array_keys($diff)];
        }, 3); } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            return response()->json(['message' => 'Não guardado: '.self::mysqlMessage($e)], 503);
        }
        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public static function mysqlMessage(\Illuminate\Database\QueryException $e): string
    {
        return match ((int) ($e->errorInfo[1] ?? 0)) {
            1142, 1143 => 'sem permissão de gravação neste campo. Na VPS execute: bash deploy/enable-client-full-edit.sh',
            1406 => 'texto maior do que a coluna permite.',
            1292, 1366 => 'valor inválido para a coluna (número ou data).',
            default => 'erro MySQL '.(int) ($e->errorInfo[1] ?? 0).'.',
        };
    }
}
