# Pagamentos e desembolsos ligados à Caixa + histórico imutável

Decisões do proprietário (18/11/2026): pagamentos aceites em contratos **históricos (VBA) e novos**; a conta receptora é **escolhida em cada pagamento**; desembolsos activados na mesma etapa; o histórico das prestações **nunca se perde**.

## Pagamento (liquidação total)

| Regra | Origem |
|---|---|
| Pendente → `Pago`; atrasada → `Pago em Atraso`; `Situação de Pagamento = Pagamento Completo` | Cobrança.frm `HandlePayment` |
| Entrada no Extracto `Receita de Juros` com Contracto, Cliente, Parcela | `UpdateTable6` |
| Juro variável histórico: `ROUND(Capital × %Juro/100 × dias(Data de Inicio → pagamento), 2)`; `Capital ≤ 0` usa `Quantia Creditada` | `Image8_Click`, `ActualizarFinanceiroCobranca` |
| Juro fixo histórico: `Valor de Juros` e `Valor a Pagar` registados | `HandlePayment` |
| Multa opcional sobre os juros, só com atraso | Parcelas.frm (`c_vrem = juros`) |
| Contratos CIS novos: meticais inteiros, arredondamento para cima | motor CIS homologado |

**Diferenças intencionais face ao VBA**
- No VBA, a liquidação atrasada com multa calcula `v_total = v_pagar + v_multa + v_juros`, somando os juros duas vezes (o `Valor a Pagar` já os inclui). O CIS cobra `Valor a Pagar + multa`. Se a dupla contagem for política da empresa e não erro, tem de ser decidida explicitamente.
- O campo `Valor Pago` dos contratos VBA vem pré-preenchido com o valor esperado; o CIS usa `Valor a Pagar` e avisa quando divergem.
- Data de pagamento = hoje (Maputo). O VBA permitia datas passadas; datas retroactivas desalinham fechos diários.
- Bloqueado se já existir Entrada no Extracto para a mesma parcela.
- Pagamento parcial, remanescente, renovação de juros, capitalização e prorrogação continuam **apenas em simulação**.

## Desembolso
Sai de uma conta activa escolhida, com verificação do **saldo dessa conta** (o VBA usava o saldo agregado de todo o Extracto). Saída com `account_id`, chave idempotente e referência do comprovativo.

## Garantias técnicas
- Conta bloqueada (`FOR UPDATE`) antes da prestação, na mesma ordem em todos os fluxos (sem deadlocks).
- `operation_key` única (`contract:collect:<uuid>`, `contract:open:<uuid>`): duplo clique ou reenvio não duplica.
- Descrições com prefixo `[CAIXA:COBRANCA]` / `[CAIXA:DESEMBOLSO]` para os relatórios da Caixa.

## Histórico imutável (`cis_contractos_historico`)
O VBA (`ActualizarFinanceiroCobranca`) **sobrepõe** Capital, Juros, Valor a Pagar, % de Juro e Intervalo na mesma linha — renovação e capitalização apagavam o histórico. No CIS, toda alteração de prestação grava, **na mesma transacção**, a fotografia completa da linha **antes e depois**, a operação, o utilizador, a conta, o Nr do Extracto e a referência. Se o histórico falhar, nada é gravado.

- Só INSERT: o escritor não tem UPDATE/DELETE; triggers MySQL recusam alteração/eliminação mesmo a outros utilizadores.
- Regra obrigatória para as próximas operações (renovar juros, capitalizar, parcial, prorrogação): só podem ser activadas usando `ContractHistory::record()` na mesma transacção.
- Consulta: separador **Histórico** da ficha do contrato → «Alterações registadas» (campos alterados destacados).

## Activação na VPS
```bash
bash deploy/install-contract-history.sh      # INSTALAR-HISTORICO-PRESTACOES
bash deploy/activate-contract-cash-flows.sh  # ACTIVAR-PAGAMENTOS-DESEMBOLSOS
bash deploy/verify-contract-cash-flows.sh    # só leitura
bash deploy/disable-contract-cash-flows.sh   # interruptor
```

## Ensaio
`backend/tests/contract_cash_flows_mysql_rehearsal.php` corre no CI (job `contract-cash-mysql`, MySQL 8.0 descartável): 24 verificações — contrato histórico fixo e variável com multa, conta escolhida, idempotência, valor errado, conta inexistente, entrada duplicada, rollback se o histórico falhar, imutabilidade, desembolso com saldo insuficiente/suficiente e repetição.

## Renovação do empréstimo (mesmo contrato)

Fluxo: «Houve pagamento» → «Renovar empréstimo». Só está disponível quando a prestação é a única em aberto do contrato.

- O cliente paga **só os juros**. A **multa** é opcional e incide sobre os juros, quando houver atraso.
- O operador escolhe a **data de início** do novo período. O **vencimento é automático**: início + o mesmo prazo.
  - Contratos de uma parcela: o prazo vem de «Prazo/Duração» (1/2/3 Semanas, 1/2/3 Meses, N Dias).
  - Contratos com várias parcelas: o prazo é o intervalo entre esta prestação e a anterior.
- A prestação actual passa a `Estado='Renovado'`, com `Valor Pago` igual a juros + multa e a data de pagamento de hoje.
- É criada uma nova prestação **no mesmo contrato** com:
  - `Nº da Parcela` = máximo + 1;
  - o mesmo capital e as mesmas condições;
  - juros fixos iguais; juros variáveis estimados e recalculados no pagamento;
  - `Estado='Pendente'`.
- O Extracto recebe uma Entrada `[CAIXA:COBRANCA] Juros de renovação …` na conta escolhida (chave `contract:renew:<uuid>`).
- O histórico imutável regista `renovar_juros` (antes/depois) e `renovacao_nova_prestacao`.
- Não há limite de renovações.
- `Renovado` conta como prestação liquidada para finalizar o contrato e nos juros recebidos da Visão geral.

## Data de pagamento

- Por defeito, a data de pagamento é hoje (Maputo).
- **Só o administrador** pode escolher uma data passada. Gestor, agente e cobrador registam sempre com a data de hoje; o servidor recusa outra data com 403.
- Regras para uma data diferente de hoje:
  - nunca é futura;
  - nunca é anterior à abertura da conta na Caixa (`cut_at`);
  - nunca cai num dia em que essa conta já tenha fecho diário (nem antes desse fecho).
- Juros variáveis, dias de atraso, estado (Pago/Pago em Atraso) e multa são calculados até à data escolhida.
- A data fica registada em `Data de Pagamento` e no Extracto (`Data da Operação`).
- Pagamento antecipado de uma prestação, por exemplo depois de uma renovação, é permitido.

## Extracto (menu «Extractos»)

Equivale ao formulário VBA «Extracto de Movimentos».

**Geral** (só administrador): movimentos do Extracto.
- Filtros: datas da operação, cliente, contrato, tipo (entradas/saídas) e conta da Caixa.
- Totais: Entradas, Saídas e Total disponível.

**Clientes**: prestações.
- Filtros: datas de cobrança, cliente, contrato e estado (Abertas/Vencidas/Pagas).
- Totais: Em aberto, Vencido, Pago, cada um com os juros (e a multa).
- Diferenças em relação ao VBA:
  - «Vencido» soma o valor em dívida (Valor a Pagar + multa); o VBA somava o Valor Pago, que é 0.
  - Uma prestação em aberto com vencimento passado conta como vencida mesmo que o Estado ainda diga «Pendente».
  - «Renovado» conta como paga.

**Exportar CSV (Excel)**: todas as linhas do filtro actual, separador `;`, compatível com Excel.

**Extracto do cliente (PDF)**: evidência para entregar ao cliente.
- Contém:
  - os dados do cliente;
  - o resumo (em aberto, vencido, pago, total em dívida);
  - os contratos;
  - todas as prestações;
  - os movimentos registados.
- Inclui um código de verificação (SHA-256 do conteúdo).
- As descrições aparecem sem marcas internas ([CAIXA:…], REF:).
- Gera-se com «Imprimir / Guardar PDF» → «Guardar como PDF».
