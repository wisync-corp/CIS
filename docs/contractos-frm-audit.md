# Auditoria do Contractos.frm original

Origem: ficheiro VBA Contractos.frm fornecido pelo utilizador em 14-11-2026. Código analisado: cmb_adicionar_Click, faixa, YourButton_Click, cmb_cobranca_Click, cmb_vizualizar_Click, cmb_novo_Click.

## Abertura de contratos
- Campos obrigatórios: montante, cidade, data de início, taxa, multa, disponibilidade, forma, modalidade, prazo e tipo de juro.
- Prazos: 1, 2 ou 3 meses. Taxa fixada pela Table4 apenas para juro Fixo; rotina faixa usa Period=30 quando o prazo está escolhido. A linha de 15 dias não é seleccionada automaticamente pelo formulário original.
- Fixo: juros totais são Round(montante * taxa / 100, 2), distribuídos pelas parcelas; a última absorve arredondamento. Modalidade Uma Parcela gera 1, mensal gera o número de meses.
- Variável: uma parcela, juros zero na abertura; juros apurados na Cobrança, na data efectiva do pagamento.
- Composto: parcelas mensais. Prestação base P*r*(1+r)^k/((1+r)^k-1) com Round(...,2); juros sobre saldo e última amortização liquida capital restante.
- Datas de vencimento: DateAdd(m, i, dataInicio), com data final DateAdd(m, prazoMeses, dataInicio).
- Linhas novas: Estado=Pendente, Estado do Contrato=Em Progresso, Valor Pago=0, Valor de Multa=0, Dias=0 e Data de Pagamento nula.
- A rotina recusa montante superior ao saldo disponível; origem do saldo deve ser auditada antes da escrita.
- A numeração por MAX do VBA não é segura para concorrência e exige substituição por geração transaccional.

## Aumento de crédito
- YourButton_Click só admite juro Fixo. Actualiza valores da prestação existente e insere Saída / Aumento de Crédito no Extracto.
- Atenção: o VBA também atribui Valor Pago = Valor a Pagar durante o aumento. Não replicar este efeito antes de reconciliar com o histórico financeiro.
- É necessária uma transacção financeira única, sem duplicação e com permissões auditáveis.

## Abrir Cobrança
- cmb_cobranca_Click abre outro formulário denominado Cobrança após passar o identificador do contrato.
- Fórmulas e regras da Cobrança não estão neste Contractos.frm. Para reproduzi-las é necessário Cobrança.frm.

## Visualizar documento original
- cmb_vizualizar_Click abre um PDF local no Windows, sob MyDocuments/Micro Credito e subpastas provenientes da folha Controle.
- Este procedimento não gera o documento. O PDF/modelo e a estratégia de armazenamento privado ainda precisam ser fornecidos e validados.

## Estado no CIS
- Consulta e pré-validação Laravel, tabela de preços e pré-visualização financeira local baseadas nas ramificações VBA; sem escrita financeira.
- Prestações e juros previstos são indicadores de simulação, não montantes homologados para cobrança.
- Antes de criar contratos no MySQL: paridade monetária com o Excel, saldo do Extracto, numeração segura, permissões de escrita, auditoria e atomicidade.

## Ajuste de produto autorizado pelo utilizador — períodos e prazos (15-11-2026)
- O utilizador definiu uma nova relação entre o período de preços e o prazo do contrato; isto é uma extensão do formulário VBA mensal, não uma regra inferida do Contractos.frm histórico.
- Período 15 dias: **1 Semana** ou **2 Semanas**.
- Período 30 dias: **1 Semana**, **2 Semanas**, **3 Semanas**, **1 Mês**, **2 Meses**, **3 Meses**.
- O campo período passa a ser seleccionável (15/30), e qualquer mudança limpa o prazo anterior para impedir combinações inconsistentes.
- A cotação de juro fixo usa o período seleccionado, e o servidor valida a combinação antes de qualquer operação.
- **Segurança:** os cálculos e o esquema de prestações semanais não constam das fórmulas mensais do Contractos.frm analisado; a interface permite seleccionar e consultar taxas para semanas, mas a abertura real semanal continua bloqueada na API até validação das modalidades, número e datas das parcelas e cálculo dos juros.
- Para os prazos mensais com período 30 dias, a abertura real conserva as validações financeiras já existentes; não alterar dados históricos.

## Ajuste da data e modalidade — 15-11-2026
- O formulário passa a iniciar com a data corrente de Moçambique (Africa/Maputo). O operador pode editar a data; a API de desembolso real continuará a exigir que corresponda ao dia efectivo do desembolso.
- O período (15 ou 30 dias) determina os prazos disponíveis. A escolha do prazo determina as modalidades disponíveis:
  - 1 Semana: Uma Parcela.
  - 2 ou 3 Semanas: Semanal ou Uma Parcela.
  - 1 Mês: Semanal ou Uma Parcela (não Mensal).
  - 2 ou 3 Meses: Semanal, Mensal ou Uma Parcela.
- Alterar período limpa prazo e modalidade; alterar prazo limpa modalidade.
- Para juro Variável/Composto, as limitações adicionais da rotina legada continuam verificadas no servidor (Variável apenas Uma Parcela; Composto apenas Mensal). Combinações novas sem fórmula homologada não autorizam desembolso.
- Modalidade Semanal e prazos semanais **podem ser preparados no formulário**, mas a gravação real permanece bloqueada pela API até se aprovar número de prestações, datas e cálculo de juros, não inferidos do módulo VBA original.
