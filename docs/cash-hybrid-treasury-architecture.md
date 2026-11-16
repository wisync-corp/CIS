# CAPTA — Arquitectura aprovada: uma tabela de contas + account_id em Extracto

**Decisão do cliente:** criar **apenas `cis_cash_accounts`** e acrescentar uma coluna **`account_id` nullable** à tabela financeira legada **`Extracto`**. A anterior tabela complementar **`cis_cash_links` foi descartada**. A Caixa Geral é padrão; a Tesouraria é opcional; as permissões por utilizador são independentes da contratação da Tesouraria.

## Relação financeira definitiva

```text
cis_cash_accounts                     Extracto (existente)
-----------------                    ------------------
id (PK)                  1  <----- N account_id (NULL para histórico não atribuído)
code                                Nr (PK)
name                                Contracto; Cliente; Parcela
type (banco/numerário/móvel)        Tipo de Movimento: Entradas/Saídas
identifier                          Valor
currency                            Data da Operação
status                              Descrição
opening_balance                     usuario
cut_at
opening_extracto_nr
evidence_reference
validated_by; validated_at
created_at; updated_at
```

**Não existe segundo livro de movimentos.** Todos os movimentos financeiros confirmados permanecem no Extracto. Para cada nova operação financeira, o backend atribuirá o `account_id` explícito da conta usada. A coluna é **nullable** para não inventar a origem dos movimentos históricos e para preservar compatibilidade durante a transição. Nas novas operações CAPTA, a conta deve ser obrigatória no backend, sem depender exclusivamente de `NOT NULL` no banco.

## Campos da tabela adicional

A tabela `cis_cash_accounts` contém: `id` (BIGINT PK), `code` único, `name`, `type` (banco/numerário/carteira móvel), `identifier`, `currency` (inicialmente MZN), `status` (em preparação, pendente de validação, activa, suspensa), `opening_balance` (DECIMAL(18,2)), `cut_at` (data/hora de corte), `opening_extracto_nr` (maior Extracto.Nr no instante de validação da abertura), `evidence_reference`, `created_by`, `validated_by`, `validated_at` e timestamps.

`opening_extracto_nr` permite distinguir de forma explícita os movimentos novos dos anteriores à validação do saldo inicial, mesmo porque o Extracto original armazena `Data da Operação` como data sem hora. Os movimentos anteriores permanecem disponíveis no Extracto, mas **não entram de novo no saldo de abertura**. Se houver escritores externos que inserem linhas depois do marcador mas com data histórica, é necessária política de integração; não marcar automaticamente essas operações como pertencendo à Caixa.

## Saldo por conta

`saldo = opening_balance validado + SUM(Entradas no Extracto com account_id da conta e Nr > opening_extracto_nr) - SUM(Saídas nas mesmas condições)`.

Em fases posteriores, o **saldo disponível** descontará ainda reservas válidas para operações autorizadas mas não executadas. Não utilizar o saldo agregado do Extracto para autorizar a saída de uma conta.

## Operações no Caixa padrão

- **Cadastro de contas**: criar conta em preparação; anexação documental e prova de saldo de abertura ainda carecem de implementação completa de armazenamento; a versão inicial aceita referência documental.
- **Receitas/despesas gerais**: inserir UMA linha em Extracto com `account_id` da conta, sob validação e bloqueio de saldo. Não criar um segundo movimento monetário.
- **Transferências internas concluídas**: registar duas linhas em Extracto, com `account_id` da origem na saída e `account_id` do destino na entrada, na mesma transacção InnoDB. O histórico ainda precisa de **identificador de operação partilhado** para agrupar as duas linhas de maneira inequívoca — evolução do esquema ou camada específica a definir; não inferir agrupamento pela descrição.
- **Desembolso de crédito**: gravar Contractos, saída em Extracto com conta obrigatória e referência da operação numa só transacção. A autorização e a confirmação de entrega são diferentes.
- **Recebimentos**: actualizar prestação e inserir entrada em Extracto com conta receptora identificada na mesma transacção.
- **Correcções**: por estorno identificado e auditável, nunca apagar silenciosamente o movimento.

## Preparação da Tesouraria (opcional)

A Tesouraria reutilizará `cis_cash_accounts` e as linhas do `Extracto` com `account_id`. Terá funcionalidades avançadas de: importação de extractos bancários, **reconciliação**, detecção de divergências, previsão de entradas/saídas, compromissos, gestão de liquidez, classificação pré-contabilística e relatórios aprofundados.

Poderão existir **tabelas próprias de dados bancários importados, matches de reconciliação, compromissos e classificações contabilísticas** quando a Tesouraria for implementada. Essas tabelas são auxiliares, **não duplicam os lançamentos financeiros do Extracto**, e não são necessárias para a Caixa padrão.

| Capacidade | Caixa padrão | Tesouraria opcional |
|---|---|---|
| Contas, saldos e registo de movimentos | Sim | Usa os mesmos dados |
| Confirmação operacional manual e comprovativo | Sim, após homologação | Sim |
| Regras de permissão directas ou em duas fases | Configuráveis | As mesmas |
| Importar extractos bancários | Não | Sim |
| Reconciliar transacções | Não | Sim |
| Planeamento, compromissos, fluxo de caixa previsto | Não | Sim |
| Pré-contabilidade e relatórios avançados | Não | Sim |

**A confirmação manual de execução na Caixa não deve ser confundida com reconciliação bancária da Tesouraria.**

## Atributos adicionais não resolvidos apenas com account_id

A decisão de criar uma única tabela nova simplifica o modelo, mas a coluna `account_id` **não substitui**: chaves de idempotência, referência de comprovativos, agrupamento de transferências, segregação e histórico de aprovações, estornos e reconciliação. São capacidades a implementar antes de autorizar novas operações reais. A sua representação poderá exigir **colunas adicionais em Extracto ou tabelas específicas de processo**, mas não uma segunda tabela principal de movimentos.

É igualmente necessário avaliar se o Excel/VBA original e outros escritores continuam a inserir no Extracto sem `account_id`. Esse campo permanece nullable por compatibilidade, mas **os novos fluxos de Caixa do CAPTA não deverão aceitar movimentos sem conta**.

## Migração e segurança

- Migração proposta: `backend/database/migrations/2026_11_16_000030_create_cis_cash_tables.php`, agora **cria somente cis_cash_accounts e adiciona Extracto.account_id**, sem preencher históricos.
- A migração **falha** se encontrar `cis_cash_links`, `cis_cash_movements`, `cis_cash_accounts` já existentes ou `Extracto.account_id` já criada. Não faz conversão ou remoção automática de estruturas anteriores.
- DDL MySQL pode deixar uma instalação parcial se falhar entre operações; confirmar **backup restaurável, ensaio em cópia da base, bloqueio dos escritores externos e pré-voo** antes de produção.
- A FK física de Extracto.account_id a cis_cash_accounts.id está diferida até verificar tipos, permissões, bloqueios e impacto no sistema legado. A API deverá validar a conta de cada operação.
- **Até implementar gravação transaccional, permissões e idempotência, endpoints de movimentos/transferências retornam 503 e abertura/desembolso permanece bloqueado.**
- Não foi executada nenhuma migração ou lançamento financeiro na VPS pelo assistente. Não confundir aprovação do desenho com aprovação para alterar o banco em produção.
