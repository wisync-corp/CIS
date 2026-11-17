# CAPTA — Auditoria integral do frame `Cobrança` do Excel/VBA

**Fonte examinada:** `Cobrança_ATUALIZADA_PARCIAL_FINAL.frm`, 2.787 linhas, recebido do utilizador em 17/11/2026. O código é um UserForm VBA cujo desenho binário está no ficheiro complementar `Cobrança.frx` **não recebido**; inventário visual baseado nos nomes de controlos e nos procedimentos presentes na fonte, não em capturas do layout. Código analisado sem executar VBA sobre dados reais.

**Decisão de arquitectura:** `Contractos` guarda estado das prestações; `Extracto` é o único livro de lançamentos financeiros; `cis_cash_accounts` identifica bancos/caixas/carteiras; cada nova cobrança confirmada deverá criar **uma linha no Extracto com `account_id` obrigatório**, na mesma transacção da prestação, sem tabela paralela de movimentos. A Tesouraria é opcional e não deve ser requisito de qualquer cobrança base.

## Inventário sem omissões de fluxos identificáveis

| # | Elemento / controlo / rotina VBA | Comportamento identificado | Estado na versão CAPTA | Regra para activação |
|---|---|---|---|---|
| 01 | `Busca_Titulos` | Identifica cliente/contrato, datas, totais e próximas parcelas | Consulta real no módulo Cobranças | Mostrar dados de Contractos, sem valores inventados |
| 02 | `ListView1`, duplo clique | Selecciona parcela pendente/atrasada e preenche campos | Selecção de prestação real | Usar identificador `Ordem`, não só data |
| 03 | `txt_contrato`, `txt_nome`, `txt_id`, `txt_data` | Ficha de contrato/cliente | Disponível | Identificar divergências do legado |
| 04 | `txt_parcelas` | Número de prestações | Disponível | Contar linhas reais, não contratos distintos |
| 05 | `txt_pendente` | Total pendente | Disponível como leitura | Apenas estados Pendente/Pagamento Atrasado |
| 06 | `txt_liquidado` | Soma de Valor Pago de parcelas pagas | Disponível como leitura | Não inferir comprovativo ou conciliação |
| 07 | `txt_acrescimo` | Juros + multas de parcelas pagas | Disponível como leitura | Separar juros e multas na fase avançada |
| 08 | `txt_bruto` | Pendente + liquidado | Disponível como leitura | Não chamar saldo contabilístico reconciliado |
| 09 | `txt_proximo`, `txt_valor` | Próxima prestação/data | Disponível | Ordenar por vencimento das pendentes |
| 10 | `txt_dt`, `Image8_Click`, Calendar | Data real, atraso, actualização de estado e juros variáveis | Selecção/revisão informativa | Alteração retroactiva só com autorização, trilho e recálculo validado |
| 11 | `txt_dcobranca` | Data prevista | Disponível | Data efectiva vs vencimento separados |
| 12 | `txt_juros`, `txt_multa`, `txt_vpago` | Juros, multa e valor recebido | Disponível por prestação | Preservar nulos; não tratar valor pago como valor a pagar |
| 13 | `cmb_pg_Click` | Pergunta houve pagamento? | Fluxo do módulo | Divisão explícita em pagamento / sem pagamento |
| 14 | `HandlePayment` | Pagamento TOTAL, pontual e em atraso | Simulação real total, execução bloqueada | Conta receptora activa, confirmação, idempotência, atomicidade |
| 15 | Multa via `Parcelas.Show` | Cálculo facultativo, por atraso e percentagem | Opção de multa na simulação total | Comparar com cálculo original de Parcelas.frm (ficheiro não fornecido) |
| 16 | `HandlePartialPayment` | Valida parcial; juros primeiro, amortização do restante; gera/recalendariza parcelas | Modo visível e decomposição aritmética indicativa; escrita bloqueada | Homologar calendário, taxas, remanescente, concorrência, saldo e parcelas |
| 17 | `ActualizarFinanceiroCobranca "TOTAL"` | Capital histórico, amortização integral, juros, prestação | Mapeado no modelo; não reescrever automaticamente | Validar estado vs valor pago, actualização transaccional |
| 18 | `ActualizarFinanceiroCobranca "PARCIAL"` | Juros antes do capital | Decomposição indicativa | Definir destino do saldo e pagamentos repetidos |
| 19 | `ActualizarFinanceiroCobranca "RENOVAR JUROS"` | Juros de novo período, mesmo capital | Modo visível; escrita bloqueada | Nova maturidade, juros acumulados e capital sujeitos a política |
| 20 | `ActualizarFinanceiroCobranca "CAPITALIZAR"` | Capital+juro; taxa/faixa novamente pela `Table4` | Modo visível; escrita bloqueada | Consulta unívoca `cis_tabela_juros`, período 15/30 e ausência de sobreposição |
| 21 | `ObterTaxaCapitalizacao` | Procura limite/taxa para capital revisto | Referência técnica existente no backend | Não escolher taxa sem período homologado |
| 22 | `cmb_rem_Click` | Finalização do valor remanescente após parcial | Modo visível; escrita bloqueada | Liquidar atrasados primeiro, soma devida auditada |
| 23 | `renovacao` | Nova contratação após liquidação/última parcela | Modo distinto visível; escrita bloqueada | Não confundir com renovar juros; novo contrato + aprovação e conta de desembolso |
| 24 | `Estado` | Pendente/Pagamento Atrasado/Pago/Pago em Atraso | Preservado na listagem | Não mudar sem pagamento real |
| 25 | `Situação de Pagamento` | Completo/Parcial/Em Progresso | Preservado na tabela do histórico | Definir combinação válida com Estado |
| 26 | `Estado do Contrato` | Em Progresso/Finalizado | Visível, finalização bloqueada | Encerrar apenas após conferir TODAS parcelas e movimentos |
| 27 | `Data de Pagamento`, `Dias` | Dia de execução, dias de atraso | Visível/calculado em simulação total | Nunca introduzir data fictícia |
| 28 | `Capital`, `Amortização`, `Valor de Juros`, `Valor de Multa`, `Valor a Pagar`, `Valor Pago` | Composição financeira | Apresentados no detalhe e histórico | Não truncar centavos ou arredondar por aproximação |
| 29 | `UpdateTable6` | Cria entrada `Receita de Juros` no Extracto, incluindo remanescente | Extração da regra, **sem execução** | Corrigir para contabilizar montante real e natureza, e exigir `account_id` |
| 30 | `FinalizePaymentDetails` / `PostPaymentOperations` | Actualiza folha/listagem, marca completo, limpa campos | Refresh de consulta sem gravar | Não traduzir efeitos de células sem validar dados |
| 31 | `ClearInputFields` | Limpar formulário após operação | Reposição de selecção e simulação | Não apagar dados antes de confirmação backend |
| 32 | `CommandButton3_Click`, `trans_Click` | Navegação para outros forms | Navegação própria CAPTA | Não replicar dependência de workbook externo |
| 33 | `% de Multa`, `% de Juro`, `Tipo de Juro`, `Intervalo` | Parametrização do cálculo | Conservados na ficha de contrato e esquema | Homologar cálculos por modalidade, período e arredondamento |
| 34 | `Forma de Pagamento`, `Disponibilidade`, `M. Pagamento` | Modalidade/canal previsto do contrato | Canal de pagamento e referência apresentada no módulo | Confirmar canal realmente utilizado e conta do tipo compatível |
| 35 | Novo `account_id` / comprovativo | NÃO existiam no VBA original | Preparados na interface, gravação bloqueada | Prova de entrada em numerário/banco/carteira, permissão por perfil/valor/conta |
| 36 | Conciliação bancária | Não está no frame original | Reservada ao módulo opcional de Tesouraria | Não a exigir para operar Caixa padrão; confirmação manual é diferente de conciliação |

## Riscos materiais observados na fonte original

1. `HandlePartialPayment` compara o valor digitado com `txt_vpago` em algumas condições, enquanto `txt_vpago` é alimentado a partir de `Valor Pago` que pode ser zero para uma prestação por pagar. **Não copiar literalmente a comparação**, pois pode bloquear parciais válidos ou permitir uma regra diferente da pretendida; validar contra o valor devido calculado no servidor.
2. O `cmb_pg_Click` chama `HandlePayment`/`HandlePartialPayment` e depois `ActualizarFinanceiroCobranca` em operações separadas: **não existe garantia de transacção única**. Uma falha após gravar a primeira operação pode deixar a prestação inconsistente.
3. `UpdateTable6` usa sempre `Descrição='Receita de Juros'` para entradas, mesmo se o recebimento também inclui capital; isto **não prova a classificação da receita** por componente.
4. `HandlePartialPayment` insere uma nova parcela, com calendários e juros variáveis conforme opções no `Parcelas` UserForm; **não foi fornecido o código integral desse segundo form**, pelo que não se deve inferir todos os prazos, taxas, multas e excepções.
5. O `cmb_rem_Click` finaliza através de filtros e células da folha, sem a mesma granularidade da linha `Extracto` e sem `account_id` no código original. Impõe bloqueio até confirmar correspondência prestação/valor/conta.
6. `Image8_Click` faz uma actualização à `Data de Pagamento` e ao estado antes da recepção real. Na web, **data de simulação não deve marcar prestação paga nem mover saldo**.
7. `renovacao` abre uma contratação nova, distinta de juros renovados; não confundir as duas operações.
8. `Nº da Parcela` é etiqueta de negócio, enquanto `Ordem` identifica fisicamente a linha; usar `Ordem` para actualizações idempotentes e transaccionais.
9. A cobrança antiga em `ContractCollectionController::collect` escrevia `Extracto` sem `account_id`. Foi **bloqueada explicitamente com HTTP 503**, independentemente da antiga flag, até integração.
10. Não há em `Cobrança.frm` elementos suficientes para validar autenticação, prova de recebimento, idempotência, estorno ou segregação de aprovadores. São requisitos adicionais do CAPTA padrão, não recursos exclusivos da Tesouraria.

## Estado implementado no repositório

- `frontend/src/CollectionsDesk.tsx`: módulo **Cobranças** próprio, pesquisador de contratos reais, selector por `Ordem`, resumos financeiros, próxima parcela, quadro de atrasos, resumo discriminado de capital/amortização/juros/multa, tabela integral de parcelas, datas, estados e situação; os oito cenários acima estão visíveis com descrição específica e salvaguardas.
- Prévia de **pagamento total** utiliza o endpoint Laravel real de simulação. Continua sujeita a contratos CIS novos elegíveis e data efectiva de hoje (Maputo). Não simula arbitrariamente históricos ou pagamentos em datas passadas.
- Pagamento parcial: validação aritmética **informativa**, não representa recalendarização completa nem crédito confirmado.
- Renovação de juros, capitalização, remanescente, renovação de crédito, revisão de data e finalização: representações no UI com execução indisponível, enquanto aguardam a homologação do motor financeiro.
- Forma de pagamento prevista, canal declarado, conta receptora activa e referência de comprovativo estão representados no formulário. **São valores não persistidos** nesta fase; nunca mostrar como pagamentos confirmados.
- `backend/app/Http/Controllers/Api/ContractCollectionController.php` recusa expressamente qualquer cobrança real que não associe a entrada a uma conta activa; os movimentos históricos não foram alterados.

## Condições necessárias para activar cada cobrança real

1. Cadastro de contas reais concluído e saldo inicial documentado, com data de corte e validação independente.
2. Política de acesso híbrida: perfil + permissão individual + contas autorizadas + limites monetários + aprovação independente quando exigida.
3. `account_id` obrigatório e compatível com o canal; recibo/referência únicos, confirmação de entrega e idempotência com chave persistente.
4. Actualização de uma ou mais prestações em `Contractos` + lançamento de entrada em `Extracto(account_id)` em **uma única transacção InnoDB**; rollback total e locks por prestação/conta.
5. Regra explícita para pagamentos parciais sucessivos e múltiplas prestações (incluindo saldo de principal e juro; diferença entre valor a pagar e valor pago).
6. Fórmula aprovada de atraso/multa e cálculo de juros variáveis, com testes de datas e calendário (dia 15/30, semanal, uma parcela, mensal).
7. Fluxos de renovação, capitalização e remanescente homologados; nenhuma operação de *sem pagamento* deve criar recebimento.
8. Estorno e correcção auditáveis; trilho de quem registou/confirmou, quando, de qual conta e que evidência.
9. Testes de concorrência, duplicação, insuficiência de permissões e incompatibilidade de linhas históricas.
10. Alinhamento com Tesouraria opcional: conciliar extracto externo com lançamento original `Extracto.Nr`, sem criar um segundo ledger.

**Não activar `CIS_COLLECTION_WRITES_APPROVED` nem remover o bloqueio de gravação antes das condições acima.**

## API de simulação dos cenários (sem escritas)

Além da simulação de liquidação integral já existente, foi criada a rota privada e autenticada:

`POST /api/cis/contractos/{numero}/prestacoes/{ordem}/simular-cenario`.

O controlador `CollectionScenarioPreviewController` aceita `operacao` e só executa **SELECT**:

- `parcial`: recebe `valor_pago` e valida o intervalo; devolve juros cobertos, amortização indicativa e diferença aritmética, mas não cria nova prestação.
- `renovar_juros`: devolve capital preservado, amortização nula e juros duplicados conforme `ActualizarFinanceiroCobranca`; não muda contrato ou Extracto.
- `capitalizar`: exige `periodo_referencia` informado explicitamente (15 ou 30), consulta faixa única em `cis_tabela_juros` e calcula previsão; recusa lacunas/ambiguidade. O VBA dava prioridade a 30 dias e fazia fallback para primeira faixa, **o CAPTA não deve escolher silenciosamente essa política**.
- `actualizar_data`: compara a data proposta com o vencimento e indica dias de atraso/estado informativo; nunca marca pagamento.
- `remanescente`: informa atrasos que impedem análise, mas não pressupõe como calcular o remanescente.
- `finalizar_contrato`: informa o número de prestações não liquidadas; não encerra.
- `renovar_credito`: indica necessidade de novo contrato e autorização; não abre nem desembolsa.

Todas as respostas têm `consulta_apenas=true`, `movimento_registado=false` e `comprovativo_verificado=false`. **Não são transacções, lançamentos, recibos nem aprovação de cobrança.** Os testes de parsing monetário e registo de rota são executados no CI em `backend/tests/collection_scenarios_smoke.php`.



## Revisão suplementar — Parcelas.frm e A_Cobrança.frx (17/11/2026)

Foram recebidos e inspeccionados **\`Parcelas.frm\` (5.976 bytes)** e **\`A_Cobrança.frx\` (~1,7 MB, dados binários OLE)**. O \`Parcelas.frm\` é código-fonte VBA, com procedimentos \`cmb_prazo_Change\`, \`txt_juro_Change\`, \`txt_vrem_Change\`, \`UserForm_Initialize\`, \`faixa\` e \`UserForm_Activate\`. O nome declarado do seu recurso de interface é **\`Parcelas.frx\`**, ficheiro que **não foi fornecido**. O \`A_Cobrança.frx\` é um ficheiro binário distinto e não substitui automaticamente o recurso de \`Parcelas\`. O posicionamento visual exacto do UserForm de Parcelas não pode ser inferido apenas a partir dos ficheiros disponíveis.

### Regras extraídas directamente de Parcelas.frm

| Controlo VBA | Regra de origem | Tratamento CAPTA |
|---|---|---|
| \`cmb_prazo\` | Valores 1 Semana, 2 Semanas, 3 Semanas, Mês. Inicializa com **2 Semanas**. | Mostrar as quatro opções; exigir escolha explícita para não presumir taxa. |
| \`faixa\` | Percorre Table4 por \`Limite inferior\`/ \`Limite Superior\`. Para 1 ou 2 semanas procura \`Period=15\`; para 3 semanas ou Mês procura \`Period=30\`. | Consultar \`cis_tabela_juros\` com faixa e período únicos. Recusar lacunas e faixas sobrepostas. |
| \`txt_vrem\` | Representa a base usada pela calculadora. | No parcial, apresentar diferença entre \`Valor a Pagar\` e valor recebido como **hipótese indicativa** (o código original usa \`txt_vpago\` do campo \`Valor Pago\` e pode produzir valores negativos). |
| \`txt_juro\` | É editável; por omissão vem da faixa de juros. | Expor taxa da faixa e campo opcional de taxa manual em simulação **sem persistir**. |
| \`txt_vpagar\` (normal) | \`base × (1+taxa/100)\` | Calcular novo valor indicativo com duas casas. |
| \`c_multa=True\` | Reusa \`c_vrem\` como **valor de juros**; oculta prazo, mostra dias a dever; \`txt_vpagar = base × taxa/100\` (sem somar capital). | Separar modo multa do modo remanescente, explicitar prazo oculto no VBA e mostrar comparação. |
| \`WasOpenedByPrincipal\` | Abre calculadora independente, taxa inicial 10%, base 0, sem botão de retorno, formulário reduzido. | Dependência do módulo Principal, não lançar cobrança; ainda não corresponde a uma acção financeira CAPTA. |
| \`CommandButton1_Click\` | Oculta Parcelas e reabre Cobrança. | Retornar à operação de cobrança no próprio ecrã, sem abrir o formulário Windows. |

**Regra adicional observada em Cobrança.frm:** ao preparar recalendarizações de parcial, o prazo é convertido em **7 dias para 1 Semana, 15 dias para 2 Semanas, 21 dias para 3 Semanas, 30 dias para Mês**, e uma nova prestação é adicionada no VBA. Esta criação não é activada no CAPTA antes de definir política de datas, juros, prestações sucessivas e idempotência.

**Divergência financeira significativa:** a simulação antiga de cobrança integral usa \`% de Multa\` contratual sobre os juros, mas **o formulário Parcelas em modo multa consulta uma taxa da Table4 com base no próprio valor dos juros**, potencialmente produzindo outra multa. O CAPTA agora mostra **ambas as taxas/valores** em prévia, e identifica quando divergem. **Não aplicar automaticamente nenhum dos métodos em cobrança real** sem aprovação institucional da política de multa.

### Funcionalidade entregue nesta fase

- \`ParcelasQuoteController.php\` e rota privada \`POST /api/cis/contractos/{numero}/prestacoes/{ordem}/simular-parcelas\` com \`modo=reparcelamento|multa\`, \`prazo\` escolhido e \`valor_recebido\` obrigatório para parcial; \`taxa_manual\` é opcional para prévia.
- O controlador usa apenas consultas SELECT, não grava saldo, contrato, recibo, plano ou lançamento em Extracto.
- A interface \`CollectionsDesk.tsx\` apresenta uma **Calculadora de Parcelas** integrada nos caminhos **Pagamento parcial** e **Pagamento integral com multa**, incluindo taxas de faixa/manuais, períodos, base, juros/multa e alertas de divergência.
- A prévia usa DECIMAL em centésimos e arredondamento **HALF_UP a 2 casas decimais**, identificado no resultado; \`Format\` do Excel/VBA pode ter diferenças em empates com meio cêntimo. A regra final de arredondamento necessita de homologação antes de lançamentos.
- Os testes \`backend/tests/parcelas_quote_smoke.php\` validam intervalos de prazo, conversão das taxas, montantes, campos inválidos e autenticação exigida pela rota, sem gravar dados.

**Pendente para equivalência operacional integral:** aprovação da fórmula legal/contratual da multa, decisão sobre o significado correcto de \`txt_vpago\` no parcial, data base para vencimentos futuros, recalendarização com várias prestações, apuramento do remanescente, actualização transaccional de Contractos e Extracto.account_id, prova de recebimento, idempotência e matriz de permissões. O \`Parcelas.frx\` original continua necessário para replicação visual **pixel a pixel**, caso isso seja exigido.


## Navegação consolidada — Cobranças dentro do contrato (17/11/2026)

**Decisão UX:** a cobrança pertence à ficha do contrato, não a uma página paralela. O menu independente `Cobranças` foi removido para evitar duas pesquisas, dois ecrãs e duas implementações de pagamentos.

Fluxo único:
`Contratos e prestações → Abrir ficha do contrato → Pagamentos`.
A partir da tabela `Plano de prestações`, `Ver pagamentos` envia directamente a prestação seleccionada para a aba `Pagamentos`. A aba utiliza a implementação única `CollectionsDesk` em modo `embedded`, recebendo `numero`, os dados já lidos da ficha e `initialOrder`. **Não faz uma segunda pesquisa de contratos nem uma segunda consulta da ficha**, e mantém todos os oito tratamentos de pagamento, a calculadora Parcelas, histórico e simulações no mesmo local.

A Caixa Geral continua como módulo próprio, transversal a contratos, para contas, saldos e movimentos da instituição. Simulações financeiras são somente leitura; executar pagamentos permanece bloqueado até associação obrigatória da conta e homologação.


## Reorganização — Simulador independente e cards de pagamentos (17/11/2026)

Foi aprovada a separação entre **simulação genérica** e **operação específica do contrato**:

- **Simulador** é agora uma área autónoma no menu principal. Não requer cliente, contrato ou prestação e utiliza valores introduzidos manualmente pelo utilizador. O backend `POST /api/cis/simulador/calcular` consulta somente `cis_tabela_juros` e apresenta taxa da faixa, eventual taxa manual, prazo, base, acréscimo e total indicativo. Não tem permissões de escrita e exige utilizador administrador autenticado em pré-visualização privada.
- **Contratos e prestações → Ficha → Pagamentos** mantém apenas tratamentos da prestação real: pagamento total, parcial, atraso, renovação, capitalização, remanescente e encerramento, com prévias específicas do contrato. O bloco **Calculadora de Parcelas** genérico, juntamente com os seus inputs e o botão duplicado, foi **retirado desta ficha**. A rota legada `simular-parcelas` continua protegida, sem gravações; não é uma operação de cobrança.
- **Cards da ficha Pagamentos** passaram a ter hierarquia: dois cards principais (pendente e recebido), três indicadores operacionais (prestações, atrasos e próximo vencimento) e dois indicadores complementares (juros/multas e total previsto/pago). Layout de 6 colunas com **container queries** para adaptar ao espaço útil real à direita da navegação do contrato, não apenas à largura total do ecrã.
- Opções de tratamento de pagamento organizadas em cards maiores e responsivos, sem sobrepor campo de cálculo genérico com operação contratual.

**Continua desactivado** o registo definitivo de cobranças e qualquer alteração financeira do Extracto até que existam a conta receptora activa, autorização, prova documental, idempotência e escrita atómica em Contractos/Extracto. 


## Evolução UX — Tratamento contextual por prestação (17/11/2026)

O separador **Pagamentos** da ficha do contrato abre inicialmente em **Resumo** e mostra os indicadores e prestações existentes. O botão **Efectuar pagamento** aparece somente para prestações cujo `Estado` seja `Pendente` ou `Pagamento Atrasado`, sem `Situação de Pagamento` finalizada e sem `Estado do Contrato` fechado. Para prestações já liquidadas, a ficha oferece **Ver histórico**, não permite iniciar cobrança duplicada.

Ao seleccionar **Efectuar pagamento**, aparece a aba contextual **Tratamento do pagamento**, com identificação da prestação, estado, composição e apenas os cenários aplicáveis. A aba não é apresentada antes de escolher uma prestação elegível. Os outros cenários de encerramento ou abertura de novo crédito não são confundidos com um pagamento pendente.

**Prazo 15 → 30 dias:** se o campo contratual `Prazo/Duração` indicar inequivocamente `15 dias` ou `2 Semanas`, o tratamento apresenta **Passar de 15 para 30 dias**. A prévia no servidor revalida que a prestação ainda está pendente, que as linhas do contrato têm prazo consistente e que a tabela de juros contém uma taxa única para a faixa de capital e `periodo_referencia=30`. A resposta informa a taxa de referência, mas **não altera** datas, percentagens, capital, prestações ou Extracto. Para qualquer outro prazo, a opção não aparece.

As verificações visuais são conveniência UX; as regras essenciais também são verificadas no servidor. Esta conversão de prazo é uma **proposta de tratamento**, não prova de aceitação pelo mutuário nem autorização para prorrogação financeira.
