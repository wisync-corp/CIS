<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Parametrização (Definições): tabela de preços (cis_tabela_juros) e tipos de movimento (cis_tipos_movimento).
 * Alterações afectam só simulações e contratos novos; cada alteração fica registada em cis_parametrizacao_log.
 */
class ParametrizacaoController
{
    public const PERIODOS = [15, 30];
    public const GRUPOS = [
        'Entradas' => ['Receita operacional', 'Entrada não operacional'],
        'Saídas' => ['Operação de crédito', 'Despesa operacional', 'Investimento', 'Saída não operacional'],
    ];
    /** Mesmo formato aceite pela categoria da Caixa. */
    private const NOME = '/^[\pL\pN _-]{2,32}$/u';

    private static function admin(Request $r): void
    {
        \App\Support\Permissions::require($r->user(),'definicoes');
    }

    private static function ready(): void
    {
        abort_unless(Schema::hasTable('cis_tabela_juros') && Schema::hasTable('cis_tipos_movimento'), 503,
            'As tabelas de parametrização ainda não foram criadas no MySQL.');
    }

    private static function editable(): bool
    {
        return Schema::hasTable('cis_parametrizacao_log') && Schema::hasColumn('cis_tipos_movimento', 'activo');
    }

    /** Faixas: uma linha por intervalo, com a taxa de cada período. */
    public static function faixas(): array
    {
        $out = [];
        foreach (DB::table('cis_tabela_juros')->orderBy('limite_inferior')->orderBy('limite_superior')->get() as $r) {
            $k = $r->limite_inferior . '-' . $r->limite_superior;
            $out[$k] ??= ['de' => (int)$r->limite_inferior, 'ate' => (int)$r->limite_superior, 'taxa_15' => null, 'taxa_30' => null];
            if (in_array((int)$r->periodo_referencia, self::PERIODOS, true))
                $out[$k]['taxa_' . (int)$r->periodo_referencia] = rtrim(rtrim(number_format((float)$r->taxa, 2, '.', ''), '0'), '.');
        }
        return array_values($out);
    }

    private static function movimentos(bool $onlyActive = false): array
    {
        $cols = ['id', 'tipo_movimento', 'descricao'];
        $ed = self::editable();
        $q = DB::table('cis_tipos_movimento')->orderBy('tipo_movimento')->orderBy('descricao');
        if ($ed && $onlyActive) $q->where('activo', true);
        return $q->get($ed ? array_merge($cols, ['grupo', 'activo', 'protegido']) : $cols)->map(fn($r) => [
            'id' => (int)$r->id, 'tipo_movimento' => $r->tipo_movimento, 'descricao' => $r->descricao,
            'grupo' => $r->grupo ?? null, 'activo' => $ed ? (bool)$r->activo : true, 'protegido' => $ed ? (bool)$r->protegido : false,
        ])->all();
    }

    public static function lacunas(array $f): array
    {
        $gaps = [];
        $top = $f[0]['ate'] ?? 0;
        for ($i = 1; $i < count($f); $i++) { if ($f[$i]['de'] > $top + 1) $gaps[] = [$top + 1, $f[$i]['de'] - 1]; $top = max($top, $f[$i]['ate']); }
        return $gaps;
    }

    public function show(Request $request): JsonResponse
    {
        self::admin($request); self::ready();
        $f = self::faixas();
        $log = self::editable() ? DB::table('cis_parametrizacao_log')->orderByDesc('id')->limit(8)->get(['tabela', 'accao', 'por', 'em'])->all() : [];
        return response()->json(['precos' => $f, 'lacunas' => self::lacunas($f), 'periodos' => self::PERIODOS, 'movimentos' => self::movimentos(),
            'grupos' => self::GRUPOS, 'editavel' => self::editable(), 'historico' => $log]);
    }

    /** Tipos activos para a Caixa (qualquer utilizador autenticado). */
    public function tiposActivos(): JsonResponse
    {
        if (!Schema::hasTable('cis_tipos_movimento')) return response()->json(['movimentos' => []]);
        return response()->json(['movimentos' => array_map(fn($m) => ['tipo_movimento' => $m['tipo_movimento'], 'descricao' => $m['descricao'], 'grupo' => $m['grupo']], self::movimentos(true))]);
    }

    public function guardarPrecos(Request $request): JsonResponse
    {
        self::admin($request); self::ready();
        abort_unless(self::editable(), 503, 'Parametrização ainda não activada. Execute: bash deploy/install-company-settings.sh');
        $rows = $request->input('faixas');
        abort_unless(is_array($rows) && count($rows) > 0 && count($rows) <= 60, 422, 'Indique entre 1 e 60 faixas.');
        $errs = []; $clean = [];
        foreach (array_values($rows) as $i => $r) {
            $n = $i + 1;
            $de = filter_var($r['de'] ?? null, FILTER_VALIDATE_INT); $ate = filter_var($r['ate'] ?? null, FILTER_VALIDATE_INT);
            if ($de === false || $ate === false || $de < 0 || $ate > 100000000) { $errs["faixas.$i"] = "Faixa $n: valores inteiros entre 0 e 100 000 000."; continue; }
            if ($ate < $de) { $errs["faixas.$i"] = "Faixa $n: o valor final é menor que o inicial."; continue; }
            $t = [];
            foreach (self::PERIODOS as $p) {
                $v = $r['taxa_' . $p] ?? null; $v = is_string($v) ? str_replace(',', '.', trim($v)) : $v;
                if ($v === null || $v === '') continue;
                if (!is_numeric($v) || (float)$v <= 0 || (float)$v > 100) { $errs["faixas.$i"] = "Faixa $n: taxa de $p dias entre 0 e 100%."; continue 2; }
                $t[$p] = round((float)$v, 2);
            }
            if (!$t) { $errs["faixas.$i"] = "Faixa $n: indique pelo menos uma taxa."; continue; }
            $clean[] = ['de' => $de, 'ate' => $ate, 't' => $t, 'i' => $i];
        }
        usort($clean, fn($a, $b) => $a['de'] <=> $b['de']);
        $best = $clean[0] ?? null;
        for ($k = 1; $k < count($clean); $k++) {
            if ($clean[$k]['de'] <= $best['ate'])
                $errs['faixas.' . $clean[$k]['i']] = sprintf('As faixas %s–%s e %s–%s sobrepõem-se.', $best['de'], $best['ate'], $clean[$k]['de'], $clean[$k]['ate']);
            if ($clean[$k]['ate'] > $best['ate']) $best = $clean[$k];
        }
        if ($errs) throw ValidationException::withMessages($errs);
        $before = self::faixas();
        DB::transaction(function () use ($clean, $before, $request) {
            DB::table('cis_tabela_juros')->lockForUpdate()->get(['id']);
            DB::table('cis_tabela_juros')->delete();
            foreach ($clean as $c) foreach ($c['t'] as $p => $tx)
                DB::table('cis_tabela_juros')->insert(['limite_inferior' => $c['de'], 'limite_superior' => $c['ate'], 'taxa' => $tx, 'periodo_referencia' => $p]);
            self::log('tabela_juros', 'actualizar', $before, self::faixas(), $request);
        });
        return $this->show($request);
    }

    public function criarMovimento(Request $request): JsonResponse
    {
        self::admin($request); self::ready();
        abort_unless(self::editable(), 503, 'Parametrização ainda não activada. Execute: bash deploy/install-company-settings.sh');
        $tipo = (string)$request->input('tipo_movimento'); $desc = trim(preg_replace('/\s+/u', ' ', (string)$request->input('descricao')));
        $grupo = $request->input('grupo');
        $errs = [];
        if (!isset(self::GRUPOS[$tipo])) $errs['tipo_movimento'] = 'Escolha Entradas ou Saídas.';
        if (!preg_match(self::NOME, $desc)) $errs['descricao'] = 'Nome com 2 a 32 letras, números, espaços ou hífen.';
        if (isset(self::GRUPOS[$tipo]) && !in_array($grupo, self::GRUPOS[$tipo], true)) $errs['grupo'] = 'Grupo inválido para ' . $tipo . '.';
        if (!$errs && DB::table('cis_tipos_movimento')->where('tipo_movimento', $tipo)->whereRaw('LOWER(descricao)=?', [mb_strtolower($desc)])->exists())
            $errs['descricao'] = 'Já existe um tipo com este nome.';
        if ($errs) throw ValidationException::withMessages($errs);
        DB::transaction(function () use ($tipo, $desc, $grupo, $request) {
            $id = DB::table('cis_tipos_movimento')->insertGetId(['tipo_movimento' => $tipo, 'descricao' => $desc, 'grupo' => $grupo, 'activo' => true, 'protegido' => false]);
            self::log('tipos_movimento', 'criar', null, ['id' => $id, 'tipo_movimento' => $tipo, 'descricao' => $desc, 'grupo' => $grupo], $request);
        });
        return $this->show($request);
    }

    public function actualizarMovimento(Request $request, int $id): JsonResponse
    {
        self::admin($request); self::ready();
        abort_unless(self::editable(), 503, 'Parametrização ainda não activada. Execute: bash deploy/install-company-settings.sh');
        $m = DB::table('cis_tipos_movimento')->where('id', $id)->first();
        abort_unless($m, 404, 'Tipo de movimento inexistente.');
        $upd = []; $errs = [];
        if ($request->has('descricao')) {
            $desc = trim(preg_replace('/\s+/u', ' ', (string)$request->input('descricao')));
            if ($desc !== $m->descricao) {
                if ($m->protegido) $errs['descricao'] = 'Tipo base do sistema: o nome não pode ser alterado.';
                elseif (!preg_match(self::NOME, $desc)) $errs['descricao'] = 'Nome com 2 a 32 letras, números, espaços ou hífen.';
                elseif (DB::table('cis_tipos_movimento')->where('id', '<>', $id)->where('tipo_movimento', $m->tipo_movimento)->whereRaw('LOWER(descricao)=?', [mb_strtolower($desc)])->exists()) $errs['descricao'] = 'Já existe um tipo com este nome.';
                else $upd['descricao'] = $desc;
            }
        }
        if ($request->has('grupo')) {
            $g = $request->input('grupo');
            if (!in_array($g, self::GRUPOS[$m->tipo_movimento] ?? [], true)) $errs['grupo'] = 'Grupo inválido.'; else $upd['grupo'] = $g;
        }
        if ($request->has('activo')) {
            $a = filter_var($request->input('activo'), FILTER_VALIDATE_BOOLEAN);
            if (!$a && $m->protegido) $errs['activo'] = 'Tipo base do sistema: não pode ser desactivado.'; else $upd['activo'] = $a;
        }
        if ($errs) throw ValidationException::withMessages($errs);
        if ($upd) DB::transaction(function () use ($id, $m, $upd, $request) {
            DB::table('cis_tipos_movimento')->where('id', $id)->update($upd);
            self::log('tipos_movimento', 'actualizar', (array)$m, (array)DB::table('cis_tipos_movimento')->where('id', $id)->first(), $request);
        });
        return $this->show($request);
    }

    private static function log(string $t, string $a, $before, $after, Request $r): void
    {
        DB::table('cis_parametrizacao_log')->insert(['tabela' => $t, 'accao' => $a, 'antes' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'depois' => json_encode($after, JSON_UNESCAPED_UNICODE), 'por' => mb_substr((string)($r->user()?->name ?? $r->user()?->email ?? 'admin'), 0, 120), 'em' => now('Africa/Maputo')]);
    }
}
