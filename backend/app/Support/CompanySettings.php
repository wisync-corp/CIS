<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Configurações da empresa guardadas em cis_company_settings (chave/valor).
 * Sem tabela instalada devolve os valores por defeito (nunca falha a leitura).
 */
final class CompanySettings
{
    public const PROVINCIAS = ['Maputo Cidade','Maputo Província','Gaza','Inhambane','Sofala','Manica','Tete','Zambézia','Nampula','Cabo Delgado','Niassa'];

    /** chave => [grupo, tipo, regra extra, valor por defeito] */
    public const FIELDS = [
        // Perfil da empresa
        'nome_empresa'          => ['perfil','texto',120,''],
        'nuit'                  => ['perfil','nuit',null,''],
        'licenca_bm'            => ['perfil','texto',60,''],
        'inicio_actividade'     => ['perfil','data',null,''],
        'capital_inicial'       => ['perfil','valor',null,'0'],
        'funcionarios'          => ['perfil','inteiro',[0,10000],'0'],
        'provincia'             => ['perfil','lista',self::PROVINCIAS,''],
        'distrito'              => ['perfil','texto',80,''],
        'morada'                => ['perfil','texto',200,''],
        'contacto_principal'    => ['perfil','texto',30,''],
        'contacto_alternativo'  => ['perfil','texto',30,''],
        'email'                 => ['perfil','email',null,''],
        'proprietario'          => ['perfil','texto',120,''],
        'logotipo'              => ['perfil','imagem',null,''],
        'conta_reembolsos'      => ['perfil','texto',160,''],
        'foro_judicial'         => ['perfil','texto',120,''],
        // Cobrança e multa
        'limite_mensal_dias_mora' => ['cobranca','inteiro',[0,31],'30'],
        'tolerancia_dias'         => ['cobranca','inteiro',[0,60],'0'],
        'penalidades_automaticas' => ['cobranca','booleano',null,'0'],
        'taxa_diaria_mora'        => ['cobranca','percentagem',null,'0'],
        'dias_carencia'           => ['cobranca','inteiro',[0,90],'0'],
        'fim_de_semana'           => ['cobranca','lista',['permitir','adiar','antecipar'],'permitir'],
        'modalidade_calendario'   => ['cobranca','lista',['dias_corridos','mesmo_dia_mes'],'dias_corridos'],
        'lembrete_dias_antes'     => ['cobranca','inteiro',[0,30],'2'],
        'lembrete_no_dia'         => ['cobranca','booleano',null,'1'],
        'lembretes_atraso_dias'   => ['cobranca','texto',40,'1,7,15,30'],
        'dias_sem_contacto'       => ['cobranca','inteiro',[1,90],'7'],
        // Critério interno: incumprimento prolongado → proposta de lista negra ao entrar.
        'dias_lista_negra'        => ['cobranca','inteiro',[30,720],'90'],
        'lista_negra_automatica'  => ['cobranca','booleano',null,'1'],
        'canal_notificacao'       => ['cobranca','lista',['whatsapp_web','whatsapp','nenhum'],'whatsapp_web'],
        // Credit scoring
        'provisao_sem_historico' => ['scoring','percentagem_opcional',null,''],
        'provisao_baixo' => ['scoring','percentagem_opcional',null,''],
        'provisao_medio' => ['scoring','percentagem_opcional',null,''],
        'provisao_alto' => ['scoring','percentagem_opcional',null,''],
        'score_minimo'            => ['scoring','inteiro',[0,100],'60'],
        'score_baixo_risco'       => ['scoring','inteiro',[0,100],'80'],
        'score_risco_medio'       => ['scoring','inteiro',[0,100],'60'],
        'max_creditos_activos'    => ['scoring','inteiro',[1,20],'1'],
        'max_creditos_atraso'     => ['scoring','inteiro',[0,20],'0'],
        'taxa_esforco_max'        => ['scoring','percentagem',null,'30'],
        // Propostas de crédito
        'limite_aprovacao_gestor' => ['scoring','valor',null,'50000'],
        'validade_aprovacao_dias' => ['scoring','inteiro',[1,180],'30'],
        // Dossier do crédito (exigido para aprovar)
        'doc_exigir_bi'           => ['scoring','booleano',null,'1'],
        'doc_exigir_residencia'   => ['scoring','booleano',null,'0'],
        'doc_exigir_rendimento'   => ['scoring','booleano',null,'0'],
        'doc_exigir_fotografia'   => ['scoring','booleano',null,'0'],
        'fiador_a_partir_de'      => ['scoring','valor',null,'0'],
        'cobertura_garantias_pct' => ['scoring','percentagem',null,'0'],
        // Numeração dos contratos (PREFIXO-ANO-SEQUÊNCIA)
        'contrato_prefixo'        => ['numeracao','prefixo',null,'CAPTA'],
        'contrato_digitos'        => ['numeracao','inteiro',[3,6],'4'],
        'numeracao_anual_activa'  => ['numeracao','sistema',null,'0'],
        'recibo_prefixo'          => ['numeracao','prefixo',null,'REC'],
        'recibos_numerados'       => ['numeracao','sistema',null,'0'],
        // Incentivos
        'incentivo_cliente_novo'  => ['incentivos','valor',null,'0'],
        'incentivo_renovacao'     => ['incentivos','valor',null,'0'],
        'incentivo_taxa_capital'  => ['incentivos','percentagem',null,'0'],
        'incentivo_taxa_juros'    => ['incentivos','percentagem',null,'0'],
    ];

    private static ?array $cache = null;

    public static function defaults(): array
    {
        return array_map(fn($f) => $f[3], self::FIELDS);
    }

    /** @return array{valores:array<string,string>,instalado:bool,actualizado_em:?string,actualizado_por:?string} */
    public static function load(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) return self::$cache;
        $vals = self::defaults(); $installed = true; $at = null; $by = null;
        try {
            foreach (DB::table('cis_company_settings')->get() as $r) {
                if (array_key_exists($r->chave, $vals)) $vals[$r->chave] = (string)($r->valor ?? '');
                if ($r->updated_at && (!$at || $r->updated_at > $at)) { $at = (string)$r->updated_at; $by = $r->updated_by; }
            }
        } catch (\Throwable) { $installed = false; }
        return self::$cache = ['valores' => $vals, 'instalado' => $installed, 'actualizado_em' => $at, 'actualizado_por' => $by];
    }

    public static function get(string $key): string
    {
        return self::load()['valores'][$key] ?? '';
    }

    public static function num(string $key): float
    {
        return (float)self::get($key);
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Perfil para cabeçalhos de documentos (visível a qualquer utilizador autenticado). */
    public static function profile(): array
    {
        $v = self::load()['valores'];
        return array_intersect_key($v, array_filter(self::FIELDS, fn($f) => $f[0] === 'perfil'));
    }
}
