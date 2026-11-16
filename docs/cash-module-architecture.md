# CIS / CAPTA — histórico de decisões da Caixa (substituído pela arquitectura híbrida)

**DOCUMENTO HISTÓRICO:** as referências seguintes a `cis_cash_movements` correspondem à proposta inicial **revogada**. O modelo final confirmado é **C — `Extracto` + `cis_cash_accounts` + `cis_cash_links`**, com apenas duas tabelas novas e sem dupla contagem. **Consultar `docs/cash-hybrid-treasury-architecture.md` como referência técnica actual**, incluindo a extensão futura de Tesouraria opcional.

**Nova decisão:** a tabela `cis_cash_links` também foi descartada. A arquitectura vigente cria **somente `cis_cash_accounts`** e acrescenta `Extracto.account_id` (nullable). A referência normativa actual é `docs/cash-hybrid-treasury-architecture.md`.




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
3. A parametrização concreta dos limites por perfil, utilizador e conta, e a política de aprovação (modelo híbrido já aprovado).
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
2. **Parâmetros de desembolso**: limites, responsáveis, regras de excepção e momento de confirmação das transferências bancárias/móveis (modelo híbrido confirmado).
3. **Activação operacional**: como impedir novas saídas sem conta reconciliada e como migrar os créditos em preparação, sem perda de operações.
4. **Gestão de diferenças**: responsáveis, tolerâncias e procedimento formal de ajustes.

## Decisão confirmada — Modelo híbrido de permissões de desembolso

**Confirmado pelo cliente:** a Caixa Geral é padrão; a Tesouraria é opcional; **o tipo de desembolso não depende do módulo contratado**, mas de um modelo híbrido de perfis, permissões individuais, limites e políticas da instituição.

### Modelo de autorização

- **Perfil** fornece uma matriz inicial de permissões; o nome do perfil não é, por si só, autorização suficiente.
- **Permissões individuais** permitem conceder/revogar direitos específicos de `credit.create`, `credit.approve`, `cash.disbursement.request`, `cash.disbursement.approve`, `cash.disbursement.execute`, `cash.accounts.manage`, `cash.accounts.reconcile` e `cash.movements.read`.
- **Âmbito por conta** limita que contas o utilizador pode movimentar, de forma independente do acesso de consulta.
- **Limites monetários** definem o valor máximo que um utilizador pode executar directamente, aprovar ou apenas solicitar; todos os montantes são avaliados em unidades monetárias exactas.
- **Regra directa:** execução sem segunda aprovação **apenas** quando o utilizador está autorizado na conta, abaixo do limite configurado e a política da instituição permite a via directa.
- **Regra com aprovação:** quando a política exige dupla autorização ou o limite directo é excedido, o crédito segue fluxo de solicitação e aprovação. A pessoa que solicita não pode aprovar a própria operação quando for exigida independência.
- **Regra condicionada:** depende do valor, conta, método de pagamento, risco, unidade e eventuais excepções autorizadas.
- **Confirmação de entrega efectiva dos fundos** é uma dimensão distinta da aprovação. Uma transferência solicitada não deve ser marcada como desembolsada antes de comprovada a execução.
- **Deny by default:** sem política homologada, permissão explícita, conta reconciliada, saldo ou identidade confirmada, a API recusa a movimentação. Nunca herdar autorização do módulo Tesouraria activado.

### Estados funcionais propostos

`Rascunho → Pendente de aprovação → Aprovado → Pendente de execução → A confirmar entrega → Desembolsado`, com vias adicionais `Rejeitado`, `Cancelado` e `Falhou`. Para operações directas, os estados de pedido/aprovação podem ser omitidos quando a política o permite, mantendo trilho de decisão.

### Dados propostos (sem migração autorizada)

- `cis_financial_role_permissions`: perfil, capacidade, habilitação.
- `cis_financial_user_permissions`: utilizador, capacidade, decisão explícita (conceder/revogar), validade, responsável.
- `cis_financial_account_access`: utilizador ou perfil, conta, operações permitidas.
- `cis_disbursement_policy`: instituição, modo directo/condicionado/aprovação, limites, segregação, estado, versão, vigência.
- `cis_disbursement_requests`: identificador idempotente, contrato, conta, montante, requerente, aprovador, executor, estado, política aplicada, carimbos e prova de entrega.
- `cis_disbursement_audit`: histórico imutável de decisões, justificação, interveniente e data.

As alterações às políticas e permissões devem ser auditadas e só afectar operações futuras; operações pendentes precisam de regra explícita para políticas alteradas.

### Critérios de aceitação antes de utilizar valores reais

1. Dois utilizadores com o mesmo perfil podem ter limites e permissões diferentes.
2. Um utilizador sem permissão de execução pode preparar a operação, mas não movimentar fundos.
3. O operador com execução directa só pode movimentar as contas autorizadas e respeitar o limite.
4. Montante acima do limite segue aprovação; não é convertido automaticamente em desembolso.
5. Uma aprovação independente nunca pode ser feita pelo próprio requerente.
6. Perfil administrador não ignora bloqueios de conta, saldo, confirmação ou registo de auditoria.
7. Activar ou desactivar Tesouraria não modifica as permissões de desembolso.
8. Conta inactiva ou não reconciliada impede a execução, mesmo com aprovação.
9. Requisição repetida não cria desembolso duplicado.
10. Nenhuma falha de confirmação externa de transferência pode transformar uma operação pendente em concluída.

### Próxima etapa de implementação

Implementar primeiro **cadastro e reconciliação de contas com consulta apenas**, a seguir permissões e fluxos de aprovação, e só depois a integração atómica de desembolsos. Não activar escritas ou alterar o Extracto legado por esta confirmação.

## Decisão confirmada — Caixa operacional e Tesouraria opcional (sem reconciliação no módulo padrão)

**Confirmado pelo cliente:** a Caixa Geral deve garantir **registo e confirmação operacional manual** de movimentos, mas não incluir importação e reconciliação de extractos bancários. Essas capacidades pertencem ao módulo **Tesouraria opcional**.

- Caixa padrão: contas, saldos, entradas/saídas de receitas e despesas, transferências concluídas, desembolsos/recebimentos após integração dedicada, comprovativo ou referência, histórico e auditoria.
- Para movimentação bancária no módulo padrão, guardar referência e evidência de transferência **confirmada manualmente**. Não alegar reconciliação bancária independente.
- Tesouraria opcional: importação de extractos, conciliação de transacções, detecção de divergências, previsões, compromissos e mapas financeiros avançados.
- A Tesouraria usa **a mesma base financeira da Caixa**, sem duplicação de movimentos ou saldos.
- A escolha comercial por módulos é **independente** das regras de autorização por perfil, utilizador e limite.
- Operações de transferência entre contas só são marcadas como concluídas após confirmação documental de débito e crédito. As transferências bancárias pendentes exigem fluxo separado a desenvolver.
- É proibido lançar movimentos do Extracto antigo em Caixa sem reconciliação controlada, para evitar dupla contabilização.

### Implementação inicial no repositório

- Ficheiros: `backend/app/Http/Controllers/Api/CashController.php`, migração `2026_11_16_000030_create_cis_cash_tables.php`, `frontend/src/CashDesk.tsx`.
- Interfaces: Contas e saldos; Livro de movimentos; Novo registo de conta; Saldo inicial documentado; Validação manual; Entrada/saída geral; Transferência confirmada entre duas contas.
- API restrita inicialmente a administradores; a matriz híbrida ainda precisa de implementação.
- As escritas estão desligadas por defeito (`CIS_CASH_WRITES_APPROVED=false`). Sem contas fictícias e sem importação automática do Extracto antigo.
- `ContractOpeningController::store` **bloqueado até integração efectiva da saída por conta**, para impedir desembolso sem conta.
- Ver `docs/cash-general-rollout.md` para migração isolada e homologação.

## Decisões necessárias

1. **Modelo de contas:** todas as contas reais (bancos, numerário e carteiras móveis) na mesma tabela? Moeda inicial MZN?
2. **Momento de saída:** opção A ou B acima; preferências para desembolso via M-Pesa, banco e numerário?
3. **Migração de abertura:** os saldos iniciais serão introduzidos e reconciliados por conta a partir de uma data de corte, mantendo o Extracto histórico inalterado?
4. **Bloqueio imediato:** pretende suspender novos desembolsos enquanto não existir uma conta reconciliada (alteração operacional do sistema actual)?

Até estas decisões serem tomadas, manter este documento como proposta; não activar migrações nem modificar os movimentos históricos.
