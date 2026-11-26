<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CreditDossier as CD;
use App\Support\Permissions;
use App\Support\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dossier do crédito: fiadores, garantias e documentos digitalizados (mutuário e proposta).
 * Os ficheiros ficam na base de dados (cis_document_files), incluídos na cópia de segurança.
 */
final class CreditDossierController extends Controller
{
    private function needReady(): void
    {
        abort_unless(CD::ready(), 503, 'O dossier do crédito ainda não está instalado. Na VPS execute: bash deploy/install-credit-dossier.sh');
    }

    private function proposal(Request $r, int $id): object
    {
        return (new ProposalController())->visible($r, $id);
    }

    /** Fiadores e garantias só se alteram antes da decisão, por quem propôs ou por quem aprova. */
    private static function canEdit(?object $u, object $p): bool
    {
        if (!in_array($p->status, ['rascunho', 'devolvida', 'submetida'], true)) return false;
        return (int) $p->created_by === (int) $u->id || Permissions::can($u, 'propostas.aprovar');
    }

    private function payload(Request $r, object $p): array
    {
        $u = $r->user();
        return [
            'instalado' => true,
            'fiadores' => CD::guarantors((int) $p->id), 'garantias' => CD::collaterals((int) $p->id),
            'documentos' => CD::documents((int) $p->client_id, (int) $p->id),
            'verificacao' => CD::checklist($p),
            'pode_editar' => self::canEdit($u, $p),
            'pode_anexar' => Permissions::can($u, 'mutuarios.editar', 'propostas.criar', 'propostas.aprovar'),
            'tipos_documento' => CD::DOCS, 'tipos_garantia' => CD::COLLATERALS,
            'max_kb' => CD::MAX_KB,
        ];
    }

    public function show(Request $r, int $id)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar', 'contratos.abrir']);
        $p = $this->proposal($r, $id);
        if (!CD::ready()) return response()->json(['instalado' => false]);
        return response()->json($this->payload($r, $p))->header('Cache-Control', 'no-store, private');
    }

    public function addGuarantor(Request $r, int $id)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $p = $this->proposal($r, $id);
        abort_unless(self::canEdit($r->user(), $p), 409, 'Os fiadores só se alteram antes da decisão, por quem propôs ou por quem aprova.');
        $d = $r->validate([
            'nome' => ['required', 'string', 'min:3', 'max:160'], 'bi' => ['nullable', 'string', 'max:40'],
            'contacto' => ['nullable', 'string', 'max:40'], 'relacao' => ['nullable', 'string', 'max:60'],
            'morada' => ['nullable', 'string', 'max:255'], 'actividade' => ['nullable', 'string', 'max:160'],
            'rendimento_mensal' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'mutuario_id' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if(DB::table('cis_guarantors')->where('proposal_id', $id)->count() >= 5, 422, 'Máximo de 5 fiadores por proposta.');
        // Avalista já registado como mutuário: valida e preenche com a ficha (como no GEMIC).
        $gc = isset($d['mutuario_id']) ? (int) $d['mutuario_id'] : null;
        unset($d['mutuario_id']);
        if ($gc !== null) {
            abort_if($gc === (int) $p->client_id, 422, 'O mutuário não pode ser avalista do próprio crédito.');
            abort_if(DB::table('cis_guarantors')->where('proposal_id', $id)->where('guarantor_client_id', $gc)->exists(), 422, 'Este avalista já está na proposta.');
            \App\Support\ClientRegistry::requireClear($gc, 'O avalista escolhido');
            $legacy = DB::connection('mysql_legacy');
            $cols = array_values(array_filter(['ID', 'Nome Completo', 'Nº de BI', 'Contacto', 'Bairro', 'Cidade'], fn ($c) => $c === 'ID' || $legacy->getSchemaBuilder()->hasColumn('Clientes', $c)));
            $c = $legacy->table('Clientes')->where('ID', $gc)->first($cols);
            abort_unless($c, 422, 'O avalista escolhido não existe.');
            $d['nome'] = mb_substr((string) $c->{'Nome Completo'}, 0, 160);
            $d['bi'] = ($d['bi'] ?? null) ?: (isset($c->{'Nº de BI'}) ? mb_substr((string) $c->{'Nº de BI'}, 0, 40) : null);
            $d['contacto'] = ($d['contacto'] ?? null) ?: (isset($c->Contacto) ? mb_substr((string) $c->Contacto, 0, 40) : null);
            $d['morada'] = ($d['morada'] ?? null) ?: (trim(implode(', ', array_filter([$c->Bairro ?? null, $c->Cidade ?? null]))) ?: null);
            if (\Illuminate\Support\Facades\Schema::hasColumn('cis_guarantors', 'guarantor_client_id')) $d['guarantor_client_id'] = $gc;
        }
        DB::table('cis_guarantors')->insert($d + ['proposal_id' => $id, 'client_id' => $p->client_id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->payload($r, $p), 201);
    }

    public function removeGuarantor(Request $r, int $id, int $gid)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $p = $this->proposal($r, $id);
        abort_unless(self::canEdit($r->user(), $p), 409, 'Os fiadores só se alteram antes da decisão.');
        abort_unless(DB::table('cis_guarantors')->where('proposal_id', $id)->where('id', $gid)->delete() === 1, 404, 'Fiador não encontrado.');
        return response()->json($this->payload($r, $p));
    }

    public function addCollateral(Request $r, int $id)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $p = $this->proposal($r, $id);
        abort_unless(self::canEdit($r->user(), $p), 409, 'As garantias só se alteram antes da decisão, por quem propôs ou por quem aprova.');
        $d = $r->validate([
            'tipo' => ['required', 'in:'.implode(',', array_keys(CD::COLLATERALS))],
            'descricao' => ['required', 'string', 'min:3', 'max:255'],
            'valor_estimado' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'localizacao' => ['nullable', 'string', 'max:160'],
        ]);
        abort_if(DB::table('cis_collaterals')->where('proposal_id', $id)->count() >= 20, 422, 'Máximo de 20 garantias por proposta.');
        DB::table('cis_collaterals')->insert($d + ['proposal_id' => $id, 'client_id' => $p->client_id, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json($this->payload($r, $p), 201);
    }

    public function removeCollateral(Request $r, int $id, int $cid)
    {
        Permissions::require($r->user(), ['propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $p = $this->proposal($r, $id);
        abort_unless(self::canEdit($r->user(), $p), 409, 'As garantias só se alteram antes da decisão.');
        abort_unless(DB::table('cis_collaterals')->where('proposal_id', $id)->where('id', $cid)->delete() === 1, 404, 'Garantia não encontrada.');
        return response()->json($this->payload($r, $p));
    }

    /** GET cis/mutuarios/{id}/documentos : documentos da ficha do mutuário (e das suas propostas). */
    public function clientDocuments(Request $r, int $id)
    {
        Permissions::require($r->user(), 'ver');
        Portfolio::requireClient($r->user(), $id);
        if (!CD::ready()) return response()->json(['instalado' => false, 'documentos' => []]);
        return response()->json(['instalado' => true, 'documentos' => CD::documents($id),
            'pode_anexar' => Permissions::can($r->user(), 'mutuarios.editar', 'propostas.criar', 'propostas.aprovar'),
            'tipos_documento' => CD::DOCS, 'max_kb' => CD::MAX_KB])->header('Cache-Control', 'no-store, private');
    }

    /** POST cis/documentos (multipart): ficheiro, client_id, tipo, [proposal_id], [descricao]. */
    public function upload(Request $r)
    {
        Permissions::require($r->user(), ['mutuarios.editar', 'propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $d = $r->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'proposal_id' => ['nullable', 'integer', 'min:1'],
            'tipo' => ['required', 'in:'.implode(',', array_keys(CD::DOCS))],
            'descricao' => ['nullable', 'string', 'max:160'],
            'ficheiro' => ['required', 'file', 'max:'.CD::MAX_KB, 'mimes:'.implode(',', CD::MIMES)],
        ], ['ficheiro.max' => 'O ficheiro tem mais de 5 MB.', 'ficheiro.mimes' => 'Aceita PDF ou imagem (JPG, PNG, WEBP).', 'ficheiro.uploaded' => 'O envio do ficheiro falhou (máx. 5 MB).']);
        $client = (int) $d['client_id'];
        Portfolio::requireClient($r->user(), $client);
        abort_unless(DB::connection('mysql_legacy')->table('Clientes')->where('ID', $client)->exists(), 422, 'Mutuário inexistente.');
        if (!empty($d['proposal_id'])) {
            $p = $this->proposal($r, (int) $d['proposal_id']);
            abort_unless((int) $p->client_id === $client, 422, 'A proposta é de outro mutuário.');
        }
        $file = $r->file('ficheiro');
        $bytes = (string) file_get_contents($file->getRealPath());
        abort_if($bytes === '', 422, 'Ficheiro vazio.');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 422, 'Aceita PDF ou imagem (JPG, PNG, WEBP).');
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'documento', 0, 190);
        $id = DB::transaction(function () use ($d, $client, $bytes, $mime, $name, $r) {
            $id = DB::table('cis_documents')->insertGetId([
                'client_id' => $client, 'proposal_id' => $d['proposal_id'] ?? null, 'tipo' => $d['tipo'], 'descricao' => $d['descricao'] ?? null,
                'nome' => $name, 'mime' => $mime, 'tamanho' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
                'uploaded_by' => $r->user()->id, 'created_at' => now(),
            ]);
            DB::table('cis_document_files')->insert(['document_id' => $id, 'conteudo' => $bytes]);
            return $id;
        });
        return response()->json(['id' => $id, 'notice' => 'Documento anexado.', 'documentos' => CD::documents($client, isset($d['proposal_id']) ? (int) $d['proposal_id'] : null)], 201);
    }

    /** GET cis/documentos/{id} : o ficheiro (só para quem vê o mutuário). */
    public function download(Request $r, int $id)
    {
        Permissions::require($r->user(), 'ver');
        $this->needReady();
        $doc = DB::table('cis_documents')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($doc, 404, 'Documento não encontrado.');
        Portfolio::requireClient($r->user(), (int) $doc->client_id);
        $bytes = DB::table('cis_document_files')->where('document_id', $id)->value('conteudo');
        abort_if($bytes === null, 404, 'Ficheiro em falta.');
        return response($bytes, 200, [
            'Content-Type' => $doc->mime, 'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.addcslashes($doc->nome, '"\\').'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** DELETE cis/documentos/{id} : retira o documento (fica registado quem e quando). */
    public function remove(Request $r, int $id)
    {
        $u = $r->user();
        Permissions::require($u, ['mutuarios.editar', 'propostas.criar', 'propostas.aprovar']);
        $this->needReady();
        $doc = DB::table('cis_documents')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($doc, 404, 'Documento não encontrado.');
        Portfolio::requireClient($u, (int) $doc->client_id);
        abort_unless((int) $doc->uploaded_by === (int) $u->id || Permissions::can($u, 'propostas.aprovar'), 403, 'Só quem anexou ou quem aprova propostas pode retirar o documento.');
        DB::table('cis_documents')->where('id', $id)->update(['deleted_at' => now(), 'deleted_by' => $u->id]);
        return response()->json(['notice' => 'Documento retirado.', 'documentos' => CD::documents((int) $doc->client_id, $doc->proposal_id !== null ? (int) $doc->proposal_id : null)]);
    }
}
