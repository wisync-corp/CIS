<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Branches;
use App\Support\ClientRegistry as CR;
use App\Support\CreditDossier;
use App\Support\Permissions;
use App\Support\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Perfil CIS do mutuário (pessoa singular/colectiva, fotografia), lista negra e pesquisa de mutuários
 * (para escolher um avalista já registado). Não altera a tabela Clientes.
 */
final class ClientRegistryController extends Controller
{
    private function needReady(): void
    {
        abort_unless(CR::ready(), 503, 'Ainda não instalado. Na VPS execute: bash deploy/install-client-registry.sh');
    }

    private function client(int $id): object
    {
        $c = DB::connection('mysql_legacy')->table('Clientes')->where('ID', $id)->limit(2)->get(['ID', 'Nome Completo']);
        abort_if($c->count() !== 1, $c->isEmpty() ? 404 : 409, 'Mutuário inexistente.');
        return $c[0];
    }

    public static function companyRules(): array
    {
        return [
            'forma_juridica' => ['nullable', 'in:'.implode(',', array_keys(CR::FORMAS))],
            'nuit' => ['nullable', 'regex:/^\d{9}$/'],
            'actividade' => ['nullable', 'string', 'max:160'],
            'data_constituicao' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'representante_nome' => ['nullable', 'string', 'max:160'],
            'representante_cargo' => ['nullable', 'string', 'max:80'],
            'representante_documento' => ['nullable', 'string', 'max:80'],
            'representante_contacto' => ['nullable', 'regex:/^\+?[0-9 ()\/-]{7,40}$/'],
        ];
    }

    public const MESSAGES = ['nuit.regex' => 'O NUIT tem 9 algarismos.', 'representante_contacto.regex' => 'Contacto do representante inválido.',
        'data_constituicao.before_or_equal' => 'A data de constituição não pode ser futura.'];

    /** Grava o perfil (usado também pelo registo de novo mutuário). */
    public static function saveProfile(int $id, string $tipo, array $d, ?int $by): void
    {
        $row = ['tipo' => $tipo, 'updated_by' => $by, 'updated_at' => now()];
        foreach (array_keys(self::companyRules()) as $k) {
            if (array_key_exists($k, $d)) $row[$k] = is_string($d[$k]) && trim($d[$k]) !== '' ? trim($d[$k]) : null;
        }
        if (DB::table('cis_client_profile')->where('client_id', $id)->exists()) {
            DB::table('cis_client_profile')->where('client_id', $id)->update($row);
        } else {
            DB::table('cis_client_profile')->insert($row + ['client_id' => $id, 'created_at' => now()]);
        }
    }

    private function payload(Request $r, int $id): array
    {
        $u = $r->user();
        $p = CR::profile($id);
        $b = CR::blacklisted($id);
        $hist = DB::table('cis_client_blacklist as b')->leftJoin('users as a', 'a.id', '=', 'b.added_by')->leftJoin('users as x', 'x.id', '=', 'b.removed_by')
            ->where('b.client_id', $id)->orderByDesc('b.id')->limit(20)
            ->get(['b.motivo', 'b.created_at', 'a.name as por', 'b.removed_at', 'x.name as retirado_por', 'b.removed_motivo']);
        $avalista = CreditDossier::ready() && \Illuminate\Support\Facades\Schema::hasColumn('cis_guarantors', 'guarantor_client_id')
            ? DB::table('cis_guarantors as g')->join('cis_credit_proposals as p', 'p.id', '=', 'g.proposal_id')
                ->where('g.guarantor_client_id', $id)->orderByDesc('p.id')->limit(20)
                ->get(['p.id', 'p.numero', 'p.client_name as mutuario', 'p.status as estado', 'p.montante'])
            : collect();
        $out = ['instalado' => true, 'tipo' => ($p->tipo ?? 'singular') === 'colectiva' ? 'colectiva' : 'singular', 'empresa' => null,
            'tem_foto' => (bool) ($p->foto_document_id ?? null),
            'lista_negra' => $b ? ['motivo' => $b->motivo, 'desde' => $b->created_at, 'por' => $b->por] : null,
            'conduta' => CR::conduta($id),
            'historico_lista_negra' => $hist, 'avalista_em' => $avalista,
            'formas_juridicas' => CR::FORMAS,
            'pode_editar' => Permissions::can($u, 'mutuarios.editar'),
            'pode_lista_negra' => Permissions::can($u, 'propostas.aprovar')];
        if ($p) {
            $out['empresa'] = array_intersect_key((array) $p, self::companyRules());
        }
        return $out;
    }

    /** GET cis/mutuarios/{id}/perfil */
    public function show(Request $r, int $id)
    {
        Permissions::require($r->user(), 'ver');
        Portfolio::requireClient($r->user(), $id);
        if (!CR::ready()) return response()->json(['instalado' => false]);
        $this->client($id);
        return response()->json($this->payload($r, $id))->header('Cache-Control', 'no-store, private');
    }

    /** PUT cis/mutuarios/{id}/perfil : tipo e dados da empresa. */
    public function update(Request $r, int $id)
    {
        Permissions::require($r->user(), 'mutuarios.editar');
        $this->needReady();
        Portfolio::requireClient($r->user(), $id);
        $this->client($id);
        $d = $r->validate(['tipo' => ['required', 'in:singular,colectiva']] + self::companyRules(), self::MESSAGES);
        if ($d['tipo'] === 'colectiva') {
            abort_if(trim((string) ($d['representante_nome'] ?? '')) === '', 422, 'Indique o representante legal da empresa.');
            abort_if(empty($d['nuit']), 422, 'Indique o NUIT da empresa (9 algarismos).');
            $dup = DB::table('cis_client_profile')->where('nuit', $d['nuit'])->where('client_id', '<>', $id)->value('client_id');
            abort_if($dup !== null, 409, 'Já existe um mutuário com este NUIT (ID '.$dup.').');
        }
        self::saveProfile($id, $d['tipo'], $d, (int) $r->user()->id);
        return response()->json($this->payload($r, $id) + ['notice' => 'Perfil do mutuário actualizado.']);
    }

    /** POST cis/mutuarios/{id}/foto (multipart «foto»): fica também nos documentos do mutuário. */
    public function uploadPhoto(Request $r, int $id)
    {
        Permissions::require($r->user(), 'mutuarios.editar');
        $this->needReady();
        abort_unless(CreditDossier::ready(), 503, 'Os documentos ainda não estão instalados (bash deploy/install-credit-dossier.sh).');
        Portfolio::requireClient($r->user(), $id);
        $this->client($id);
        $r->validate(['foto' => ['required', 'file', 'max:'.CreditDossier::MAX_KB, 'mimes:jpg,jpeg,png,webp']],
            ['foto.max' => 'A fotografia tem mais de 5 MB.', 'foto.mimes' => 'A fotografia tem de ser JPG, PNG ou WEBP.']);
        $file = $r->file('foto');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: '';
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && $bytes !== '', 422, 'A fotografia tem de ser JPG, PNG ou WEBP.');
        $u = (int) $r->user()->id;
        DB::transaction(function () use ($id, $bytes, $mime, $u) {
            $doc = DB::table('cis_documents')->insertGetId(['client_id' => $id, 'proposal_id' => null, 'tipo' => 'fotografia',
                'descricao' => 'Fotografia da ficha', 'nome' => 'fotografia.'.explode('/', $mime)[1], 'mime' => $mime, 'tamanho' => strlen($bytes),
                'sha256' => hash('sha256', $bytes), 'uploaded_by' => $u, 'created_at' => now()]);
            DB::table('cis_document_files')->insert(['document_id' => $doc, 'conteudo' => $bytes]);
            $p = CR::profile($id);
            if ($p) DB::table('cis_client_profile')->where('client_id', $id)->update(['foto_document_id' => $doc, 'updated_by' => $u, 'updated_at' => now()]);
            else DB::table('cis_client_profile')->insert(['client_id' => $id, 'tipo' => 'singular', 'foto_document_id' => $doc, 'updated_by' => $u, 'created_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['notice' => 'Fotografia actualizada.', 'tem_foto' => true], 201);
    }

    /** GET cis/mutuarios/{id}/foto */
    public function photo(Request $r, int $id)
    {
        Permissions::require($r->user(), 'ver');
        $this->needReady();
        Portfolio::requireClient($r->user(), $id);
        $doc = CR::profile($id)->foto_document_id ?? null;
        $row = $doc ? DB::table('cis_documents')->where('id', $doc)->whereNull('deleted_at')->first(['mime']) : null;
        abort_unless($row, 404, 'Sem fotografia.');
        $bytes = DB::table('cis_document_files')->where('document_id', $doc)->value('conteudo');
        abort_if($bytes === null, 404, 'Sem fotografia.');
        return response($bytes, 200, ['Content-Type' => $row->mime, 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** POST cis/mutuarios/{id}/lista-negra {motivo} */
    public function blacklist(Request $r, int $id)
    {
        Permissions::require($r->user(), 'propostas.aprovar');
        $this->needReady();
        Portfolio::requireClient($r->user(), $id);
        $this->client($id);
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']], ['motivo.min' => 'Explique o motivo (mínimo 5 letras).']);
        abort_if(CR::blacklisted($id), 409, 'O mutuário já está na lista negra.');
        DB::table('cis_client_blacklist')->insert(['client_id' => $id, 'motivo' => trim($d['motivo']), 'added_by' => $r->user()->id, 'created_at' => now()]);
        CR::setConduta($id, CR::CONDUTA_BLACK);
        return response()->json($this->payload($r, $id) + ['notice' => 'Mutuário adicionado à lista negra.'], 201);
    }

    /** DELETE cis/mutuarios/{id}/lista-negra {motivo} */
    public function unblacklist(Request $r, int $id)
    {
        Permissions::require($r->user(), 'propostas.aprovar');
        $this->needReady();
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:255']], ['motivo.min' => 'Explique porque sai da lista (mínimo 5 letras).']);
        $legacy = in_array($id, CR::legacyBlackIds(), true);
        $open = DB::table('cis_client_blacklist')->where('client_id', $id)->whereNull('removed_at')->exists();
        abort_unless($open || $legacy, 404, 'O mutuário não está na lista negra.');
        // A «Conduta» do cadastro tem de mudar primeiro; se falhar, nada fica a meio.
        if ($legacy) abort_unless(CR::setConduta($id, CR::CONDUTA_DEFAULT), 503,
            'Não foi possível mudar a Conduta no cadastro. Na VPS execute: bash deploy/enable-client-full-edit.sh');
        elseif (CR::conduta($id) !== null) CR::setConduta($id, CR::CONDUTA_DEFAULT);
        if ($open) {
            DB::table('cis_client_blacklist')->where('client_id', $id)->whereNull('removed_at')
                ->update(['removed_at' => now(), 'removed_by' => $r->user()->id, 'removed_motivo' => trim($d['motivo'])]);
        } else {
            // Marcado só no cadastro antigo: fica no histórico a entrada e a saída.
            DB::table('cis_client_blacklist')->insert(['client_id' => $id, 'motivo' => 'Conduta «Lista Negra» no cadastro', 'created_at' => now(),
                'removed_at' => now(), 'removed_by' => $r->user()->id, 'removed_motivo' => trim($d['motivo'])]);
        }
        return response()->json($this->payload($r, $id) + ['notice' => 'Mutuário retirado da lista negra.']);
    }

    /** GET cis/lista-negra : central de risco da empresa (todas as agências, para ninguém conceder crédito a estes mutuários). */
    public function index(Request $r)
    {
        Permissions::require($r->user(), 'ver');
        if (!CR::ready()) return response()->json(['instalado' => false, 'data' => [], 'retirados' => []]);
        $rows = DB::table('cis_client_blacklist as b')->leftJoin('users as a', 'a.id', '=', 'b.added_by')->leftJoin('users as x', 'x.id', '=', 'b.removed_by')
            ->orderByDesc('b.id')->limit(1000)
            ->get(array_merge(['b.id', 'b.client_id', 'b.motivo', 'b.created_at', 'a.name as por', 'b.removed_at', 'x.name as retirado_por', 'b.removed_motivo'],
                \Illuminate\Support\Facades\Schema::hasColumn('cis_client_blacklist', 'origem') ? ['b.origem', 'b.dias_atraso'] : []));
        // Mutuários marcados só pela «Conduta» do cadastro também entram na lista.
        $active = $rows->whereNull('removed_at')->pluck('client_id')->map(fn ($v) => (int) $v)->all();
        foreach (array_diff(CR::legacyBlackIds(), $active) as $lid) {
            $rows->prepend((object) ['id' => null, 'client_id' => $lid, 'motivo' => 'Conduta «Lista Negra» no cadastro', 'created_at' => null,
                'por' => null, 'removed_at' => null, 'retirado_por' => null, 'removed_motivo' => null]);
        }
        $ids = $rows->pluck('client_id')->unique()->values()->all();
        $legacy = DB::connection('mysql_legacy');
        $cols = array_values(array_filter(['ID', 'Nome Completo', 'Contacto', 'Cidade', 'Bairro', 'Nº de BI'], fn ($c) => $c === 'ID' || $legacy->getSchemaBuilder()->hasColumn('Clientes', $c)));
        $info = $ids ? $legacy->table('Clientes')->whereIn('ID', $ids)->get($cols)->keyBy('ID') : collect();
        // Crédito em lista negra: o que cada um ainda deve (prestações em aberto), fora da carteira corrente.
        $debt = $ids ? $legacy->table('Contractos')->whereIn('Nº de Cliente', $ids)->whereRaw("`Estado` IN ('Pendente','Pagamento Atrasado')")
            ->selectRaw('`Nº de Cliente` AS c, ROUND(SUM(COALESCE(`Valor a Pagar`,0)),2) AS divida, COUNT(DISTINCT `Nr do Contracto`) AS contratos')
            ->groupBy('Nº de Cliente')->get()->keyBy(fn ($x) => (int) $x->c) : collect();
        $map = fn ($b) => ['client_id' => (int) $b->client_id, 'origem' => $b->origem ?? ($b->id === null ? 'cadastro' : 'manual'),
            'dias_atraso' => isset($b->dias_atraso) ? (int) $b->dias_atraso : null,
            'divida' => (float) ($debt[(int) $b->client_id]->divida ?? 0), 'contratos' => (int) ($debt[(int) $b->client_id]->contratos ?? 0), 'nome' => $info[$b->client_id]->{'Nome Completo'} ?? ('#'.$b->client_id),
            'contacto' => $info[$b->client_id]->Contacto ?? null, 'residencia' => trim(implode(', ', array_filter([$info[$b->client_id]->Bairro ?? null, $info[$b->client_id]->Cidade ?? null]))) ?: null,
            'bi' => $info[$b->client_id]->{'Nº de BI'} ?? null, 'motivo' => $b->motivo, 'desde' => $b->created_at, 'por' => $b->por,
            'retirado_em' => $b->removed_at, 'retirado_por' => $b->retirado_por, 'motivo_saida' => $b->removed_motivo];
        $activeRows = $rows->whereNull('removed_at')->unique('client_id')->map($map)->values();
        return response()->json(['instalado' => true,
            'credito_lista_negra' => ['mutuarios' => $activeRows->count(), 'contratos' => $activeRows->sum('contratos'), 'valor' => round($activeRows->sum('divida'), 2)],
            'dias_criterio' => CR::overdueLimit(),
            'data' => $activeRows,
            'retirados' => $rows->whereNotNull('removed_at')->map($map)->values(),
            'pode_gerir' => Permissions::can($r->user(), 'propostas.aprovar')])->header('Cache-Control', 'no-store, private');
    }

    /** GET cis/lista-negra/candidatos : mutuários em incumprimento prolongado (critério interno), para decisão. */
    public function candidates(Request $r)
    {
        $u = $r->user();
        $limit = CR::overdueLimit();
        if (!CR::ready() || !Permissions::can($u, 'propostas.aprovar')) {
            return response()->json(['instalado' => CR::ready(), 'pode_decidir' => false, 'dias' => $limit, 'candidatos' => []]);
        }
        $c = CR::overdueCandidates($u, $limit);
        return response()->json(['instalado' => true, 'pode_decidir' => true, 'dias' => $limit,
            'propor_ao_entrar' => \App\Support\CompanySettings::get('lista_negra_automatica') === '1',
            'candidatos' => $c, 'total_atraso' => round(array_sum(array_column($c, 'valor_atraso')), 2),
            'total_divida' => round(array_sum(array_column($c, 'valor_divida')), 2)])->header('Cache-Control', 'no-store, private');
    }

    /** POST cis/lista-negra/automatica {clientes:[ids]} : transfere, com autorização, quem passa do critério. */
    public function autoBlacklist(Request $r)
    {
        $u = $r->user();
        Permissions::require($u, 'propostas.aprovar');
        $this->needReady();
        $d = $r->validate(['clientes' => ['required', 'array', 'min:1', 'max:2000'], 'clientes.*' => ['integer', 'min:1']]);
        $limit = CR::overdueLimit();
        // Volta a verificar no servidor: só entram os que ainda passam do critério.
        $now = collect(CR::overdueCandidates($u, $limit))->keyBy('id');
        $ids = array_values(array_unique(array_map('intval', $d['clientes'])));
        $done = [];
        $hasOrigin = \Illuminate\Support\Facades\Schema::hasColumn('cis_client_blacklist', 'origem');
        DB::transaction(function () use ($ids, $now, $limit, $u, $hasOrigin, &$done) {
            foreach ($ids as $id) {
                $c = $now[$id] ?? null;
                if (!$c || CR::blacklisted($id)) continue;
                $row = ['client_id' => $id, 'motivo' => CR::motivoAutomatico($c['dias'], $limit), 'added_by' => $u->id, 'created_at' => now()];
                if ($hasOrigin) $row += ['origem' => 'automatica', 'dias_atraso' => $c['dias'], 'valor_em_divida' => $c['valor_divida']];
                DB::table('cis_client_blacklist')->insert($row);
                $done[] = $c;
            }
        });
        foreach ($done as $c) CR::setConduta($c['id'], CR::CONDUTA_BLACK);
        $total = array_sum(array_column($done, 'valor_divida'));
        return response()->json(['transferidos' => count($done), 'ignorados' => count($ids) - count($done),
            'valor_divida' => round($total, 2),
            'notice' => count($done).' mutuário(s) transferido(s) para a lista negra por incumprimento prolongado. Saíram da carteira corrente; o crédito fica em «Crédito em lista negra».']);
    }

    /** GET cis/mutuarios/procurar?q= : mutuários da agência (nome, BI, contacto), para escolher um avalista. */
    public function search(Request $r)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar', 'mutuarios.editar']);
        $q = trim((string) $r->query('q', ''));
        abort_if(mb_strlen($q) < 2, 422, 'Escreva pelo menos 2 letras.');
        $legacy = DB::connection('mysql_legacy');
        $cols = array_values(array_filter(['ID', 'Nome Completo', 'Nº de BI', 'Contacto', 'Cidade'], fn ($c) => $c === 'ID' || $legacy->getSchemaBuilder()->hasColumn('Clientes', $c)));
        $query = $legacy->table('Clientes')->where(function ($w) use ($q, $cols) {
            $w->where('Nome Completo', 'like', '%'.addcslashes($q, '%_\\').'%');
            if (in_array('Nº de BI', $cols, true)) $w->orWhere('Nº de BI', 'like', addcslashes($q, '%_\\').'%');
            if (ctype_digit($q)) $w->orWhere('ID', (int) $q);
        });
        Branches::applyToClients($query, Branches::userBranch($r->user()));
        $black = array_flip(CR::blacklistedIds());
        $rows = $query->orderBy('Nome Completo')->limit(15)->get($cols)->map(fn ($c) => ['id' => (int) $c->ID, 'nome' => $c->{'Nome Completo'},
            'bi' => $c->{'Nº de BI'} ?? null, 'contacto' => $c->Contacto ?? null, 'cidade' => $c->Cidade ?? null, 'lista_negra' => isset($black[(int) $c->ID])]);
        return response()->json(['data' => $rows])->header('Cache-Control', 'no-store, private');
    }
}
