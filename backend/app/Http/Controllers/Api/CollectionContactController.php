<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Branches;
use App\Support\CompanySettings as CS;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos de cobrança, promessas de pagamento e agenda do dia (modelo Loandisk).
 *
 * Uma promessa fica «cumprida» quando as entradas no Extracto do contrato, desde o dia do contacto
 * até à data prometida, somam pelo menos o valor prometido; «falhada» quando a data passa sem isso.
 * A avaliação é feita ao ler (agenda, contactos do contrato e lembretes), sem tarefas em segundo plano.
 */
final class CollectionContactController extends Controller
{
    public const CANAIS = ['chamada' => 'Chamada', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'visita' => 'Visita', 'presencial' => 'Na agência', 'outro' => 'Outro'];
    public const RESULTADOS = ['prometeu_pagar' => 'Prometeu pagar', 'vai_pagar' => 'Vai pagar no vencimento', 'sem_resposta' => 'Sem resposta',
        'numero_errado' => 'Número errado / indisponível', 'recusou' => 'Recusou pagar', 'ja_pagou' => 'Diz que já pagou', 'outro' => 'Outro'];
    private const OPEN = ['Pendente', 'Pagamento Atrasado'];

    public static function ready(): bool
    {
        try {
            return Schema::hasTable('cis_collection_contacts');
        } catch (\Throwable) {
            return false;
        }
    }

    private function needReady(): void
    {
        abort_unless(self::ready(), 503, 'Contactos e promessas ainda não instalados. Na VPS execute: bash deploy/install-collection-contacts.sh');
    }

    /** Avalia promessas pendentes (todas, ou só as de alguns contratos). */
    public static function evaluate(?array $contracts = null): void
    {
        if (!self::ready()) return;
        $today = now('Africa/Maputo')->toDateString();
        $q = DB::table('cis_collection_contacts')->where('promessa_estado', 'pendente');
        if ($contracts !== null) $q->whereIn('contract_number', $contracts ?: ['__none__']);
        $pending = $q->get(['id', 'contract_number', 'promessa_valor', 'promessa_data', 'created_at']);
        if ($pending->isEmpty()) return;
        $ex = DB::connection('mysql_legacy')->table('Extracto')->where('Tipo de Movimento', 'Entradas')
            ->whereIn('Contracto', $pending->pluck('contract_number')->unique()->values()->all())
            ->where('Data da Operação', '>=', substr((string) $pending->min('created_at'), 0, 10))
            ->get(['Contracto', 'Valor', 'Data da Operação as d']);
        foreach ($pending as $p) {
            $from = substr((string) $p->created_at, 0, 10);
            $paid = $ex->filter(fn ($e) => (string) $e->Contracto === (string) $p->contract_number && ($d = substr((string) $e->d, 0, 10)) >= $from && $d <= $p->promessa_data)
                ->sum(fn ($e) => (float) $e->Valor);
            $state = $paid + 0.005 >= (float) $p->promessa_valor ? 'cumprida' : ($p->promessa_data < $today ? 'falhada' : null);
            if ($state) {
                DB::table('cis_collection_contacts')->where('id', $p->id)->where('promessa_estado', 'pendente')
                    ->update(['promessa_estado' => $state, 'promessa_pago' => round($paid, 2), 'promessa_resolvida_em' => now(), 'updated_at' => now()]);
            } elseif ($paid > 0) {
                DB::table('cis_collection_contacts')->where('id', $p->id)->update(['promessa_pago' => round($paid, 2)]);
            }
        }
    }

    private static function out(object $c, array $names): array
    {
        return [
            'id' => (int) $c->id, 'contrato' => $c->contract_number, 'canal' => $c->canal, 'canal_label' => self::CANAIS[$c->canal] ?? $c->canal,
            'resultado' => $c->resultado, 'resultado_label' => self::RESULTADOS[$c->resultado] ?? $c->resultado, 'notas' => $c->notas,
            'proximo_contacto' => $c->proximo_contacto, 'promessa' => $c->promessa_estado ? ['valor' => $c->promessa_valor, 'data' => $c->promessa_data,
                'estado' => $c->promessa_estado, 'pago' => $c->promessa_pago, 'resolvida_em' => $c->promessa_resolvida_em] : null,
            'por' => $names[$c->user_id] ?? '—', 'em' => $c->created_at,
        ];
    }

    private static function names($rows): array
    {
        return DB::table('users')->whereIn('id', collect($rows)->pluck('user_id')->unique()->all() ?: [0])->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    /** GET contractos/{numero}/contactos */
    public function index(Request $r, string $numero)
    {
        Permissions::require($r->user(), 'ver');
        if (!self::ready()) return response()->json(['instalado' => false, 'data' => []]);
        self::evaluate([$numero]);
        $rows = DB::table('cis_collection_contacts')->where('contract_number', $numero)->orderByDesc('id')->limit(200)->get();
        $names = self::names($rows);
        return response()->json(['instalado' => true, 'canais' => self::CANAIS, 'resultados' => self::RESULTADOS,
            'pode_registar' => Permissions::can($r->user(), 'cobrancas'),
            'data' => $rows->map(fn ($c) => self::out($c, $names))->all()])->header('Cache-Control', 'no-store, private');
    }

    /** POST contractos/{numero}/contactos */
    public function store(Request $r, string $numero)
    {
        Permissions::require($r->user(), 'cobrancas');
        $this->needReady();
        $today = now('Africa/Maputo')->toDateString();
        $d = $r->validate([
            'canal' => ['required', 'in:'.implode(',', array_keys(self::CANAIS))],
            'resultado' => ['required', 'in:'.implode(',', array_keys(self::RESULTADOS))],
            'notas' => ['nullable', 'string', 'max:2000'],
            'proximo_contacto' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'promessa_valor' => ['required_if:resultado,prometeu_pagar', 'nullable', 'numeric', 'gt:0', 'max:100000000'],
            'promessa_data' => ['required_if:resultado,prometeu_pagar', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today,
                'before_or_equal:'.now('Africa/Maputo')->addDays(90)->toDateString()],
        ]);
        $first = DB::connection('mysql_legacy')->table('Contractos')->where('Nr do Contracto', $numero)->orderBy('Ordem')->first(['Nº de Cliente']);
        abort_unless($first, 404, 'Contrato não encontrado.');
        $promise = $d['resultado'] === 'prometeu_pagar';
        $id = DB::transaction(function () use ($d, $numero, $first, $promise, $r) {
            if ($promise) {
                // Uma nova promessa substitui a anterior ainda pendente deste contrato.
                DB::table('cis_collection_contacts')->where('contract_number', $numero)->where('promessa_estado', 'pendente')
                    ->update(['promessa_estado' => 'cancelada', 'promessa_resolvida_em' => now(), 'updated_at' => now()]);
            }
            return DB::table('cis_collection_contacts')->insertGetId([
                'contract_number' => $numero, 'client_id' => (int) $first->{'Nº de Cliente'} ?: null, 'canal' => $d['canal'], 'resultado' => $d['resultado'],
                'notas' => isset($d['notas']) && trim($d['notas']) !== '' ? trim($d['notas']) : null,
                'proximo_contacto' => $d['proximo_contacto'] ?? null,
                'promessa_valor' => $promise ? round((float) $d['promessa_valor'], 2) : null, 'promessa_data' => $promise ? $d['promessa_data'] : null,
                'promessa_estado' => $promise ? 'pendente' : null, 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $c = DB::table('cis_collection_contacts')->where('id', $id)->first();
        return response()->json(self::out($c, self::names([$c])) + ['notice' => $promise ? 'Contacto e promessa registados.' : 'Contacto registado.'], 201);
    }

    /** POST contactos/{id}/cancelar-promessa */
    public function cancelPromise(Request $r, int $id)
    {
        Permissions::require($r->user(), 'cobrancas');
        $this->needReady();
        $c = DB::table('cis_collection_contacts')->where('id', $id)->first();
        abort_unless($c, 404, 'Contacto não encontrado.');
        Branches::requireContract($r->user(), (string) $c->contract_number);
        abort_unless($c->promessa_estado === 'pendente', 409, 'A promessa já não está pendente.');
        DB::table('cis_collection_contacts')->where('id', $id)->update(['promessa_estado' => 'cancelada', 'promessa_resolvida_em' => now(), 'updated_at' => now()]);
        return response()->json(['notice' => 'Promessa cancelada.']);
    }

    /** GET cobranca/agenda : folha de cobrança do dia (agência e carteira do utilizador). */
    public function agenda(Request $r)
    {
        Permissions::require($r->user(), 'cobrancas');
        $today = now('Africa/Maputo')->toDateString();
        $db = DB::connection('mysql_legacy');
        $q = $db->table('Contractos')->whereIn('Estado', self::OPEN);
        Branches::restrict($q, $r->user(), $r->query('agencia'));
        $open = $q->get(['Nr do Contracto', 'Nome do Cliente', 'Nº de Cliente', 'Nº da Parcela', 'Data de Cobrança', 'Valor a Pagar', 'Valor de Multa', 'Valor Pago']);
        $byC = $open->groupBy('Nr do Contracto');
        $nums = $byC->keys()->map(fn ($k) => (string) $k)->all();
        $phones = $db->table('Clientes')->whereIn('ID', $open->pluck('Nº de Cliente')->unique()->filter()->values()->all() ?: [0])->pluck('Contacto', 'ID')->all();
        $contract = function (string $n) use ($byC, $phones, $today) {
            $rows = $byC[$n] ?? collect();
            $late = $rows->filter(fn ($x) => substr((string) $x->{'Data de Cobrança'}, 0, 10) < $today);
            $oldest = $late->min(fn ($x) => substr((string) $x->{'Data de Cobrança'}, 0, 10));
            $next = $rows->min(fn ($x) => substr((string) $x->{'Data de Cobrança'}, 0, 10));
            $f = $rows->first();
            return ['contrato' => $n, 'cliente' => (string) ($f->{'Nome do Cliente'} ?? ''), 'cliente_id' => (int) ($f->{'Nº de Cliente'} ?? 0) ?: null,
                'telefone' => trim((string) ($phones[(int) ($f->{'Nº de Cliente'} ?? 0)] ?? '')) ?: null,
                'em_divida' => number_format($rows->sum(fn ($x) => (float) $x->{'Valor a Pagar'} + (float) ($x->{'Valor de Multa'} ?? 0) - (float) ($x->{'Valor Pago'} ?? 0)), 2, '.', ''),
                'vencido' => number_format($late->sum(fn ($x) => (float) $x->{'Valor a Pagar'} - (float) ($x->{'Valor Pago'} ?? 0)), 2, '.', ''),
                'dias_atraso' => $oldest ? (int) floor((strtotime($today) - strtotime($oldest)) / 86400) : 0, 'proximo_vencimento' => $next];
        };
        $out = ['instalado' => self::ready(), 'hoje' => $today, 'promessas_hoje' => [], 'promessas_falhadas' => [], 'seguimentos' => [], 'sem_contacto' => [], 'vencem_hoje' => []];
        $dias = max(1, (int) (CS::get('dias_sem_contacto') ?: 7));
        $lastContact = [];
        if (self::ready()) {
            self::evaluate($nums);
            $contacts = DB::table('cis_collection_contacts')->whereIn('contract_number', $nums ?: ['__none__'])->orderByDesc('id')->get();
            $names = self::names($contacts);
            $latest = $contacts->groupBy('contract_number')->map(fn ($g) => $g->first());
            foreach ($latest as $n => $c) $lastContact[(string) $n] = substr((string) $c->created_at, 0, 10);
            foreach ($contacts as $c) {
                $n = (string) $c->contract_number;
                $isLatest = (int) $latest[$n]->id === (int) $c->id;
                $row = fn () => $contract($n) + ['contacto' => self::out($c, $names)];
                if ($c->promessa_estado === 'pendente' && $c->promessa_data <= $today) $out['promessas_hoje'][] = $row();
                elseif ($c->promessa_estado === 'falhada' && $isLatest) $out['promessas_falhadas'][] = $row();
                elseif ($isLatest && $c->proximo_contacto && $c->proximo_contacto <= $today && $c->promessa_estado !== 'pendente') $out['seguimentos'][] = $row();
            }
        }
        $limit = now('Africa/Maputo')->subDays($dias)->toDateString();
        $busy = collect($out['promessas_hoje'])->merge($out['promessas_falhadas'])->merge($out['seguimentos'])->pluck('contrato')->flip();
        foreach ($nums as $n) {
            $c = $contract($n);
            if ($c['dias_atraso'] > 0 && !isset($busy[$n]) && (($lastContact[$n] ?? '') < $limit)) $out['sem_contacto'][] = $c + ['ultimo_contacto' => $lastContact[$n] ?? null];
            elseif ($c['dias_atraso'] === 0 && $c['proximo_vencimento'] === $today && !isset($busy[$n])) $out['vencem_hoje'][] = $c;
        }
        usort($out['sem_contacto'], fn ($a, $b) => $b['dias_atraso'] <=> $a['dias_atraso']);
        $out['dias_sem_contacto'] = $dias;
        try {
            $out['lembretes_por_enviar'] = Permissions::can($r->user(), 'mensagens') ? count((new MessageCenterController())->lembretesLista($r)) : null;
        } catch (\Throwable) {
            $out['lembretes_por_enviar'] = null;
        }
        return response()->json($out)->header('Cache-Control', 'no-store, private');
    }
}
