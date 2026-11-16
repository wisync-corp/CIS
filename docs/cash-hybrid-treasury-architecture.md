# CAPTA · Modelo híbrido de Caixa Geral e Tesouraria opcional

**Decisão aprovada: C — Extracto único + duas tabelas complementares**, sem duplicar movimentos financeiros. A Caixa Geral faz parte do CAPTA padrão; a Tesouraria é opcional, com reconciliação bancária e gestão avançada. O direito de desembolsar depende de permissões híbridas, **não** da subscrição da Tesouraria.

## 1. Fonte única e três entidades lógicas

```text
                 [cis_cash_accounts]
                   id; code; type
                   opening_balance; cut_at
                   status; evidence; validator
                           |
                           | 1:N account_id
                           v
                   [cis_cash_links]             <------ Tesouraria (opcional)
                    extracto_nr UNIQUE                 associa extrato bancário
                    account_id                          à mesma referência
                    operation_id (transferências)       sem gravar outro movimento
                    nature, category, evidence
                    confirmation, confirmed_at/by
                    counts_after_opening
                           |
                           | N:1 (logicamente; UNIQUE)
                           v
                      [Extracto]
                        Nr PK
                        Tipo de Movimento
                        Valor
                        Data da Operação
                        Descrição; Contracto; Parcela
                        usuario
```

**Duas tabelas novas**, não duas tabelas independentes de lançamentos:

1. **`cis_cash_accounts`**: cadastro das contas reais (banco, numerário, carteira móvel), estado, moeda, saldo de abertura, corte e referência de comprovativo/validação.
2. **`cis_cash_links`**: associação **1:1** de cada linha financeira `Extracto.Nr` à conta utilizada, acrescida de metadados de confirmação, categoria, idempotência e agrupamento de transferências. **Não tem Valor, Débito ou Crédito próprios:** estes são lidos exclusivamente de `Extracto`.

O `Extracto` **não é uma terceira tabela nova**; é o livro de movimentos legado. Cada `Extracto.Nr` poderá ter **uma única** conta associada. Uma conta terá muitos movimentos.

## 2. Campos relevantes da arquitectura

### cis_cash_accounts

| Campo | Tipo | Semântica |
|---|---|---|
| id | BIGINT PK | Identificador técnico |
| code | VARCHAR(40), UNIQUE | Código institucional |
| name | VARCHAR(150) | Designação |
| type | ENUM | banco, numerario, carteira_movel |
| identifier | VARCHAR(120), null | Conta ou carteira |
| currency | CHAR(3) | Inicialmente MZN |
| status | ENUM | em_preparacao, pendente_validacao, activa, suspensa |
| opening_balance | DECIMAL(18,2), null | Saldo documentado e aprovado; não é movimento de Extracto |
| cut_at | DATETIME, null | Data/hora de corte em Africa/Maputo, a confirmar |
| evidence_reference | VARCHAR(255), null | Referência do documento de abertura |
| created_by; validated_by; validated_at | IDs e timestamp | Responsabilidade |
| created_at; updated_at | timestamps | Auditoria básica |

### cis_cash_links

| Campo | Tipo | Semântica |
|---|---|---|
| id | BIGINT PK | ID da associação |
| extracto_nr | BIGINT UNSIGNED, UNIQUE | Uma linha de Extracto não pode pertencer a duas contas |
| account_id | FK cis_cash_accounts.id | Conta financeira identificada |
| operation_id | UUID, null, INDEX | Liga as duas pernas de uma transferência |
| idempotency_key | VARCHAR(100), UNIQUE | Impede reenvio da mesma operação |
| nature | ENUM | desembolso, recebimento, receita_geral, despesa_geral, transferencia, ajuste, estorno |
| category | VARCHAR(90), null | Classificação operacional |
| evidence_reference | VARCHAR(255), null | Comprovativo de execução; não é reconciliação bancária |
| confirmation | ENUM | manual, automatica (reservado para futuro) |
| confirmed_at; confirmed_by | timestamp, actor | Confirmação operacional |
| counts_after_opening | BOOLEAN | false para associação de linha histórica já abrangida no saldo de abertura |
| created_at; updated_at | timestamps | Histórico técnico |

**Nota importante:** a correspondência de `extracto_nr` com `Extracto.Nr` ainda é uma **integridade lógica verificada pelo backend**, não uma foreign key física. O esquema físico do `Nr`, o motor InnoDB e os privilégios terão de ser confirmados na base real antes de criar uma FK.

## 3. Fluxos normais de Caixa

### Receita ou despesa geral
1. Seleccionar uma conta **activa** e autorizada.
2. Indicar natureza, montante, data, categoria, descrição e comprovativo quando obrigatório.
3. Verificar saldo **da conta**, limites e regras de acesso, idempotência e concorrência.
4. Na **mesma transacção MySQL/InnoDB**, fazer um único INSERT em `Extracto`, obter o `Nr` e criar um único `cis_cash_links` para essa conta.
5. Confirmar a transacção ou efectuar rollback de ambos os INSERTs. **Nunca** gravar valores financeiros independentes na associação.

### Transferência interna entre contas
1. Verificar as duas contas activas, na mesma moeda; exigir prova de débito **e** crédito concluídos.
2. Bloquear as duas contas por ordem determinística de ID e verificar saldo da origem.
3. Criar duas linhas em `Extracto`: uma `Saídas` na origem e outra `Entradas` no destino.
4. Criar duas associações `cis_cash_links` apontando respectivamente aos dois `Nr`, com **o mesmo `operation_id`** e chaves de idempotência diferentes mas derivadas da mesma solicitação.
5. Confirmar **atomicamente**. No total consolidado, a transferência tem impacto zero (não é receita/despesa).
6. Se o banco ainda não creditou o destino, a transferência **não pode ser marcada como concluída**; fluxo de pendências é evolução separada.

### Desembolso associado ao crédito
1. Crédito autorizado conforme o perfil/limite; conta seleccionada, activa, saldo suficiente.
2. Em operação transaccional única, gerar `Contractos` (prestações), inserir `Extracto` de saída e ligar `Extracto.Nr` à conta no `cis_cash_links`.
3. A operação só é desembolsada quando a entrega efectiva é comprovada. Um pedido de transferência não equivale a execução.
4. Sem conta, sem aprovação ou sem saldo: nenhum contrato ou saída financeira final é confirmado.
5. Nenhum movimento antigo é reinterpretado automaticamente.

### Recebimento de prestação
1. A cobrança confirma modalidade, montante, conta receptora e execução.
2. Gravar a atualização da prestação `Contractos`, a entrada de `Extracto` e a associação da conta **na mesma transacção**.
3. Impedir cobrar novamente uma prestação; não permitir movimento financeiro solto sem associar contrato e prestação.
4. Evitar lançar manualmente no Caixa uma receita que a rotina de cobranças já gravou.

## 4. Cálculo de saldo sem dupla contagem

`Saldo disponível = opening_balance aprovado + SUM(Extracto.Entradas associadas após corte) − SUM(Extracto.Saídas associadas após corte) − reservas vigentes`.

- **Nunca usar o saldo global do Extracto para autorizar uma saída de uma conta.**
- **Nunca somar um movimento do Extracto duas vezes** (via ligação e movimento independente).
- `counts_after_opening=false`: movimento histórico associado apenas para consulta, já reflectido no saldo inicial.
- Operações posteriores à homologação contam a partir do movimento `Extracto` e do seu vínculo único; não duplicar na Tesouraria.
- A data de corte de `cis_cash_accounts` é documental; **o `Extracto` usa data sem hora**, pelo que o corte por timestamp não é suficiente para classificar movimentos do mesmo dia. O marcador explícito `counts_after_opening` impede recálculo errado. A política de backdating e a reconciliação histórica devem ser homologadas antes de qualquer escrita.
- Reservas para saídas aprovadas mas ainda não executadas requerem **controlo separado de pendências**, a definir no motor de aprovações; não reduzir indevidamente o saldo contabilístico.

## 5. Tesouraria opcional: previsão sem duplicar a Caixa

A Tesouraria consulta **as mesmas contas** e **os mesmos movimentos do Extracto** por intermédio de `cis_cash_links`. Não surge um novo ledger monetário.

| Domínio | Caixa Geral (incluída) | Tesouraria (opcional) |
|---|---|---|
| Registo operacional | Contas, entradas, saídas, transferências | Os mesmos dados, sem duplicação |
| Confirmação | Manual com comprovativo/referência e auditoria | Mantém confirmação manual |
| Dados bancários | Não importa extractos externos | Importação bancária / M-Pesa / outros |
| Reconciliação | Não disponibiliza matching | Matching automático e manual de extracto ↔ Extracto.Nr |
| Diferenças | Recusa movimentos inválidos e duplicados | Identifica pendentes, divergências e movimentos bancários não reconhecidos |
| Planeamento | Consulta de saldos | Previsão de liquidez, compromissos e cenários |
| Contabilidade | Categoria financeira simples | Mapeamento pré-contabilístico, exportação e relatórios |
| Aprovações | Híbridas, por perfil e limite | Mesma matriz e motor, com análises adicionais |

**Evolução futura:** a Tesouraria poderá ter tabelas próprias de **importação de extractos bancários**, **correspondências de reconciliação**, **planeamento** e **classificação contabilística**, sem tocar na fonte única `Extracto`. **Estas tabelas avançadas NÃO fazem parte das duas tabelas novas da Caixa**, nem são necessárias para operar a versão básica.

O estado de reconciliação bancária não deve ser confundido com `confirmation=manual` da Caixa. Uma operação confirmada manualmente **não** significa conciliada. Quando houver Tesouraria, a correspondência externa será registada separadamente e sem reescrever o movimento.

## 6. Permissões e segurança: independentes da subscrição

- A Caixa é padrão, mas as permissões de cada pessoa são por perfil + atribuições individuais + limites + contas autorizadas.
- Execução directa ou com aprovação depende da política institucional e das permissões, **não** da activação da Tesouraria.
- Sempre validar no backend; a interface não é autorização.
- Audit trail, idempotência, integridade e rollback são capacidades de segurança base, nunca reservadas à Tesouraria.
- Não apagar ou editar silenciosamente `Extracto` nem associações confirmadas; correcções futuras deverão gerar estornos referenciados.

## 7. Estado no GitHub e plano de transição

**Modelo de duas tabelas implementado apenas na migração proposta**, `backend/database/migrations/2026_11_16_000030_create_cis_cash_tables.php`. O controlador de consulta foi adaptado para ler `cis_cash_links` com join a `Extracto`. **Os endpoints que escreviam movimentos na tabela antiga foram bloqueados com erro 503**; são incompatíveis com o novo modelo e não podem ser reactivados pela flag.

**Não houve execução de migração na VPS nem criação ou modificação de movimentos reais nesta alteração.**

Sequência segura:
1. **Não aplicar a migração anterior** com `cis_cash_movements`. Confirmar se já existe tabela antiga ou parcial no ambiente antes de avançar.
2. Backup e ensaio numa cópia da base legada; confirmar tipo de `Extracto.Nr`, invariantes e permissões de escrita.
3. Instalar **só** as duas tabelas `cis_cash_accounts` + `cis_cash_links` se a base não tiver versões anteriores.
4. Testar cadastro e validação de conta com documentação verdadeira, sem movimentação financeira.
5. Implementar e testar operações atómicas `Extracto + cis_cash_links` para movimentos gerais, transferências, desembolsos e cobranças.
6. Implementar permissões híbridas, estornos auditáveis, provas e observabilidade; só então autorizar escritas reais.

**Sem autorização de implantação produtiva e homologação de fluxos, Caixa e desembolsos permanecem desligados para novos lançamentos.**
