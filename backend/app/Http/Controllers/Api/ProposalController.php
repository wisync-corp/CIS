<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContractCalculationStrategy;
use App\Support\Branches;
use App\Support\CompanySettings as CS;
use App\Support\CreditScore;
use App\Support\Permissions;
use App\Support\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propostas de crédito com aprovação.
 *
 * Agente e gestor de crédito propõem; o gestor aprova até ao limite de Definições › Credit scoring
 * («limite_aprovacao_gestor»); acima disso só o administrador. Ninguém aprova a própria proposta,
 * excepto o administrador. Uma proposta aprovada é válida «validade_aprovacao_dias» e é a ÚNICA
 * forma de abrir um contrato (o desembolso consome-a).
 */
final class ProposalController extends Controller
{
    public const STATES = ['rascunho', 'submetida', 'devolvida', 'aprovada', 'rejeitada', 'cancelada', 'desembolsada', 'expirada'];
    private const TERMS = ['1 Semana', '2 Semanas', '3 Semanas', '1 Mês', '2 Meses', '3 Meses'];

    public static function ready(): bool
    {
        try {
            return Schema::hasTable('cis_credit_proposals') && Schema::hasTable('cis_credit_proposal_events');
        } catch (\Throwable) {
            return false;
        }
    }

    private function needReady(): void
    {
        abort_unless(self::ready(), 503, 'Propostas de crédito ainda não instaladas. Na VPS execute: bash deploy/install-credit-proposals.sh');
    }

    /** Propostas que o utilizador pode ver: todas (admin), da agência, ou só as suas/dos seus mutuários (agente). */
    private function scoped(Request $r)
    {
        $u = $r->user();
        $q = DB::table('cis_credit_proposals');
        $branch = Branches::scope($u, $r->query('agencia'));
        if ($branch !== null) $q->where(fn ($w) => $w->where('branch_id', $branch)->orWhereNull('branch_id'));
        if (Portfolio::kind($u) !== null) {
            $clients = Portfolio::clients($u) ?? [];
            $q->where(fn ($w) => $w->where('created_by', $u->id)->orWhereIn('client_id', $clients ?: [0]));
        }
        return $q;
    }

    private function find(Request $r, int $id): object
    {
        $p = $this->scoped($r)->where('id', $id)->first();
        abort_unless($p, 404, 'Proposta não encontrada.');
        return $p;
    }

    /** Limite de aprovação do utilizador (null = sem limite). */
    public static function approvalLimit(?object $u): ?float
    {
        if (!Permissions::can($u, 'propostas.aprovar')) return 0.0;
        if (($u->role ?? '') === 'administrador') return null;
        return CS::num('limite_aprovacao_gestor');
    }

    private static function canDecide(?object $u, object $p): array
    {
        if (!Permissions::can($u, 'propostas.aprovar')) return [false, 'Sem permissão para aprovar propostas.'];
        if ((int) $p->created_by === (int) $u->id && ($u->role ?? '') !== 'administrador') return [false, 'Não pode decidir uma proposta criada por si.'];
        $limit = self::approvalLimit($u);
        if ($limit !== null && (float) $p->montante > $limit) return [false, 'Montante acima do seu limite de aprovação ('.number_format($limit, 0, ',', ' ').' MT): decisão do administrador.'];
        return [true, null];
    }

    private static function event(int $id, string $accao, ?string $de, ?string $para, ?string $notas, int $user): void
    {
        DB::table('cis_credit_proposal_events')->insert(['proposal_id' => $id, 'accao' => $accao, 'de' => $de, 'para' => $para,
            'notas' => $notas !== null && trim($notas) !== '' ? mb_substr(trim($notas), 0, 2000) : null, 'user_id' => $user, 'created_at' => now()]);
    }

    /** Aprovadas com validade vencida passam a «expirada». */
    private static function expire(): void
    {
        $ids = DB::table('cis_credit_proposals')->where('status', 'aprovada')->where('valida_ate', '<', now('Africa/Maputo')->toDateString())->pluck('id');
        foreach ($ids as $id) {
            if (DB::table('cis_credit_proposals')->where('id', $id)->where('status', 'aprovada')->update(['status' => 'expirada', 'updated_at' => now()])) {
                self::event((int) $id, 'expirou', 'aprovada', 'expirada', 'Validade da aprovação terminou sem desembolso.', 0);
            }
        }
    }

    private static function names(array $ids): array
    {
        return DB::table('users')->whereIn('id', array_filter(array_unique($ids)) ?: [0])->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    private static function out(object $p, array $names): array
    {
        $a = $p->analise ? json_decode((string) $p->analise, true) : null;
        return [
            'id' => (int) $p->id, 'numero' => $p->numero, 'estado' => $p->status,
            'mutuario' => ['id' => (int) $p->client_id, 'nome' => $p->client_name],
            'montante' => (int) $p->montante, 'montante_aprovado' => $p->montante_aprovado !== null ? (int) $p->montante_aprovado : null,
            'tipo_juro' => $p->tipo_juro, 'estrategia' => $p->estrategia, 'periodo_dias' => (int) $p->periodo_dias, 'prazo' => $p->prazo,
            'modalidade' => $p->modalidade, 'taxa' => $p->taxa !== null ? (float) $p->taxa : null, 'taxa_aprovada' => $p->taxa_aprovada !== null ? (float) $p->taxa_aprovada : null,
            'finalidade' => $p->finalidade, 'actividade' => $p->actividade,
            'rendimento_mensal' => $p->rendimento_mensal, 'despesas_mensais' => $p->despesas_mensais, 'outras_dividas_mensais' => $p->outras_dividas_mensais,
            'garantias' => $p->garantias, 'observacoes' => $p->observacoes, 'analise' => $a,
            'decisao_notas' => $p->decisao_notas, 'decidido_por' => $p->decided_by ? ($names[$p->decided_by] ?? null) : null, 'decidido_em' => $p->decided_at,
            'valida_ate' => $p->valida_ate, 'contrato' => $p->contract_number,
            'criado_por' => $names[$p->created_by] ?? null, 'criado_por_id' => (int) $p->created_by, 'criado_em' => $p->created_at, 'submetido_em' => $p->submitted_at,
            'agencia' => $p->branch_id ? Branches::label((int) $p->branch_id) : null,
        ];
    }

    public function index(Request $r)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar', 'contratos.abrir']);
        if (!self::ready()) return response()->json(['instalado' => false, 'data' => [], 'contagem' => []]);
        self::expire();
        $f = $r->validate(['estado' => ['nullable', 'in:'.implode(',', self::STATES).',para_decidir,todas'], 'q' => ['nullable', 'string', 'max:100']]);
        $u = $r->user();
        $all = $this->scoped($r)->orderByDesc('id')->limit(500)->get();
        $limit = self::approvalLimit($u);
        $toDecide = $all->filter(fn ($p) => $p->status === 'submetida' && self::canDecide($u, $p)[0]);
        $list = match ($f['estado'] ?? 'todas') {
            'todas' => $all, 'para_decidir' => $toDecide, default => $all->where('status', $f['estado']),
        };
        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $list = $list->filter(fn ($p) => stripos($p->client_name.' '.$p->numero.' '.$p->contract_number, $term) !== false);
        }
        $names = self::names($all->pluck('created_by')->merge($all->pluck('decided_by'))->all());
        return response()->json([
            'instalado' => true,
            'data' => $list->values()->map(fn ($p) => self::out($p, $names))->all(),
            'contagem' => ['para_decidir' => $toDecide->count()] + $all->countBy('status')->all(),
            'limite_aprovacao' => $limit, 'validade_dias' => (int) CS::get('validade_aprovacao_dias'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $r, int $id)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar', 'contratos.abrir']);
        $this->needReady();
        self::expire();
        $p = $this->find($r, $id);
        $ev = DB::table('cis_credit_proposal_events')->where('proposal_id', $id)->orderBy('id')->get();
        $names = self::names(array_merge([$p->created_by, $p->decided_by], $ev->pluck('user_id')->all()));
        $u = $r->user();
        [$decide, $why] = self::canDecide($u, $p);
        $mine = (int) $p->created_by === (int) $u->id || ($u->role ?? '') === 'administrador';
        $history = CreditScore::forClient((int) $p->client_id);
        return response()->json(self::out($p, $names) + [
            'historico_mutuario' => $history,
            'analise_actual' => in_array($p->status, ['rascunho', 'devolvida'], true) ? CreditScore::analyse((array) $p, $history) : null,
            'eventos' => $ev->map(fn ($e) => ['accao' => $e->accao, 'de' => $e->de, 'para' => $e->para, 'notas' => $e->notas,
                'por' => $e->user_id ? ($names[$e->user_id] ?? '—') : 'Sistema', 'em' => $e->created_at])->all(),
            'pode' => [
                'editar' => $mine && in_array($p->status, ['rascunho', 'devolvida'], true) && Permissions::can($u, 'propostas.criar'),
                'submeter' => $mine && in_array($p->status, ['rascunho', 'devolvida'], true) && Permissions::can($u, 'propostas.criar'),
                'cancelar' => $mine && in_array($p->status, ['rascunho', 'submetida', 'devolvida', 'aprovada'], true),
                'decidir' => $p->status === 'submetida' && $decide, 'motivo_nao_decidir' => $p->status === 'submetida' && !$decide ? $why : null,
                'abrir_contrato' => $p->status === 'aprovada' && Permissions::can($u, 'contratos.abrir'),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function rules(Request $r): array
    {
        if (is_string($r->input('taxa'))) $r->merge(['taxa' => str_replace(',', '.', trim($r->input('taxa'))) ?: null]);
        $d = $r->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'montante' => ['required', 'integer', 'min:1', 'max:16777216'],
            'tipo_juro' => ['required', 'in:Fixo,Variável,Composto'],
            'estrategia' => ['required', 'string', 'max:90'],
            'periodo_dias' => ['required', 'integer', 'in:15,30'],
            'prazo' => ['required', 'in:'.implode(',', self::TERMS)],
            'modalidade' => ['required', 'in:Semanal,Mensal,Uma Parcela'],
            'taxa' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'finalidade' => ['required', 'string', 'min:3', 'max:255'],
            'actividade' => ['nullable', 'string', 'max:160'],
            'rendimento_mensal' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'despesas_mensais' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'outras_dividas_mensais' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'garantias' => ['nullable', 'string', 'max:2000'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(in_array($d['estrategia'], ContractCalculationStrategy::OPTIONS, true) && ContractCalculationStrategy::compatible($d['tipo_juro'], $d['estrategia']), 422,
            'O sistema de amortização não é compatível com o tipo de juro.');
        if ($d['tipo_juro'] !== 'Fixo') abort_if(empty($d['taxa']), 422, 'Indique a taxa de juro.');
        $d['despesas_mensais'] ??= 0; $d['outras_dividas_mensais'] ??= 0;
        $d['taxa'] = $d['taxa'] ?? null;
        return $d;
    }

    private function client(Request $r, int $id): object
    {
        Portfolio::requireClient($r->user(), $id);
        $c = DB::connection('mysql_legacy')->table('Clientes')->where('ID', $id)->first(['ID', 'Nome Completo', 'Cidade']);
        abort_unless($c, 422, 'Mutuário inexistente.');
        return $c;
    }

    /** Prévia da análise (sem gravar). */
    public function analyse(Request $r)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar']);
        $d = $this->rules($r);
        $this->client($r, (int) $d['client_id']);
        $h = CreditScore::forClient((int) $d['client_id']);
        return response()->json(['historico_mutuario' => $h, 'analise' => CreditScore::analyse($d, $h)])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $r)
    {
        $u = $r->user();
        Permissions::require($u, 'propostas.criar');
        $this->needReady();
        $d = $this->rules($r);
        $c = $this->client($r, (int) $d['client_id']);
        $id = DB::transaction(function () use ($d, $c, $u) {
            $year = now('Africa/Maputo')->format('Y');
            $last = DB::table('cis_credit_proposals')->where('numero', 'like', "PROP-$year-%")->lockForUpdate()->max('numero');
            $seq = $last ? ((int) substr((string) $last, -4)) + 1 : 1;
            $id = DB::table('cis_credit_proposals')->insertGetId($d + [
                'numero' => sprintf('PROP-%s-%04d', $year, $seq), 'client_name' => mb_substr((string) $c->{'Nome Completo'}, 0, 160),
                'branch_id' => Branches::userBranch($u) ?? (Branches::ready() ? Branches::principalId() : null),
                'status' => 'rascunho', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            self::event($id, 'criada', null, 'rascunho', null, (int) $u->id);
            return $id;
        });
        return $this->show($r, $id)->setStatusCode(201);
    }

    public function update(Request $r, int $id)
    {
        $u = $r->user();
        Permissions::require($u, 'propostas.criar');
        $this->needReady();
        $p = $this->find($r, $id);
        abort_unless(in_array($p->status, ['rascunho', 'devolvida'], true), 409, 'Só é possível alterar propostas em rascunho ou devolvidas.');
        abort_unless((int) $p->created_by === (int) $u->id || $u->role === 'administrador', 403, 'Só quem criou a proposta a pode alterar.');
        $d = $this->rules($r);
        $c = $this->client($r, (int) $d['client_id']);
        DB::table('cis_credit_proposals')->where('id', $id)->update($d + ['client_name' => mb_substr((string) $c->{'Nome Completo'}, 0, 160), 'updated_at' => now()]);
        self::event($id, 'alterada', $p->status, $p->status, null, (int) $u->id);
        return $this->show($r, $id);
    }

    public function submit(Request $r, int $id)
    {
        $u = $r->user();
        Permissions::require($u, 'propostas.criar');
        $this->needReady();
        $p = $this->find($r, $id);
        abort_unless(in_array($p->status, ['rascunho', 'devolvida'], true), 409, 'A proposta já foi submetida.');
        abort_unless((int) $p->created_by === (int) $u->id || $u->role === 'administrador', 403, 'Só quem criou a proposta a pode submeter.');
        $h = CreditScore::forClient((int) $p->client_id);
        $a = CreditScore::analyse((array) $p, $h);
        abort_if($a['prestacao'] === null, 422, 'Não foi possível calcular as prestações: '.implode(' ', $a['alertas']));
        $n = DB::table('cis_credit_proposals')->where('id', $id)->whereIn('status', ['rascunho', 'devolvida'])
            ->update(['status' => 'submetida', 'submitted_at' => now(), 'analise' => json_encode(['calculo' => $a, 'historico' => $h], JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        abort_unless($n === 1, 409, 'A proposta foi alterada entretanto.');
        self::event($id, 'submetida', $p->status, 'submetida', $r->input('notas'), (int) $u->id);
        return $this->show($r, $id);
    }

    public function decide(Request $r, int $id)
    {
        $u = $r->user();
        $this->needReady();
        $d = $r->validate([
            'decisao' => ['required', 'in:aprovar,rejeitar,devolver'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'montante_aprovado' => ['nullable', 'integer', 'min:1'],
            'taxa_aprovada' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $p = $this->find($r, $id);
        abort_unless($p->status === 'submetida', 409, 'Só se decidem propostas submetidas.');
        [$ok, $why] = self::canDecide($u, $p);
        abort_unless($ok, 403, $why);
        $notes = trim((string) ($d['notas'] ?? ''));
        if ($d['decisao'] !== 'aprovar') abort_if(mb_strlen($notes) < 5, 422, $d['decisao'] === 'rejeitar' ? 'Indique o motivo da rejeição.' : 'Indique o que deve ser corrigido.');
        $upd = ['decided_by' => $u->id, 'decided_at' => now(), 'decisao_notas' => $notes ?: null, 'updated_at' => now()];
        if ($d['decisao'] === 'aprovar') {
            $amount = (int) ($d['montante_aprovado'] ?? $p->montante);
            abort_if($amount > (int) $p->montante, 422, 'O montante aprovado não pode ser superior ao pedido.');
            $limit = self::approvalLimit($u);
            abort_if($limit !== null && $amount > $limit, 403, 'Montante acima do seu limite de aprovação.');
            // Juro fixo: a taxa vem sempre da tabela de preços no desembolso.
            $rate = $p->tipo_juro === 'Fixo' ? null : ($d['taxa_aprovada'] ?? ($p->taxa !== null ? (float) $p->taxa : null));
            if ($rate !== null) abort_unless(abs($rate - round($rate)) < 0.000001, 422, 'A taxa tem de ser um número inteiro.');
            $upd += ['status' => 'aprovada', 'montante_aprovado' => $amount, 'taxa_aprovada' => $rate,
                'valida_ate' => now('Africa/Maputo')->addDays(max(1, (int) CS::get('validade_aprovacao_dias')))->toDateString()];
        } else {
            $upd += ['status' => $d['decisao'] === 'rejeitar' ? 'rejeitada' : 'devolvida'];
        }
        $n = DB::table('cis_credit_proposals')->where('id', $id)->where('status', 'submetida')->update($upd);
        abort_unless($n === 1, 409, 'A proposta foi decidida entretanto.');
        self::event($id, ['aprovar' => 'aprovada', 'rejeitar' => 'rejeitada', 'devolver' => 'devolvida'][$d['decisao']], 'submetida', $upd['status'],
            $notes.($d['decisao'] === 'aprovar' && ($upd['montante_aprovado'] ?? 0) !== (int) $p->montante ? ' (montante aprovado: '.$upd['montante_aprovado'].' MT)' : ''), (int) $u->id);
        return $this->show($r, $id);
    }

    public function cancel(Request $r, int $id)
    {
        $u = $r->user();
        $this->needReady();
        $p = $this->find($r, $id);
        abort_unless(in_array($p->status, ['rascunho', 'submetida', 'devolvida', 'aprovada'], true), 409, 'Esta proposta já não pode ser cancelada.');
        abort_unless((int) $p->created_by === (int) $u->id || $u->role === 'administrador', 403, 'Só quem criou a proposta (ou o administrador) a pode cancelar.');
        $notes = trim((string) $r->input('notas', ''));
        abort_if(mb_strlen($notes) < 5, 422, 'Indique o motivo do cancelamento.');
        DB::table('cis_credit_proposals')->where('id', $id)->where('status', $p->status)->update(['status' => 'cancelada', 'updated_at' => now()]);
        self::event($id, 'cancelada', $p->status, 'cancelada', $notes, (int) $u->id);
        return $this->show($r, $id);
    }

    /** Propostas aprovadas e válidas para abrir contrato (formulário do contrato). */
    public function approved(Request $r)
    {
        Permissions::require($r->user(), 'contratos.abrir');
        if (!self::ready()) return response()->json(['instalado' => false, 'data' => []]);
        self::expire();
        $rows = $this->scoped($r)->where('status', 'aprovada')->orderBy('valida_ate')->get();
        $names = self::names($rows->pluck('created_by')->merge($rows->pluck('decided_by'))->all());
        return response()->json(['instalado' => true, 'data' => $rows->map(fn ($p) => self::out($p, $names))->values()->all()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Regras de abertura a partir de uma proposta (usadas na validação e no desembolso).
     * Devolve a proposta ou um erro: aprovada, válida, mesmo mutuário, montante ≤ aprovado e mesmas condições.
     */
    public static function checkForOpening(?int $id, array $d, ?object $user): object
    {
        abort_unless(self::ready(), 503, 'Propostas de crédito não instaladas.');
        abort_unless($id, 422, 'Seleccione a proposta de crédito aprovada: só se abrem contratos a partir de propostas aprovadas.');
        $p = DB::table('cis_credit_proposals')->where('id', $id)->first();
        abort_unless($p, 422, 'Proposta inexistente.');
        $branch = Branches::userBranch($user);
        abort_if($branch !== null && $p->branch_id !== null && (int) $p->branch_id !== $branch, 403, 'A proposta pertence a outra agência.');
        abort_unless($p->status === 'aprovada', 422, 'A proposta '.$p->numero.' não está aprovada (estado: '.$p->status.').');
        abort_if($p->valida_ate && $p->valida_ate < now('Africa/Maputo')->toDateString(), 422, 'A aprovação da proposta '.$p->numero.' expirou.');
        abort_unless((int) $p->client_id === (int) $d['cliente_id'], 422, 'O mutuário não corresponde ao da proposta '.$p->numero.'.');
        abort_if((int) $d['montante'] > (int) $p->montante_aprovado, 422, 'O capital não pode exceder o aprovado na proposta ('.number_format((int) $p->montante_aprovado, 0, ',', ' ').' MT).');
        foreach (['tipo_juro' => 'tipo_juro', 'estrategia_calculo' => 'estrategia', 'periodo_dias' => 'periodo_dias', 'prazo' => 'prazo', 'modalidade' => 'modalidade'] as $k => $col) {
            if (!array_key_exists($k, $d)) continue;
            abort_unless((string) $d[$k] === (string) $p->{$col}, 422, 'As condições do contrato têm de ser as aprovadas na proposta '.$p->numero.' ('.$k.').');
        }
        if ($p->taxa_aprovada !== null && isset($d['taxa_informada'])) {
            abort_unless(abs((float) $d['taxa_informada'] - (float) $p->taxa_aprovada) < 0.00001, 422, 'A taxa tem de ser a aprovada na proposta ('.(float) $p->taxa_aprovada.'%).');
        }
        return $p;
    }

    /** Reserva a proposta para o desembolso (evita dois contratos com a mesma proposta). */
    public static function reserve(int $id): void
    {
        $n = DB::table('cis_credit_proposals')->where('id', $id)->where('status', 'aprovada')->update(['status' => 'desembolsada', 'updated_at' => now()]);
        abort_unless($n === 1, 409, 'A proposta já foi usada ou mudou de estado.');
    }

    public static function release(int $id): void
    {
        DB::table('cis_credit_proposals')->where('id', $id)->where('status', 'desembolsada')->whereNull('contract_number')->update(['status' => 'aprovada', 'updated_at' => now()]);
    }

    public static function link(int $id, string $contract, int $user): void
    {
        DB::table('cis_credit_proposals')->where('id', $id)->update(['status' => 'desembolsada', 'contract_number' => $contract, 'updated_at' => now()]);
        self::event($id, 'desembolsada', 'aprovada', 'desembolsada', 'Contrato '.$contract.' aberto e desembolsado.', $user);
    }
}
