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

## Estratégias de cálculo das prestações — 16-11-2026
A nova lista visual foi fornecida em captura pelo operador; o código **Contractos.frm** é a fonte para determinar as opções já homologadas:
- **Amortização fixa com juro fixo:** corresponde à ramificação FIXO do VBA, que distribui a amortização e o juro total pelas prestações, ajustando a última (linhas 666–682, 878–925).
- **Tabela Price:** corresponde à fórmula de prestação constante com capitalização do ramo COMPOSTO do VBA (linhas 685–710, 837–870).
- **Juro variável na cobrança:** opção adicional explicativa para o tipo VARIÁVEL, pois o VBA cria uma prestação sem juros e delega o cálculo à Cobrança (linhas 523–539, 810–834).
- **Amortização fixa com juro decrescente, Prestação fixa pelo total, Prestação fixa simples:** constam da imagem enviada, mas **não têm implementações autónomas demonstradas no Contractos.frm**. Aparecem no menu com indicação de não homologadas e não autorizam a escrita financeira.
- O selector de estratégia depende do tipo de juro e selecciona automaticamente a estratégia correspondente comprovada, sem permitir mudar para cálculo não homologado.
- Uma migração aditiva fornece a coluna nullable **Estratégia de Cálculo** (VARCHAR 90) na tabela Contractos. As prestações históricas ficam com NULL; todas as prestações de novos contratos guardam a estratégia escolhida.
- A API verifica tipo/estratégia novamente antes da gravação e recusa qualquer estratégia não implementada no motor real. A ficha do contrato apresenta a estratégia quando a coluna já existe.
- Para aplicar no VPS com backup recente: `bash deploy/add-contract-calculation-strategy-column.sh`. O script exige confirmação explícita; nenhuma alteração à base de produção foi efectuada pelo desenvolvimento no GitHub.

## Nova política financeira aprovada — 16-11-2026: meticais inteiros e estratégias adicionais
O utilizador solicitou expressamente a implementação dos três métodos restantes com base no conhecimento matemático geral e que os **valores monetários sejam inteiros, sempre arredondados para cima**. Trata-se de uma ALTERAÇÃO às regras antigas do VBA, que usava `Round(...,2)`. Não atribuir estes cálculos novos ao código histórico do Contractos.frm.

**Compatibilidade e fórmulas adoptadas no CIS:**
- **Fixo — Amortização fixa com juro fixo:** juro TOTAL `ceil(P*r)` uma vez; amortização de capital `ceil(P/n)` por prestação (última = saldo exacto), juros distribuídos em inteiros, última = juro total ainda não distribuído.
- **Fixo — Prestação fixa pelo total:** total `T=P+ceil(P*r)`, prestação `ceil(T/n)` (última = saldo exacto do total), amortização `ceil(P/n)` (última = capital restante); juro por prestação = prestação menos amortização. O total do contrato não varia com o número de prestações.
- **Fixo — Prestação fixa simples:** juros simples de `ceil(P*r)` EM CADA prestação, usando capital original P; amortização de capital igual a `ceil(P/n)`, última recebe saldo exacto. Por aplicar juros por prestação, o juro total é `n*ceil(P*r)`. Esta é uma opção distinta da prestação fixa pelo total, e o utilizador deve conhecer essa distinção.
- **Composto — Amortização fixa com juro decrescente:** amortização `ceil(P/n)`, última = saldo exacto; juro de cada prestação `ceil(saldo_devedor*r)`; prestação = amortização + juro.
- **Composto — Tabela Price:** prestação base `ceil(P*r*(1+r)^n/((1+r)^n-1))`, ou `ceil(P/n)` se r=0; juro de cada período `ceil(saldo_devedor*r)`; amortização é diferença prestação-juro; última prestação quita saldo e respectivos juros.
- **Variável — Juro variável na cobrança:** cria uma prestação de capital inteiro, sem antecipar os juros; estes só poderão ser apurados quando o módulo Cobrança tiver as regras respectivas.

**Regras comuns:** montante de desembolso deve ser inteiro e positivo; percentagens são validadas no esquema original. Não arredondar ou aumentar silenciosamente o capital desembolsado; rejeitar montante fraccionário. Sempre que houver divisão, arredondar para cima os valores positivos. A parcela final recebe o **remanescente inteiro exacto**, sem arredondar novamente esse saldo; isto evita criar ou cobrar capital não desembolsado. Todas as prestações satisfazem `a_pagar=amortizacao+juros`; somatório das amortizações = capital realmente desembolsado; somatório do contrato = capital+juros. Registos antigos não são recalculados.

**Nota temporal:** a taxa é aplicada por período de prestação mensal nas estratégias COMPOSTO e SIMPLE descritas acima; as regras financeiras de frequência Semanal e prazo em Semanas permanecem bloqueadas até homologação da correspondência entre períodos de juros e periodicidade real. Esta alteração não habilita modalidades semanais nem altera o Extracto sem nova abertura confirmada.

**Implantação:** os novos algoritmos afectam APENAS contratos novos depois da publicação e dos testes; se houver contratos reais em curso, confirmar previamente como serão tratados os contratos antigos. Executar os scripts de teste do backend antes de activar novos desembolsos.
