<?php

namespace App\Http\Controllers\Api;

use App\Services\ContractNumbering;
use App\Support\CompanySettings as CS;
use App\Support\Extenso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Modelos de contrato com variáveis {…}: catálogo de variáveis, valores de um contrato e edição dos modelos.
 * A composição (substituição das variáveis) é feita no CIS a partir destes valores; não altera os contratos.
 */
class ContractTemplateController
{
    /** nome => [grupo, descrição, exemplo] */
    public const VARS = [
        'Nome da Empresa' => ['Empresa', 'Nome registado da empresa', 'CAPTA Microcrédito, Lda'],
        'NUIT da Empresa' => ['Empresa', 'NUIT da empresa', '400123456'],
        'Licença BM' => ['Empresa', 'N.º de licença/registo no Banco de Moçambique', 'BM-123'],
        'Morada da Empresa' => ['Empresa', 'Morada da sede', 'Av. 24 de Julho, 1234'],
        'Distrito da Empresa' => ['Empresa', 'Distrito da sede', 'KaMpfumo'],
        'Província da Empresa' => ['Empresa', 'Província da sede', 'Maputo Cidade'],
        'Contacto da Empresa' => ['Empresa', 'Contacto principal', '84 000 1111'],
        'Email da Empresa' => ['Empresa', 'Email', 'geral@empresa.co.mz'],
        'Proprietário' => ['Empresa', 'Proprietário / representante', 'Nome do proprietário'],
        'Conta para Reembolsos' => ['Empresa', 'Conta onde o mutuário paga (Definições)', 'BIM 347807372, titular CAPTA'],
        'Foro Judicial' => ['Empresa', 'Tribunal competente (Definições)', 'Gondola'],
        'Nr do Contrato' => ['Contrato', 'Número do contrato', 'CAPTA-2026-0001'],
        'Capital' => ['Contrato', 'Capital desembolsado (MT)', '4 000,00'],
        'Capital por Extenso' => ['Contrato', 'Capital desembolsado por extenso', 'quatro mil meticais'],
        'N.º de Prestações' => ['Contrato', 'Número de prestações', '1'],
        'Valor da Prestação' => ['Contrato', 'Valor da primeira prestação (MT)', '4 800,00'],
        'Taxa de Juro' => ['Contrato', 'Taxa de juro por período (%)', '20'],
        'Tipo de Juro' => ['Contrato', 'Fixo, variável ou composto', 'fixo'],
        'Periodicidade' => ['Contrato', 'Semanal, mensal ou prestação única', 'mensal'],
        'Prazo' => ['Contrato', 'Prazo do contrato', '1 Mês'],
        'Sistema de Amortização' => ['Contrato', 'Fórmula de cálculo das prestações', 'Juros simples'],
        'Taxa de Juros de Mora' => ['Contrato', 'Taxa de juros de mora do contrato (%)', '10'],
        'Forma de Pagamento' => ['Contrato', 'Numerário, M-Pesa, e-Mola…', 'M-Pesa'],
        'Data de Desembolso' => ['Contrato', 'Data de início / desembolso', '15/10/2026'],
        'Primeiro Vencimento' => ['Contrato', 'Data de vencimento da 1.ª prestação', '15/11/2026'],
        'Vencimento Final' => ['Contrato', 'Data da última prestação', '15/11/2026'],
        'Total de Juros' => ['Contrato', 'Soma dos juros das prestações (MT)', '800,00'],
        'Total a Pagar' => ['Contrato', 'Capital + juros (MT)', '4 800,00'],
        'Nome do Mutuário' => ['Mutuário', 'Nome completo', 'Ana Matsinhe'],
        'N.º do Mutuário' => ['Mutuário', 'Número do mutuário no cadastro', '42'],
        'Tipo de Documento' => ['Mutuário', 'BI, passaporte…', 'BI'],
        'N.º do Documento' => ['Mutuário', 'Número do documento de identificação', '110102345678B'],
        'Data de Emissão do Documento' => ['Mutuário', 'Data de emissão do documento', '10/01/2020'],
        'Local de Emissão do Documento' => ['Mutuário', 'Local de emissão', 'Maputo'],
        'Validade do Documento' => ['Mutuário', 'Validade do documento', '10/01/2030'],
        'NUIT do Mutuário' => ['Mutuário', 'NUIT', '100200300'],
        'Naturalidade' => ['Mutuário', 'Naturalidade', 'Maputo'],
        'Nacionalidade' => ['Mutuário', 'Nacionalidade', 'Moçambicana'],
        'Estado Civil' => ['Mutuário', 'Estado civil', 'Solteiro(a)'],
        'Profissão' => ['Mutuário', 'Profissão', 'Comerciante'],
        'Morada do Mutuário' => ['Mutuário', 'Bairro, rua, n.º da casa, cidade', 'Bairro Polana, Rua 1, casa 12, Maputo'],
        'Contacto do Mutuário' => ['Mutuário', 'Contacto principal', '84 123 4567'],
        'Cidade' => ['Documento', 'Cidade do contrato (ou da empresa)', 'Maputo'],
        'Data de Hoje' => ['Documento', 'Data de emissão do documento', '19/11/2026'],
        'Plano de Pagamentos' => ['Blocos', 'Tabela com as prestações (sozinho numa linha)', '(tabela)'],
        'Assinaturas' => ['Blocos', 'Linhas de assinatura (sozinho numa linha)', '(assinaturas)'],
    ];

    private static function admin(Request $r): void
    {
        abort_unless($r->user()?->role === 'administrador', 403, 'Só o administrador edita os modelos de contrato.');
    }

    private static function ready(): void
    {
        abort_unless(Schema::hasTable('cis_contract_templates'), 503, 'Modelos de contrato ainda não instalados. Execute: bash deploy/install-company-settings.sh');
    }

    private function payload(): array
    {
        return [
            'modelos' => DB::table('cis_contract_templates')->orderByDesc('padrao')->orderBy('nome')->get(['id', 'nome', 'corpo', 'padrao', 'updated_by', 'updated_at']),
            'variaveis' => array_map(fn($k, $v) => ['nome' => $k, 'grupo' => $v[0], 'descricao' => $v[1], 'exemplo' => $v[2]], array_keys(self::VARS), self::VARS),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        self::ready();
        return response()->json($this->payload());
    }

    public function store(Request $request): JsonResponse
    {
        self::admin($request); self::ready();
        $d = $this->valid($request);
        $id = DB::table('cis_contract_templates')->insertGetId($d + ['padrao' => !DB::table('cis_contract_templates')->exists(), 'created_at' => now(), 'updated_at' => now(),
            'updated_by' => mb_substr((string)($request->user()?->name ?? ''), 0, 120)]);
        return response()->json($this->payload() + ['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        self::admin($request); self::ready();
        abort_unless(DB::table('cis_contract_templates')->where('id', $id)->exists(), 404, 'Modelo inexistente.');
        if ($request->boolean('padrao') && !$request->has('corpo')) {
            DB::transaction(function () use ($id) {
                DB::table('cis_contract_templates')->update(['padrao' => false]);
                DB::table('cis_contract_templates')->where('id', $id)->update(['padrao' => true]);
            });
            return response()->json($this->payload());
        }
        $d = $this->valid($request, $id);
        DB::table('cis_contract_templates')->where('id', $id)->update($d + ['updated_at' => now(), 'updated_by' => mb_substr((string)($request->user()?->name ?? ''), 0, 120)]);
        return response()->json($this->payload());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        self::admin($request); self::ready();
        $m = DB::table('cis_contract_templates')->where('id', $id)->first();
        abort_unless($m, 404, 'Modelo inexistente.');
        abort_if((bool)$m->padrao, 422, 'Escolha outro modelo como padrão antes de eliminar este.');
        DB::table('cis_contract_templates')->where('id', $id)->delete();
        return response()->json($this->payload());
    }

    private function valid(Request $r, ?int $id = null): array
    {
        $nome = trim((string)$r->input('nome')); $corpo = (string)$r->input('corpo');
        $e = [];
        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 120) $e['nome'] = 'Nome com 3 a 120 caracteres.';
        elseif (DB::table('cis_contract_templates')->where('nome', $nome)->when($id, fn($q) => $q->where('id', '<>', $id))->exists()) $e['nome'] = 'Já existe um modelo com este nome.';
        if (mb_strlen(trim($corpo)) < 20) $e['corpo'] = 'O texto do contrato está vazio.';
        if (strlen($corpo) > 200000) $e['corpo'] = 'Texto demasiado longo.';
        if ($e) throw ValidationException::withMessages($e);
        return ['nome' => $nome, 'corpo' => str_replace("\r\n", "\n", $corpo)];
    }

    /** Valores das variáveis de um contrato (e plano de pagamentos). */
    public function values(Request $request, string $numero): JsonResponse
    {
        abort_unless($request->user()?->active, 403);
        $numero = ContractNumbering::resolveIfMissing(trim($numero));
        $db = DB::connection('mysql_legacy');
        $have = array_flip(Schema::connection('mysql_legacy')->getColumnListing('Contractos'));
        $want = ['Ordem', 'Nº da Parcela', 'Nome do Cliente', 'Nº de Cliente', 'Quantia Creditada', 'M. Pagamento', 'Prazo/Duração', '% de Juro', 'Tipo de Juro',
            'Estratégia de Cálculo', '% de Multa', 'Forma de Pagamento', 'Data de Inicio', 'Data de Cobrança', 'Data Final', 'Cidade', 'Capital', 'Amortização', 'Valor de Juros', 'Valor a Pagar', 'Estado'];
        $sel = implode(',', array_map(fn($c) => '`' . $c . '`', array_values(array_filter($want, fn($c) => isset($have[$c])))));
        $rows = $db->table('Contractos')->where('Nr do Contracto', $numero)->selectRaw($sel)->orderBy('Nº da Parcela')->orderBy('Ordem')->get();
        abort_if($rows->isEmpty(), 404, 'Contrato não encontrado.');
        // Só as prestações do plano (as renovadas aparecem como histórico).
        $plan = $rows->values();
        $f = $rows->first();
        $g = fn($o, $k) => $o && property_exists($o, $k) ? trim((string)$o->$k) : '';
        $dt = fn($v) => preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$v, $m) && $m[1] !== '0000' ? "$m[3]/$m[2]/$m[1]" : '';
        $mt = fn($v) => number_format((float)$v, 2, ',', ' ');
        $cli = null;
        try {
            $ch = array_flip(Schema::connection('mysql_legacy')->getColumnListing('Clientes'));
            $cw = array_values(array_filter(['ID', 'Numeração', 'Nome Completo', 'Nº de BI', 'tipo_documento', 'Data de Emissão', 'local_emissao_documento', 'validade_documento', 'nuit',
                'Naturalidade', 'Nacionalidade', 'Estado Civil', 'profissao', 'Bairro', 'Rua', 'Nº da Casa', 'Nº de Quarterão', 'Cidade', 'Provincia', 'Contacto'], fn($c) => isset($ch[$c])));
            if ((int)$g($f, 'Nº de Cliente') > 0) $cli = $db->table('Clientes')->where('ID', (int)$g($f, 'Nº de Cliente'))->first($cw);
        } catch (\Throwable) {}
        $cs = CS::load()['valores'];
        $morada = implode(', ', array_filter([
            $g($cli, 'Bairro') !== '' ? 'Bairro ' . preg_replace('/^bairro\s+/i', '', $g($cli, 'Bairro')) : '',
            $g($cli, 'Rua'), $g($cli, 'Nº da Casa') !== '' ? 'casa n.º ' . $g($cli, 'Nº da Casa') : '',
            $g($cli, 'Nº de Quarterão') !== '' ? 'quarteirão ' . $g($cli, 'Nº de Quarterão') : '', $g($cli, 'Cidade'), $g($cli, 'Provincia')]));
        $mod = $g($f, 'M. Pagamento');
        $totalJuros = $plan->sum(fn($r) => (float)($r->{'Valor de Juros'} ?? 0));
        $capital = (float)$g($f, 'Quantia Creditada');
        $vals = [
            'Nome da Empresa' => $cs['nome_empresa'], 'NUIT da Empresa' => $cs['nuit'], 'Licença BM' => $cs['licenca_bm'],
            'Morada da Empresa' => $cs['morada'], 'Distrito da Empresa' => $cs['distrito'], 'Província da Empresa' => $cs['provincia'],
            'Contacto da Empresa' => $cs['contacto_principal'], 'Email da Empresa' => $cs['email'], 'Proprietário' => $cs['proprietario'],
            'Conta para Reembolsos' => $cs['conta_reembolsos'], 'Foro Judicial' => $cs['foro_judicial'],
            'Nr do Contrato' => $numero, 'Capital' => $mt($capital), 'Capital por Extenso' => Extenso::meticais($capital),
            'N.º de Prestações' => (string)$plan->pluck('Nº da Parcela')->unique()->count(),
            'Valor da Prestação' => $mt($plan->first()->{'Valor a Pagar'} ?? 0), 'Taxa de Juro' => rtrim(rtrim(number_format((float)$g($f, '% de Juro'), 2, ',', ''), '0'), ','),
            'Tipo de Juro' => mb_strtolower($g($f, 'Tipo de Juro')), 'Periodicidade' => $mod === 'Uma Parcela' ? 'em prestação única' : mb_strtolower($mod),
            'Prazo' => $g($f, 'Prazo/Duração'), 'Sistema de Amortização' => $g($f, 'Estratégia de Cálculo'),
            'Taxa de Juros de Mora' => rtrim(rtrim(number_format((float)$g($f, '% de Multa'), 2, ',', ''), '0'), ','), 'Forma de Pagamento' => $g($f, 'Forma de Pagamento'),
            'Data de Desembolso' => $dt($rows->min('Data de Inicio')), 'Primeiro Vencimento' => $dt($rows->min('Data de Cobrança')),
            'Vencimento Final' => $dt($g($f, 'Data Final') ?: $rows->max('Data de Cobrança')),
            'Total de Juros' => $mt($totalJuros), 'Total a Pagar' => $mt($capital + $totalJuros),
            'Nome do Mutuário' => $g($cli, 'Nome Completo') ?: $g($f, 'Nome do Cliente'), 'N.º do Mutuário' => $g($cli, 'Numeração') ?: $g($f, 'Nº de Cliente'),
            'Tipo de Documento' => $g($cli, 'tipo_documento') ?: 'BI', 'N.º do Documento' => $g($cli, 'Nº de BI'),
            'Data de Emissão do Documento' => $dt($g($cli, 'Data de Emissão')), 'Local de Emissão do Documento' => $g($cli, 'local_emissao_documento'),
            'Validade do Documento' => $dt($g($cli, 'validade_documento')), 'NUIT do Mutuário' => $g($cli, 'nuit'),
            'Naturalidade' => $g($cli, 'Naturalidade'), 'Nacionalidade' => $g($cli, 'Nacionalidade'), 'Estado Civil' => $g($cli, 'Estado Civil'),
            'Profissão' => $g($cli, 'profissao'), 'Morada do Mutuário' => $morada, 'Contacto do Mutuário' => $g($cli, 'Contacto'),
            'Cidade' => $g($f, 'Cidade') ?: ($cs['distrito'] ?: $cs['provincia']), 'Data de Hoje' => now('Africa/Maputo')->format('d/m/Y'),
        ];
        $plano = $plan->map(fn($r) => ['prestacao' => (string)$r->{'Nº da Parcela'}, 'vencimento' => $dt($r->{'Data de Cobrança'} ?? ''),
            'capital' => $mt($r->{'Amortização'} ?? 0), 'juros' => $mt($r->{'Valor de Juros'} ?? 0), 'valor' => $mt($r->{'Valor a Pagar'} ?? 0)])->values();
        return response()->json(['contrato' => $numero, 'valores' => $vals, 'plano' => $plano])->header('Cache-Control', 'no-store, private');
    }
}
