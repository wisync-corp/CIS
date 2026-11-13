<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LegacyClienteEditController extends Controller
{
    // First safe phase: only verified text-based cadastral columns (no financial or identity fields).
    private const FIELDS = ['Nome Completo', 'Bairro', 'Cidade', 'Provincia', 'Rua'];
    private const MAX = 155;

    private function editor()
    {
        abort_unless(
            filter_var(env('CIS_CLIENTES_EDIT_APPROVED', false), FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('CIS_PRIVATE_PREVIEW', false), FILTER_VALIDATE_BOOLEAN),
            503,
            'Edição de clientes não autorizada neste ambiente.'
        );
        return DB::connection('mysql_editor');
    }

    public function show(Request $request, int $id)
    {
        $db = $this->editor();
        $records = $db->table('Clientes')->where('ID', $id)->limit(2)
            ->get(array_merge(['ID'], self::FIELDS));
        abort_if($records->count() !== 1, $records->isEmpty() ? 404 : 409,
            'ID de cliente inexistente ou não exclusivo.');
        return response()->json($records->first());
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'changes.*' => ['nullable', 'string', 'max:'.self::MAX],
            'original' => ['required', 'array'],
        ]);
        $changes = $validated['changes'];
        $original = $validated['original'];
        foreach ($changes as $field => $value) {
            if (!in_array($field, self::FIELDS, true) || !array_key_exists($field, $original)) {
                throw ValidationException::withMessages(['changes' => 'Campo não autorizado.']);
            }
            if ($field === 'Nome Completo' && (!is_string($value) || mb_strlen(trim($value)) < 3)) {
                throw ValidationException::withMessages(['changes' => 'O nome deve ter pelo menos 3 caracteres.']);
            }
            $changes[$field] = $value === null ? null : trim($value);
            if (!is_null($original[$field]) && !is_string($original[$field])) {
                throw ValidationException::withMessages(['original' => 'Valor original inválido.']);
            }
        }
        $db = $this->editor();
        $result = $db->transaction(function () use ($db, $id, $changes, $original, $request) {
            // Lock only the row to guard against concurrent changes and validate unique ID.
            $records = $db->table('Clientes')->where('ID', $id)->lockForUpdate()
                ->limit(2)->get(array_merge(['ID'], self::FIELDS));
            abort_if($records->count() !== 1, $records->isEmpty() ? 404 : 409,
                'ID de cliente inexistente ou não exclusivo.');
            $current = (array) $records->first();
            $diff = [];
            foreach ($changes as $field => $newValue) {
                $old = $current[$field];
                if (($original[$field] ?? null) !== $old) {
                    abort(409, 'Este cliente foi modificado entretanto. Actualize a página antes de guardar.');
                }
                if ($old !== $newValue) {
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
        }, 3);
        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }
}
