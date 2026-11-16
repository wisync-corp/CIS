# CIS / CAPTA — desenho funcional e técnico do módulo Caixa (proposta v0.1)

Estado: **PROPOSTA PARA DECISÃO — NÃO IMPLEMENTAR ESCRITAS FINANCEIRAS**.

## Referência e âmbito

A análise do GEMIC fornecida pelo utilizador descreve: contas de caixa com saldo, entrada, saída e transferência; uma conta a debitar durante a concessão; livro diário e estornos; e separação entre concessão e pagamentos. Não copiar a implementação do GEMIC nem considerar que os seus movimentos sejam a fonte da verdade do CIS.

## Diagnóstico do CIS (código actual)

- `ContractOpeningController::store` valida o saldo **global** de `Extracto` somando `Entradas` e subtraindo `Saídas`.
- Escreve o contrato e **uma saída** em `Extracto` na mesma transacção MySQL, mas o lançamento não identifica qualquer conta de caixa.
- A tabela legada `Extracto` possui: `Nr`, `Contracto`, `Parcela`, `Cliente`, `Tipo de Movimento`, `Descrição`, `Data da Operação`, `Valor`, `usuario`; **não possui ID de conta**.
- O endpoint de saldo actual e o formulário usam o saldo do conjunto de movimentos, não um saldo por conta.
- Não há módulo/tabela `cis_cash_accounts` ou `cis_cash_movements` no código do repositório.

## Regra de segurança pretendida

> **É proibido desembolsar se não houver uma conta de caixa activa, explicitamente escolhida, autorizada e com saldo disponível suficiente.** Não criar conta fictícia, não usar a primeira conta automaticamente e não aceitar apenas o saldo total agregado como prova de saldo.

A verificação do formulário é informativa: **o backend deve repetir todas as verificações dentro da transacção que realiza o desembolso**, com bloqueio de concorrência. Um crédito só é considerado desembolsado quando a saída da conta é confirmada e ligada inequivocamente ao contrato.

## Proposta de dados (ainda não aplicar migração)

- `cis_cash_accounts`: `id` (UUID ou BIGINT — decidir); `code` único; `name`; `type` = numerário / banco / carteira móvel; `currency`; `active`; `opening_balance` (decimal exacto); `created_by`; `created_at`; `updated_at`.
- `cis_cash_movements`: `id`; `account_id`; `type` = entrada / saída / transferência / ajuste / estorno; `value` (DECIMAL exacto e positivo); `effective_at`; `reference` único para idempotência; `contract_number` opcional; `legacy_extracto_nr` para ligação verificável; `counterparty_account_id` para transferência; `status`; `created_by`; carimbos de auditoria.
- Saldo calculado **apenas** sobre movimentos confirmados e saldo inicial reconciliado, sem confiar em campos enviados pelo frontend. Precisar regra de reconciliação dos movimentos legados para não somar o mesmo desembolso duas vezes.
- Controlo adicional `cis_contract_disbursements` ou chave idempotente única `contract_number` + natureza do movimento, para impedir duplicados, com FK para movimento e conta.
- Requisitos adicionais: índices, chave estrangeira, moeda, estados de abertura/fecho, proibição de apagar lançamentos financeiros, histórico auditável, estorno autorizado em vez de eliminar.

## Fluxo-alvo

1. Administrador cria e reconcilia contas reais (saldo inicial por conta, com data de corte e comprovativo).
2. Operador escolhe cliente e parâmetros de crédito.
3. Operador escolhe **a conta de saída**. A lista mostra apenas contas activas, autorizadas e da moeda do crédito, com **saldo por conta**.
4. Simulação exibe valor, conta, saldo antes/depois, calendário, taxa, confirmação de disponibilidade.
5. Ao confirmar que os fundos foram efectivamente entregues, servidor inicia operação atómica, bloqueia a conta afectada e verifica novamente permissões/saldo/duplicados.
6. Servidor cria contrato, prestações, saída na conta e saída no `Extracto` com referência unívoca. Se **qualquer** etapa falhar, faz rollback de tudo.
7. Regista comprovativo e quem confirmou a entrega dos fundos. Sucesso abre automaticamente a ficha, com conta e referência do movimento.
8. Para créditos por transferências bancárias/móveis, distinguir **ordem emitida** de **desembolso confirmado**. Não marcar fundos como entregues só porque o operador pediu transferência.

## Regra para numerário versus transferência — PENDENTE DE DECISÃO

- **Opção A (simples):** o desembolso é registado apenas depois de entrega efectiva de numerário ou confirmação externa da transferência; não há estado pendente.
- **Opção B (recomendada para rigor bancário):** fluxo autorização -> transferência pendente -> confirmação por banco/carteira -> desembolso registado. Requer estados e reconciliação de operações pendentes.

## Compatibilidade com a base legada

**Não** acrescentar `account_id` a `Extracto` nem reatribuir movimentos antigos silenciosamente. Definir ligação por tabela de movimentos, referência única e processo explícito de reconciliação antes de importar históricos. Expor saldo global legado como **não discriminado**, nunca como saldo de uma conta específica.

## Testes de aceitação antes de activar escrita

1. Sem conta seleccionada -> HTTP 422; nenhum INSERT.
2. Conta inexistente/inactiva/sem autorização/moeda errada -> falha segura.
3. Conta com saldo insuficiente, embora saldo global seja suficiente -> falha segura.
4. Duas operações concorrentes disputam saldo -> no máximo uma aprovada.
5. Repetir o mesmo pedido -> sem duas saídas ou dois contratos.
6. Falha de `Extracto` ou `Contractos` -> rollback de todos os movimentos da conta e das prestações.
7. Confirmar transferência pendente não debita como concluída antes da confirmação, se adoptada opção B.
8. Listagem, saldo antes/depois, recibo, reversão e trilho de auditoria conferidos com MySQL.
9. Nenhuma migração muda os 679 registos de contratos e 1294 movimentos existentes sem decisão expressa.
10. Nenhum teste cria um desembolso real ou move fundos.

## Decisão funcional confirmada — Caixa Geral (Opção B)

**Confirmado pelo cliente em 16/11/2026:** a **Caixa é um módulo padrão** do CAPTA, com âmbito **geral** e não limitado à concessão e cobrança de créditos.

### Alcance mínimo da Caixa padrão

- Múltiplas contas reais, com saldo independente: numerário, contas bancárias e carteiras móveis.
- Entradas: pagamentos de prestações e outras receitas da instituição, com classificação e referência.
- Saídas: desembolsos de crédito, despesas operacionais e outras saídas, com conta de origem explícita.
- Transferências entre contas: saída de uma conta e entrada noutra com vínculo comum, sem afectar indevidamente o resultado financeiro consolidado.
- Livro diário, saldos por conta, histórico, comprovantes e estornos controlados.
- Cada movimento possui natureza, conta, montante, data efectiva, operador, referência e relação opcional a crédito/contrato.
- A caixa geral tem uma única fonte de movimentos. **Não** criar segunda caixa para quem activar Tesouraria.

### Tesouraria como módulo opcional

A Tesouraria é uma **camada adicional**, contratada ou activada conforme a escolha de cada instituição. Reutiliza as contas, saldos e movimentos da Caixa padrão e acrescenta planeamento, previsões, gestão de compromissos, reconciliação avançada, classificações pré-contabilísticas e relatórios de gestão. Não introduz uma segunda base de saldos.

**A contratação da Tesouraria não determina automaticamente aprovação em duas fases.** Fluxos de aprovação são definidos por permissões de utilizador, limites e políticas institucionais, independentemente do pacote de módulos.

### Requisitos de lançamento de despesas e receitas gerais

- Natureza distinta do movimento: crédito/desembolso, cobrança, receita geral, despesa operacional, transferência, ajuste e estorno.
- Categoria e descrição obrigatórias para movimentos gerais; ligação ao contrato obrigatória para operações de crédito.
- Guardar utilizador, data de registo, data efectiva e comprovativo quando exigido pela política.
- Não permitir alterar nem eliminar movimento confirmado; corrigir por estorno com autorização e referência.
- Contabilização avançada **não** é obrigatória para utilizar Caixa padrão.
- Conta de origem/destino, moeda, saldo e permissões são sempre validados no servidor. Para desembolsos, saldo global agregado nunca substitui saldo da conta escolhida.

### Decisões técnicas ainda em aberto

A escolha **B — Caixa Geral** resolve o âmbito do produto, **não** autoriza automaticamente:
1. O procedimento de saldos iniciais e a data de corte de reconciliação.
2. O tratamento de transferências bancárias/móveis ainda pendentes de confirmação.
3. Os perfis, limites e regras de autorização de desembolsos.
4. A alteração imediata do endpoint de desembolso existente (bloqueio operacional).

Manter a implementação financeira desactivada até definir e testar estas regras; não atribuir a contas os registos legados sem reconciliação.

## Decisão confirmada — Abertura de contas por saldo reconciliado (Opção B)

**Confirmado pelo cliente:** cada conta da Caixa Geral deve iniciar a operação com um **saldo de abertura reconciliado**, comprovado por extracto bancário, comprovativo de carteira móvel ou contagem física de numerário, numa data de corte definida. A confirmação não estabelece, por si só, os valores, a data de corte nem as regras de autorização.

### Fluxo de abertura e homologação de conta

1. **Cadastrar conta real** (nome, identificador, instituição, tipo, moeda, estado `Em preparação`). Não criar caixa fictícia nem saldo automático a partir do total do Extracto.
2. **Indicar data/hora de corte** da reconciliação (fuso `Africa/Maputo`) e saldo documentado nessa data, em montante exacto.
3. **Associar evidência** (extracto, comprovativo ou folha de contagem) e identificar utilizador responsável.
4. **Confrontar movimentos anteriores à data de corte** com os comprovativos, identificando diferenças e os registos legados sem conta atribuída. Não distribuir movimentos históricos automaticamente.
5. **Submeter à validação** de um utilizador autorizado; resolução de divergências deve ser documentada. A regra de aprovação independente depende da política institucional ainda a definir.
6. **Activar a conta** apenas após validação e reconciliação; manter registos e comprovativos imutáveis e auditáveis. Só contas activas e autorizadas ficam disponíveis para novos desembolsos.
7. **Registar movimentos posteriores à data de corte** no livro de caixa por conta, com chave idempotente e vínculo ao crédito ou categoria de receita/despesa. Não contabilizar duas vezes movimentos importados.

### Estados sugeridos

`Em preparação` → `Em reconciliação` → `Pendente de validação` → `Activa` / `Rejeitada` / `Suspensa`.

### Garantias de integridade

- O saldo inicial é um **ponto de partida contabilizado uma única vez por conta**, nunca uma entrada repetida em todas as consultas.
- O saldo disponível resulta do saldo reconciliado e dos movimentos confirmados posteriores à data de corte, descontadas reservas válidas se forem implementadas.
- Divergência não justificada, ausência de documento, conta inactiva ou moeda incompatível **bloqueiam a utilização dessa conta para desembolso**.
- Correções de saldo exigem movimento de ajuste ou estorno auditável, nunca alteração silenciosa do saldo inicial homologado.
- Testar saldos com `DECIMAL` e fusos horários, inclusive operação na data de corte; determinar a regra de inclusão dos movimentos exactamente na hora do corte.
- Preservar os históricos de `Contractos` e `Extracto`; qualquer migração requer plano de reconciliação e backup verificado.
- **Nenhuma migração financeira nem bloqueio operacional é autorizado apenas pela escolha desta opção.**

### Decisões por definir antes de implementar

1. **Data de corte e contas iniciais**: por conta ou data comum? Montantes, moedas e comprovativos reais.
2. **Política de desembolsos**: permissões e limites por perfil/indivíduo; aprovação directa ou independente; momento de confirmação das transferências bancárias/móveis.
3. **Activação operacional**: como impedir novas saídas sem conta reconciliada e como migrar os créditos em preparação, sem perda de operações.
4. **Gestão de diferenças**: responsáveis, tolerâncias e procedimento formal de ajustes.

## Decisões necessárias

1. **Modelo de contas:** todas as contas reais (bancos, numerário e carteiras móveis) na mesma tabela? Moeda inicial MZN?
2. **Momento de saída:** opção A ou B acima; preferências para desembolso via M-Pesa, banco e numerário?
3. **Migração de abertura:** os saldos iniciais serão introduzidos e reconciliados por conta a partir de uma data de corte, mantendo o Extracto histórico inalterado?
4. **Bloqueio imediato:** pretende suspender novos desembolsos enquanto não existir uma conta reconciliada (alteração operacional do sistema actual)?

Até estas decisões serem tomadas, manter este documento como proposta; não activar migrações nem modificar os movimentos históricos.
