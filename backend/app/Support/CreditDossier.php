<?php

namespace App\Support;

use App\Support\CompanySettings as CS;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dossier do crédito: fiadores, garantias e documentos digitalizados, e a lista de verificação
 * que a aprovação exige (Definições › Credit scoring › Dossier do crédito).
 */
final class CreditDossier
{
    public const DOCS = [
        'bi' => 'Bilhete de identidade', 'residencia' => 'Comprovativo de residência', 'rendimento' => 'Comprovativo de rendimento',
        'fotografia' => 'Fotografia do mutuário', 'garantia' => 'Documento / foto da garantia', 'fiador' => 'Documento do fiador',
        'contrato' => 'Contrato assinado', 'outro' => 'Outro documento',
    ];
    /** Documentos que a empresa pode exigir (chave da definição → tipo). */
    public const REQUIRABLE = ['doc_exigir_bi' => 'bi', 'doc_exigir_residencia' => 'residencia', 'doc_exigir_rendimento' => 'rendimento', 'doc_exigir_fotografia' => 'fotografia'];
    public const COLLATERALS = [
        'viatura' => 'Viatura / motorizada', 'imovel' => 'Imóvel / terreno', 'electrodomestico' => 'Electrodoméstico / mobília',
        'equipamento' => 'Equipamento / mercadoria', 'deposito' => 'Depósito / poupança', 'outro' => 'Outro bem',
    ];
    public const MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    public const MAX_KB = 5120;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        try {
            return self::$ready = Schema::hasTable('cis_guarantors') && Schema::hasTable('cis_collaterals')
                && Schema::hasTable('cis_documents') && Schema::hasTable('cis_document_files');
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    public static function reset(): void
    {
        self::$ready = null;
    }

    /** Documentos activos do mutuário (os da ficha e os de qualquer proposta dele). */
    public static function documents(int $clientId, ?int $proposalId = null): array
    {
        if (!self::ready()) return [];
        $q = DB::table('cis_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by')
            ->where('d.client_id', $clientId)->whereNull('d.deleted_at');
        if ($proposalId !== null) $q->where(fn ($w) => $w->whereNull('d.proposal_id')->orWhere('d.proposal_id', $proposalId));
        return $q->orderByDesc('d.id')->get(['d.id', 'd.client_id', 'd.proposal_id', 'd.tipo', 'd.descricao', 'd.nome', 'd.mime', 'd.tamanho', 'd.created_at', 'd.uploaded_by', 'u.name as por'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'tipo' => $d->tipo, 'tipo_label' => self::DOCS[$d->tipo] ?? $d->tipo, 'descricao' => $d->descricao,
                'nome' => $d->nome, 'mime' => $d->mime, 'tamanho' => (int) $d->tamanho, 'em' => $d->created_at, 'por' => $d->por,
                'por_id' => $d->uploaded_by !== null ? (int) $d->uploaded_by : null,
                'da_proposta' => $d->proposal_id !== null, 'proposta_id' => $d->proposal_id !== null ? (int) $d->proposal_id : null])->all();
    }

    public static function guarantors(int $proposalId): array
    {
        if (!self::ready()) return [];
        return DB::table('cis_guarantors')->where('proposal_id', $proposalId)->orderBy('id')->get()
            ->map(fn ($g) => ['id' => (int) $g->id, 'nome' => $g->nome, 'bi' => $g->bi, 'contacto' => $g->contacto, 'relacao' => $g->relacao,
                'morada' => $g->morada, 'actividade' => $g->actividade, 'rendimento_mensal' => $g->rendimento_mensal])->all();
    }

    public static function collaterals(int $proposalId): array
    {
        if (!self::ready()) return [];
        return DB::table('cis_collaterals')->where('proposal_id', $proposalId)->orderBy('id')->get()
            ->map(fn ($c) => ['id' => (int) $c->id, 'tipo' => $c->tipo, 'tipo_label' => self::COLLATERALS[$c->tipo] ?? $c->tipo, 'descricao' => $c->descricao,
                'valor_estimado' => $c->valor_estimado, 'localizacao' => $c->localizacao])->all();
    }

    /**
     * Lista de verificação para aprovar $amount (por omissão o montante pedido/aprovado).
     * @return array{itens:array,em_falta:array,cobertura:array}
     */
    public static function checklist(object $p, ?float $amount = null): array
    {
        $amount ??= (float) ($p->montante_aprovado ?? $p->montante);
        $out = ['itens' => [], 'em_falta' => [], 'cobertura' => null];
        if (!self::ready()) return $out;
        $have = collect(self::documents((int) $p->client_id, (int) $p->id))->pluck('tipo')->flip();
        foreach (self::REQUIRABLE as $key => $tipo) {
            if (CS::get($key) !== '1') continue;
            $out['itens'][] = ['chave' => 'doc_'.$tipo, 'label' => self::DOCS[$tipo], 'ok' => isset($have[$tipo])];
        }
        $minGuarantor = CS::num('fiador_a_partir_de');
        if ($minGuarantor > 0 && $amount >= $minGuarantor) {
            $n = DB::table('cis_guarantors')->where('proposal_id', $p->id)->count();
            $out['itens'][] = ['chave' => 'fiador', 'label' => 'Fiador (crédito a partir de '.number_format($minGuarantor, 0, ',', ' ').' MT)', 'ok' => $n > 0];
        }
        $pct = CS::num('cobertura_garantias_pct');
        $total = (float) DB::table('cis_collaterals')->where('proposal_id', $p->id)->sum('valor_estimado');
        $need = round($amount * $pct / 100, 2);
        $out['cobertura'] = ['valor' => number_format($total, 2, '.', ''), 'necessario' => number_format($need, 2, '.', ''), 'pct_exigida' => $pct,
            'pct' => $amount > 0 ? round($total / $amount * 100, 1) : null];
        if ($pct > 0) {
            $out['itens'][] = ['chave' => 'cobertura', 'label' => 'Garantias cobrem '.rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.').'% do crédito', 'ok' => $total + 0.004 >= $need];
        }
        $out['em_falta'] = array_values(array_map(fn ($i) => $i['label'], array_filter($out['itens'], fn ($i) => !$i['ok'])));
        return $out;
    }
}
