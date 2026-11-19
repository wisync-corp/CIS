<?php

namespace App\Services;

use App\Support\CompanySettings as CS;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeração anual dos contratos: PREFIXO-ANO-SEQUÊNCIA (ex.: CAPTA-2026-0001).
 * A sequência recomeça em 1 em cada ano, pela data de desembolso (primeira Data de Inicio do contrato).
 */
final class ContractNumbering
{
    public static function prefix(): string
    {
        $p = strtoupper(trim(CS::get('contrato_prefixo')));
        return preg_match('/^[A-Z0-9]{1,10}$/', $p) ? $p : 'CAPTA';
    }

    public static function digits(): int
    {
        return max(3, min(6, (int)CS::get('contrato_digitos') ?: 4));
    }

    public static function active(): bool
    {
        return CS::get('numeracao_anual_activa') === '1';
    }

    public static function format(int $year, int $seq, ?string $prefix = null, ?int $digits = null): string
    {
        return ($prefix ?? self::prefix()) . '-' . $year . '-' . str_pad((string)$seq, $digits ?? self::digits(), '0', STR_PAD_LEFT);
    }

    /** Próximo número do ano (chamar com o GET_LOCK da abertura de contratos). */
    public static function next(ConnectionInterface $db, string $dataInicio): string
    {
        $year = (int)substr($dataInicio, 0, 4);
        $prefix = self::prefix();
        $like = $prefix . '-' . $year . '-%';
        $max = 0;
        foreach ($db->table('Contractos')->where('Nr do Contracto', 'like', $like)->distinct()->pluck('Nr do Contracto') as $n) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-' . $year . '-(\d+)$/', (string)$n, $m)) $max = max($max, (int)$m[1]);
        }
        return self::format($year, $max + 1, $prefix);
    }

    /**
     * Plano da renumeração de todos os contratos: ordenados pela data de desembolso e, no mesmo dia,
     * pela ordem de registo. @return array{linhas:list<array>,sem_data:list<string>}
     */
    public static function plan(ConnectionInterface $db, ?string $prefix = null, ?int $digits = null): array
    {
        $rows = $db->table('Contractos')->selectRaw('`Nr do Contracto` AS n, MIN(`Data de Inicio`) AS d, MIN(`Data de Cobrança`) AS c, MIN(`Ordem`) AS o')
            ->whereNotNull('Nr do Contracto')->where('Nr do Contracto', '<>', '')->groupBy('Nr do Contracto')->get();
        $items = []; $noDate = [];
        foreach ($rows as $r) {
            $d = substr((string)($r->d ?: $r->c ?: ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || (int)substr($d, 0, 4) < 1990) { $noDate[] = (string)$r->n; continue; }
            $items[] = ['atual' => (string)$r->n, 'data' => $d, 'ordem' => (int)$r->o];
        }
        usort($items, fn($a, $b) => [$a['data'], $a['ordem']] <=> [$b['data'], $b['ordem']]);
        $seq = []; $out = [];
        foreach ($items as $it) {
            $y = (int)substr($it['data'], 0, 4);
            $seq[$y] = ($seq[$y] ?? 0) + 1;
            $out[] = $it + ['ano' => $y, 'sequencia' => $seq[$y], 'novo' => self::format($y, $seq[$y], $prefix, $digits)];
        }
        return ['linhas' => $out, 'sem_data' => $noDate];
    }

    /** Resumo por ano para as Definições. */
    public static function summary(array $plan): array
    {
        $by = [];
        foreach ($plan['linhas'] as $l) {
            $by[$l['ano']] ??= ['ano' => $l['ano'], 'contratos' => 0, 'primeiro' => $l['novo'], 'ultimo' => $l['novo'], 'alterar' => 0];
            $by[$l['ano']]['contratos']++; $by[$l['ano']]['ultimo'] = $l['novo'];
            if ($l['atual'] !== $l['novo']) $by[$l['ano']]['alterar']++;
        }
        ksort($by);
        return array_values($by);
    }

    /** Números antigos que correspondem ao número actual (para histórico e pesquisa). */
    public static function aliases(string $numero): array
    {
        $all = [$numero];
        try {
            if (Schema::hasTable('cis_contract_numbers')) {
                $all = array_merge($all, DB::table('cis_contract_numbers')->where('numero', $numero)->pluck('numero_original')->all());
            }
        } catch (\Throwable) {}
        return array_values(array_unique($all));
    }

    /** Número actual a partir de um número antigo (ou o próprio). */
    public static function resolve(string $numero): string
    {
        try {
            if (Schema::hasTable('cis_contract_numbers')) {
                $n = DB::table('cis_contract_numbers')->where('numero_original', $numero)->value('numero');
                if ($n) return (string)$n;
            }
        } catch (\Throwable) {}
        return $numero;
    }

    /** Número actual só quando o número dado já não existe nos Contractos (ex.: pesquisa pelo número antigo). */
    public static function resolveIfMissing(string $numero): string
    {
        $r = self::resolve($numero);
        if ($r === $numero) return $numero;
        try {
            if (DB::connection('mysql_legacy')->table('Contractos')->where('Nr do Contracto', $numero)->exists()) return $numero;
        } catch (\Throwable) {}
        return $r;
    }

    /**
     * Aplica a renumeração: Contractos, Extracto (coluna Contracto e descrições), registo de mensagens
     * e tabela de correspondência. O histórico imutável mantém o número antigo (lido por alias).
     * @return array{contratos:int,prestacoes:int,extracto:int,mensagens:int}
     */
    public static function apply(ConnectionInterface $db, array $plan, string $by): array
    {
        $map = [];
        foreach ($plan['linhas'] as $l) if ($l['atual'] !== $l['novo']) $map[$l['atual']] = $l['novo'];
        $stats = ['contratos' => count($map), 'prestacoes' => 0, 'extracto' => 0, 'mensagens' => 0];
        $db->transaction(function () use ($db, $map, $plan, $by, &$stats) {
            // 1) Contractos em duas fases (evita colisões quando se volta a renumerar).
            $i = 0; $tmp = [];
            foreach ($map as $old => $new) { $t = '~RN' . (++$i) . '~'; $tmp[$t] = $new;
                $stats['prestacoes'] += $db->table('Contractos')->where('Nr do Contracto', $old)->update(['Nr do Contracto' => $t]); }
            foreach ($tmp as $t => $new) $db->table('Contractos')->where('Nr do Contracto', $t)->update(['Nr do Contracto' => $new]);
            // 2) Extracto: substituição simultânea na coluna Contracto e no texto da descrição.
            $re = '/(?<![A-Za-z0-9])([A-Za-z][A-Za-z0-9]*-(?:\d{4}-)?\d+)(?![0-9])/u';
            $sub = fn(string $s) => preg_replace_callback($re, fn($m) => $map[$m[1]] ?? $m[1], $s);
            $db->table('Extracto')->select(['Nr', 'Contracto', 'Descrição'])->orderBy('Nr')->chunk(1000, function ($rows) use ($db, $map, $sub, &$stats) {
                foreach ($rows as $r) {
                    $c = (string)($r->Contracto ?? ''); $d = (string)($r->{'Descrição'} ?? '');
                    $nc = $map[$c] ?? $c; $nd = $d === '' ? $d : $sub($d);
                    if ($nc !== $c || $nd !== $d) {
                        $db->table('Extracto')->where('Nr', $r->Nr)->update(['Contracto' => $r->Contracto === null ? null : $nc, 'Descrição' => $r->{'Descrição'} === null ? null : $nd]);
                        $stats['extracto']++;
                    }
                }
            });
            // 3) Registo de mensagens (tabela CIS).
            if (Schema::connection($db->getName())->hasTable('cis_message_log')) {
                foreach ($map as $old => $new) $stats['mensagens'] += $db->table('cis_message_log')->where('contract_number', $old)->update(['contract_number' => $new]);
            }
            // 4) Correspondência: todos os números que o contrato já teve apontam para o número actual.
            $now = now('Africa/Maputo'); $i = 0; $tmp = [];
            foreach ($plan['linhas'] as $l) { $t = '~RN' . (++$i) . '~'; $tmp[$t] = $l;
                $db->table('cis_contract_numbers')->where('numero', $l['atual'])->update(['numero' => $t]); }
            foreach ($tmp as $t => $l) {
                $fields = ['numero' => $l['novo'], 'ano' => $l['ano'], 'sequencia' => $l['sequencia'], 'data_desembolso' => $l['data'],
                    'renumerado_por' => mb_substr($by, 0, 120), 'updated_at' => $now];
                $db->table('cis_contract_numbers')->where('numero', $t)->update($fields);
                foreach ([$l['atual']] as $alias)
                    if (!$db->table('cis_contract_numbers')->where('numero_original', $alias)->exists())
                        $db->table('cis_contract_numbers')->insert($fields + ['numero_original' => $alias, 'created_at' => $now]);
            }
        });
        return $stats;
    }
}
