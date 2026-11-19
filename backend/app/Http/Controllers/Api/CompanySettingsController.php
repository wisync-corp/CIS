<?php

namespace App\Http\Controllers\Api;

use App\Support\CompanySettings as CS;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Configurações da empresa ao estilo GEMIC: perfil, cobrança e multa, credit scoring e incentivos. */
class CompanySettingsController
{
    private const MAX_LOGO_BYTES = 400_000;

    public function show(Request $request): JsonResponse
    {
        $s = CS::load(true);
        if ($request->user()?->role !== 'administrador') {
            return response()->json(['perfil' => CS::profile(), 'instalado' => $s['instalado']]);
        }
        return response()->json($s + ['perfil' => CS::profile(), 'provincias' => CS::PROVINCIAS]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->role === 'administrador', 403, 'Só o administrador altera as configurações da empresa.');
        $in = $request->input('valores');
        abort_unless(is_array($in) && $in !== [], 422, 'Nada para guardar.');
        $clean = []; $errors = [];
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, CS::FIELDS)) { $errors[$k] = 'Campo desconhecido.'; continue; }
            [$msg, $val] = self::check($k, $v);
            if ($msg) $errors[$k] = $msg; else $clean[$k] = $val;
        }
        $merged = array_merge(CS::load(true)['valores'], $clean);
        if ((int)$merged['score_risco_medio'] > (int)$merged['score_baixo_risco'])
            $errors['score_risco_medio'] = 'O limiar de risco médio não pode ser superior ao de baixo risco.';
        if ($errors) throw ValidationException::withMessages($errors);
        $who = (string)($request->user()?->name ?? $request->user()?->email ?? 'admin');
        try {
            DB::transaction(function () use ($clean, $who) {
                $now = now('Africa/Maputo');
                foreach ($clean as $k => $v) {
                    DB::table('cis_company_settings')->updateOrInsert(['chave' => $k], ['valor' => $v, 'updated_by' => mb_substr($who, 0, 120), 'updated_at' => $now, 'created_at' => $now]);
                }
            });
        } catch (\Illuminate\Database\QueryException $e) {
            abort(503, ($e->errorInfo[1] ?? null) === 1146
                ? 'Configurações ainda não instaladas na base (MySQL 1146). Execute: bash deploy/install-company-settings.sh'
                : 'Não foi possível guardar as configurações.');
        }
        CS::forget();
        return response()->json(CS::load(true) + ['perfil' => CS::profile(), 'provincias' => CS::PROVINCIAS, 'guardados' => array_keys($clean)]);
    }

    /** @return array{0:?string,1:string} */
    public static function check(string $k, mixed $v): array
    {
        [, $type, $rule] = CS::FIELDS[$k];
        if (is_bool($v)) $v = $v ? '1' : '0';
        if (!is_scalar($v) && $v !== null) return ['Valor inválido.', ''];
        $s = trim((string)($v ?? ''));
        if ($s === '' && !in_array($type, ['booleano', 'lista', 'inteiro', 'valor', 'percentagem'], true)) return [null, ''];
        switch ($type) {
            case 'texto':  return mb_strlen($s) > $rule ? ["Máximo de $rule caracteres.", ''] : [null, $s];
            case 'nuit':   return preg_match('/^\d{9}$/', $s) ? [null, $s] : ['O NUIT tem 9 dígitos.', ''];
            case 'email':  return filter_var($s, FILTER_VALIDATE_EMAIL) ? [null, mb_strtolower($s)] : ['Email inválido.', ''];
            case 'data':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
                return $d && $d->format('Y-m-d') === $s && $s <= now('Africa/Maputo')->toDateString() ? [null, $s] : ['Data inválida (não pode ser futura).', ''];
            case 'inteiro':
                if ($s === '' || !preg_match('/^\d+$/', $s)) return ['Indique um número inteiro.', ''];
                return ((int)$s < $rule[0] || (int)$s > $rule[1]) ? ["Entre {$rule[0]} e {$rule[1]}.", ''] : [null, (string)(int)$s];
            case 'valor':
                $s = str_replace([' ', ','], ['', '.'], $s);
                return is_numeric($s) && (float)$s >= 0 && (float)$s < 1e12 ? [null, number_format((float)$s, 2, '.', '')] : ['Valor inválido (≥ 0).', ''];
            case 'percentagem':
                $s = str_replace(',', '.', $s);
                return is_numeric($s) && (float)$s >= 0 && (float)$s <= 100 ? [null, rtrim(rtrim(number_format((float)$s, 4, '.', ''), '0'), '.')] : ['Percentagem entre 0 e 100.', ''];
            case 'booleano':
                return in_array($s, ['1', '0', 'true', 'false', 'sim', 'nao', 'não'], true) ? [null, in_array($s, ['1', 'true', 'sim'], true) ? '1' : '0'] : ['Sim ou Não.', ''];
            case 'lista':
                return in_array($s, $rule, true) || ($s === '' && $k === 'provincia') ? [null, $s] : ['Opção inválida.', ''];
            case 'imagem':
                if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $s, $m)) return ['Logótipo: PNG, JPG ou WEBP.', ''];
                $bin = base64_decode($m[2], true);
                if ($bin === false || strlen($bin) > self::MAX_LOGO_BYTES) return ['Logótipo até 400 KB.', ''];
                $info = @getimagesizefromstring($bin);
                return $info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true) ? [null, $s] : ['A imagem não é válida.', ''];
        }
        return ['Tipo desconhecido.', ''];
    }
}
