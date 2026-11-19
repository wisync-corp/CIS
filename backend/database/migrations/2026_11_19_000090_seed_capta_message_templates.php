<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de mensagem da CAPTA (folha MessageTemplates / tblMessages do VBA), texto original.
 * Só insere os que ainda não existem pelo nome: nunca substitui um modelo editado no CIS.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cis_message_templates')) return;
        $now=now();
        foreach (self::templates() as $name=>$body) {
            if (DB::table('cis_message_templates')->where('name',$name)->exists()) continue;
            DB::table('cis_message_templates')->insert(['name'=>$name,'body'=>$body,'created_at'=>$now,'updated_at'=>$now]);
        }
    }

    public function down(): void
    {
        // Mantém os modelos: podem ter sido usados/editados (o registo de mensagens referencia-os).
    }

    public static function templates(): array
    {
        return [
'Cobrança Antecipada'=><<<'TXT'
Olá *{Nome Completo}*,

Esperamos que esteja bem.

Informamos que a próxima prestação do seu contrato de crédito aproxima-se da data de cobrança. Seguem abaixo os principais detalhes para a sua referência:

📄 *Detalhes do Contrato*
• Nº do Cliente: {Nº de Cliente}  
• Nº do Contrato: {Nr do Contracto}  
• Valor Creditado: {Quantia Creditada} MT  
• Taxa de Juro: {% de Juro}%  
• Data de Início: {Data de Inicio}  

💰 *Detalhes da Próxima Prestação*
• Data Prevista de Cobrança: {Data de Cobrança}
• Valor da Prestação: {Valor a Pagar} MT  

Agradecemos que prepare o pagamento até à data indicada, de modo a manter o seu contrato em situação regular e evitar encargos adicionais.

Caso já tenha efectuado o pagamento, por favor desconsidere esta mensagem.

Para qualquer esclarecimento, estamos disponíveis para ajudar.

*CAPTA Microcrédito – William Tamele*  
_Empoderando o seu potencial financeiro_
TXT,
'Bom dia'=><<<'TXT'
Bom dia *{Nome Completo}* ☀️,

Esperamos que esteja a ter um excelente início de dia.

A equipa da *CAPTA Microcrédito* deseja-lhe um dia produtivo, cheio de saúde, sucesso e novas oportunidades.

Estamos sempre disponíveis para qualquer esclarecimento ou apoio que necessite.

Tenha um ótimo dia!

*CAPTA – William Tamele*  
_Empoderando o seu potencial financeiro_
TXT,
'Mensagem no próprio dia'=><<<'TXT'
Olá *{Nome do Cliente}*,

Esperamos que esteja bem.

Informamos que *hoje* corresponde à data prevista de cobrança da prestação do seu contrato de crédito. Seguem abaixo os detalhes para a sua referência:

📄 *Detalhes do Contrato*
• Nº do Cliente: {Nº de Cliente}  
• Nº do Contrato: {Nr do Contracto}  
• Valor Creditado: {Quantia Creditada} MT  
• Taxa de Juro: {% de Juro}%  
• Data de Início: {Data de Inicio}  

💰 *Detalhes da Prestação de Hoje*
• Data de Cobrança: {Data Final}  
• Valor da Prestação: {Valor a Pagar} MT  

Pedimos, por favor, que efectue o pagamento ao longo do dia de hoje, de modo a manter o seu contrato em situação regular.

Caso já tenha efectuado o pagamento, por favor desconsidere esta mensagem.

Para qualquer esclarecimento, estamos disponíveis para ajudar.

*CAPTA Microcrédito – William Tamele*  
_Empoderando o seu potencial financeiro_
TXT,
'Mensagem Vencida'=><<<'TXT'
Olá *{Nome Completo}*,

Esperamos que esteja bem.

Verificámos que a prestação do seu contrato de crédito encontra-se *em atraso*, tendo ultrapassado a data prevista de pagamento.

📄 *Detalhes do Contrato*
• Nº do Cliente: {Nº de Cliente}
• Nº do Contrato: {Nr do Contracto}
• Valor Creditado: {Quantia Creditada} MT
• Taxa de Juro: {% de Juro}%
• Data de Início: {Data de Inicio}

⚠️ *Detalhes da Prestação em Atraso*
• Data Prevista de Cobrança: {Data de Cobrança}
• Valor da Prestação: {Valor a Pagar} MT

Solicitamos a regularização do valor em atraso com a maior brevidade possível, de forma a evitar a aplicação de multas, juros adicionais e outras medidas de cobrança.

Caso já tenha efectuado o pagamento, pedimos que desconsidere esta mensagem.

Se estiver a enfrentar alguma dificuldade, recomendamos que entre em contacto connosco para avaliarmos uma possível solução.

Estamos disponíveis para qualquer esclarecimento.

*CAPTA Microcrédito – William Tamele*
*Empoderando o seu potencial financeiro*
TXT,
'Actualização de Dados'=><<<'TXT'
Olá *{Nome Completo}* 👋

Como já é nosso cliente, gostaríamos apenas de fazer uma breve actualização dos seus dados para mantermos o cadastro actualizado e continuarmos a atendê-lo com mais facilidade.

Pedimos, por favor:

1. Morada actual + ponto de referência
2. Local de trabalho/actividade
3. Principal fonte de rendimento
4. Contacto alternativo
5. Nome e contacto de uma pessoa próxima, caso não consigamos falar consigo
6. Melhor horário para contacto

É uma actualização rápida e não altera a relação que já tem com a *CAPTA Microcrédito*.

Obrigado pela confiança.
TXT,
        ];
    }
};
