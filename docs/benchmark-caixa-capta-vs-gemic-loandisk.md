# CAPTA | Caixa Geral — benchmark de excelência funcional e financeira
**Referências internas examinadas:** `Loandisk – Análise técnica e funcional(1).pdf` (17/11/2026, 48 páginas) e `GEMIC – Análise técnica e funcional(2).pdf` (16/11/2026, 32 páginas).

> **Objectivo, não certificação:** tornar a Caixa Geral do CAPTA **mais fiável, compreensível, auditável e integrada com os créditos em Moçambique** do que as experiências observadas nos relatórios. Nenhuma superioridade pode ser afirmada antes de testes comparativos de ponta a ponta em ambiente equivalente. Os resultados dos dois relatórios provêm de testes distintos e não constituem certificação independente.

## 1. O que as aplicações de referência já oferecem

### GEMIC (secções 3.6, 3.7, 8, 9 e imagens da p. 12)
- Várias contas: saldo, entrada, saída, transferências, edição e remoção; conta receptora associada ao contrato; opção de numerário.
- Livro diário com concessões, amortizações, outras entradas e saídas.
- Fluxo de caixa anual e mensal, esperado vs. recebido, análise comparativa de meses e categorias.
- Estornos com motivo; extractos/recibos integrados ao crédito.
- **Problemas observados no teste:** comissões debitadas em vez de creditadas e omissas no diário; estorno executado ausente na lista; reforço/renovação contados como novos desembolsos; saldo de topo não actualizado sem reload; divergências de cálculo e vencimentos.

### Loandisk (secções 3.4, 3.8, 3.9, 4, 5 e 7)
- Bancos e transferências com aprovação, reconciliação bancária, diários, plano de contas, demonstrações financeiras, impostos, fecho de períodos, relatórios e auditoria com IP.
- Pagamentos por CSV, recepção online via integrações, distribuição por capital/juros/comissões/mora.
- 2FA, perfis e restrições de acesso, backups e exportações.
- **Falha observada no teste:** permitiu conceder 35.000 MZN sem fundos previamente registados na caixa.
- **Limitação para Moçambique:** M-Pesa local não foi testado directamente; prestadores intermediários dependem de integração e contrato.

## 2. Critérios de vantagem competitiva obrigatória do CAPTA

| Critério | Regra de aceitação CAPTA | Referência que importa superar | Estado em 17/11/2026 |
|---|---|---|---|
| Fonte financeira | Único livro de movimentos: `Extracto`; `cis_cash_accounts` para contas; histórico antigo sem atribuição inventada | GEMIC / Loandisk | **Estrutura concluída**; 1.295 linhas históricas preservadas |
| Saldo de abertura | Exigir evidência real, data de corte, identidade do confirmador e marcador do Extracto | GEMIC | **Implementado**, dependente da confirmação real da conta |
| Prevenção de saldo negativo | Saída/desembolso rejeitado por saldo insuficiente sob bloqueio transaccional | Loandisk | **Prévia apenas**; operação real bloqueada |
| Exactidão financeira | Aritmética de cêntimos, regra explícita de arredondamento; balanço por conta e geral reconciliável | GEMIC | **Parcial**; prévia em cêntimos, escrituração ainda não aprovada |
| Idempotência | Mesmo pedido, mesmo resultado: não duplicar lançamentos mesmo após timeout/retry | Ambos | **Pendente de desenho e esquema aprovado** |
| Transferências | Duas pernas atómicas, identificador correlacionável, mesmo valor, mesma transacção, nunca contam como receita/despesa externa | GEMIC | **Prévia apenas** |
| Desembolso de crédito | Debitar conta activa com fundos reais na mesma transacção que cria crédito/plano/Extracto | Loandisk | **Bloqueado deliberadamente** |
| Recebimento de prestações | Creditar conta efectiva e actualizar prestação + recibo + Extracto atomicamente; impedir dupla cobrança | GEMIC | **Bloqueado deliberadamente** |
| Encargos e comissões | Classificação de capital, juros, comissões e mora; entradas não debitadas e sem duplicar totais | GEMIC | **Pendente** |
| Reforço e renovação | Só dinheiro efectivamente entregue entra no fluxo de caixa; mera reestruturação não cria débito/entrada | GEMIC | **Pendente** |
| Estornos | Reversão explícita e imutável com motivo, referência ao lançamento inicial, responsável, valores simétricos e lista pesquisável | GEMIC | **Pendente** |
| Fluxo de caixa | Visão diária, semanal, mensal, anual; recebimentos/pagamentos reais; previsto vs. realizado; filtro por conta/categoria | GEMIC | **Pendente** |
| Relatórios e exportação | Livro diário, extrato de conta, resumo por categoria, caixa por operador; CSV/Excel; campos MZN e datas de Maputo | GEMIC | **Leitura limitada aos últimos 200** |
| Rastreabilidade | Quem, quando (Maputo e UTC), acção, conta, chave, documento, contrato/prestação, motivo de estorno; sem editar movimentos já lançados | Loandisk | **Parcial**; não há livro auditável de operações reais |
| Segurança | Perfis por operação/conta/limite; 2FA; revisão de permissão, backup/restore testado e registo de acessos | Loandisk | **Parcial**; administrador único autorizado apenas para abertura de saldo |
| Fecho e controlo | Fecho diário de numerário, diferença física vs. sistema, bloqueio de período e reabertura justificada | Loandisk | **Pendente** |
| Moçambique | MZN, português, Africa/Maputo, contas locais e possibilidade de integração M-Pesa/e-Mola/mKesh com confirmação do prestador | Ambos | **Base local**; **integrações não testadas** |
| UX | 3 acções por conta (entrada, saída, transferência), formulário guiado, prévia vs. confirmação claramente separadas; sem cards redundantes | GEMIC | **Parcial**, acções em Livro de Movimentos e prévias seguras |

## 3. Requisitos inegociáveis antes de activar qualquer movimento real

**BLOQUEIO DE PRODUÇÃO** — não activar `CIS_CASH_WRITES_APPROVED`, cobranças nem desembolsos até todos os pontos abaixo estarem verdes:

1. **Idempotência persistente.** A proposta de `Extracto.operation_key` UNIQUE nullable é uma **alteração ainda não aprovada**. Sem ela ou alternativa equivalente comprovada, não activar escrita. Transferências exigem chaves distintas e correlacionadas por origem/destino.
2. **Esquema real auditado.** Confirmar os campos e tipos do Extracto, índices, permissões e todas as fórmulas. Ensaio de migração em restauro isolado; não reutilizar ficheiro de migração já executado.
3. **Contabilidade de saldo sob bloqueios.** Transacção InnoDB: bloquear contas em ordem determinística, validar estado/moeda, ler saldos completos, verificar limite, inserir lançamento(s), associar operação de crédito, commit atómico; recuperar após timeout sem duplicar.
4. **Registos não apagáveis.** Estorno deve ser lançamento reverso autorizado e referenciado ao original; sem DELETE/UPDATE de histórico financeiro.
5. **Papel de cada operação.** Diferenciar: financiamento/desembolso (saída), cobrança (entrada), comissão (entrada, quando recebida), gasto (saída), aporte (entrada não operacional), transferência interna (saída+entrada, resultado líquido zero), renovação (alteração de contrato sem lançamento, excepto diferença de dinheiro real).
6. **Evidências e autorização.** Comprovativo em saídas e transferências; decisão documentada do administrador; limites por tipo/conta; nunca expor senhas da DB ou API.
7. **Reconciliação de números.** `saldo_final=saldo_inicial+entradas-saidas` por conta e no total, sem contar transferências como receitas externas; relatório de discrepâncias.
8. **Testes de concorrência e falhas.** Dois débitos simultâneos que excedem saldo: só um pode confirmar; replay do pedido: um único efeito; erro após primeira perna da transferência: rollback das duas; erro após commit: retry devolve mesma operação.
9. **Testes integrados de contratos.** Os exemplos de GEMIC (comissões, reforço, mora, vencimentos, estorno) e o exemplo de Loandisk (desembolso sem saldo) tornam-se **regressões automáticas obrigatórias**.
10. **Verificação operacional.** Backup + restauro testado, utilizador real, comprovativo, comparação de saldos e histórico na interface, registo dos resultados; não usar valores fictícios na base operacional.

## 4. Ecrãs recomendados — mais simples que os concorrentes

**Caixa Geral** com quatro separadores, sem réplica dos dados do contrato:

1. **Contas e saldos.** Saldo total confirmado; contas activas/por activar; cada conta mostra saldo, estado, última operação, e botões **Entrada / Saída / Transferir**. Identificador bancário mascarado e confirmação de abertura em fluxo próprio.
2. **Movimentos.** Livro único pesquisável por data, conta, natureza, referência, contrato, operador, categoria e estado, com detalhe e comprovativo; acções contextuais conforme estado e permissão. Até a escrita ser homologada, o texto deve indicar **simulação** em vez de **confirmar**.
3. **Fluxo de caixa.** Receitas e despesas efectivas por período, categorias, caixa livre, evolução e comparações; transferências internas aparecem em detalhe mas nunca inflacionam entradas externas.
4. **Controlo.** Conferência diária e fecho, diferenças, estornos e integridade/avisos, de forma proporcional ao tamanho da instituição.

**Regras de não duplicação:** pagamentos e tratamentos continuam no contrato; Caixa mostra o respectivo movimento financeiro apenas. O simulador genérico continua independente.

## 5. Plano de entrega e critérios de passagem

- **Fase A — segurança e dados:** chave de operação aprovada, migração segura ensaiada, saldos, bloqueios, transacções, estornos, testes de stress. **Passagem:** zero movimentos duplicados nos testes de retry e concorrência.
- **Fase B — operações Caixa:** entrada, saída, transferência, comprovativo, movimentos detalhados, extrato e exportação. **Passagem:** contas e total conciliam com o Extracto após todas as operações e estornos.
- **Fase C — contratos:** desembolsos, cobranças parciais/totais, comissões e reestruturações ligadas atomicamente à Caixa. **Passagem:** zero diferença entre fluxo de caixa real e dinheiro efectivamente entregue/recebido.
- **Fase D — controlo/gestão:** fluxo de caixa, fecho, relatórios, filtros/exportações, 2FA/perfis e integrações locais opcionais. **Passagem:** testes de relatório, UX móvel, permissões e recuperação após falhas aprovados.

### Declaração de qualidade

**É incorrecto afirmar hoje que a Caixa do CAPTA é superior à do GEMIC ou Loandisk em capacidade funcional.** A estrutura e o modo conservador de homologação são pontos promissores, mas o CAPTA ainda não realiza movimentos reais, estornos ou demonstração de fluxo de caixa. A superioridade deverá ser demonstrada por esta matriz, não pela aparência do ecrã.
