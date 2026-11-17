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
