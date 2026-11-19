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

**Extracto geral (PDF)** (modo Geral): documento imprimível com os filtros actuais, os totais Entradas/Saídas/Disponível, todas as linhas (até 5000) e um código de verificação.

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

### Cartões da Visão geral → Extracto

Cada cartão da Visão geral abre o Extracto (Clientes) já filtrado, com o mesmo mês e ano:

| Cartão | Estado | Data de referência |
|---|---|---|
| Carteira desembolsada | todos | desembolso: contratos cuja 1.ª Data de Inicio cai no período |
| Juros / Multas recebidos | pagas | Data de Pagamento |
| Prestações vencidas | vencidas | Data de Cobrança |
| Prestações pendentes | abertas | Data de Cobrança |

Os números do cartão e do Extracto coincidem; o ensaio MySQL verifica-o.
Vencida = em aberto com vencimento antes de hoje, a mesma regra nos dois ecrãs.

### Informação do contrato (formulário VBA «Informação»)

Um clique numa linha do Extracto (Geral com contrato, ou Clientes) abre o painel «Informação». Mostra:
- o cliente: código com 3 dígitos, nome e contacto;
- o contrato: data de início, data final, quantia creditada e forma de pagamento;
- a situação: Pendente, Pagamento atrasado ou Finalizado;
- as prestações: total, pagas, em aberto e vencidas, o valor em dívida e pago, e o próximo vencimento ou os dias de atraso.

Acções:
- **Mensagem ao cliente** (substitui o frmMessageCenter): texto pronto conforme a situação, editável. Pode ser copiado ou aberto no WhatsApp ou SMS do dispositivo, para o operador confirmar e enviar. O CIS não envia mensagens sozinho.
- **Extracto do cliente (PDF)** do contrato.

## Centro de mensagens (menu «Mensagens», frmMessageCenter do VBA)

- **Instalação na VPS:** `bash deploy/install-message-center.sh`. Cria `cis_message_templates` e `cis_message_log`, com 4 modelos iniciais e os 5 modelos CAPTA (Cobrança Antecipada, Bom dia, Mensagem no próprio dia, Mensagem Vencida, Actualização de Dados). É idempotente e não substitui modelos editados.
- **Modelos:** Novo / Editar / Guardar / Cancelar / Eliminar. Campos `{…}` com os nomes do VBA:
  - do contrato: `{Nome Completo}`, `{Nr do Contracto}`, `{Data de Cobrança}`, `{Valor a Pagar}`, `{Dias}`, `{-1*Dias}`, `{% de Juro}`, `{Quantia Creditada}`, `{Data de Inicio}`, `{Contacto}`, `{Nº de Cliente}`;
  - novos: `{Primeiro Nome}`, `{Valor em Dívida}`, `{Prestações Vencidas}`, `{Dias de Atraso}`, `{Data Final}`.
  - Há também uma paleta de emojis.
- **Envio:** o CIS prepara a mensagem e abre-a no WhatsApp (ou SMS) do dispositivo do operador, que confirma o envio. Não há envio automático, nem pyautogui, nem credenciais no código. Cada abertura fica em `cis_message_log` (contrato, número, canal, texto, operador, data).
- **Prestação de referência:** a vencida mais antiga; senão, a próxima em aberto; senão, a última.
- **Mandar para todos:** fila de envio com «Abrir próxima», seguindo as regras do `capta_whatsapp_bot.py`:
  - Todos: um por cliente, com o contrato mais recente;
  - Activos: um por cliente, com o contrato em aberto mais recente;
  - Inactivos: clientes sem contratos em aberto;
  - Pendentes e Atrasados: por contrato, pela data de vencimento.
- **Imagem:** o WhatsApp não aceita anexos por ligação; anexa-se na conversa depois de a abrir.
- **A partir do Extracto:** painel Informação → «Mensagem ao cliente» abre o centro de mensagens para esse contrato.

### Envio automático pelo WhatsApp Web (extensão «CIS WhatsApp»)

Código em `extension/cis-whatsapp/`. O pacote para instalar é `frontend/public/cis-whatsapp-extension.zip`, descarregável no CIS em Mensagens; gera-se com `bash extension/build.sh`.

**Instalação** (Chrome/Edge do operador):
1. Descompactar o ficheiro.
2. Abrir `chrome://extensions` e ligar o «Modo de programador».
3. «Carregar expandida» e escolher a pasta.
4. Ligar o CIS, de uma de duas formas (v1.1):
   - com o CIS aberto, clicar no ícone da extensão: o endereço aparece preenchido. Carregar em «Ligar este endereço»;
   - ou, no menu de extensões do navegador, escolher «Permitir neste site».
5. Voltar ao CIS e carregar em «Verificar novamente», ou recarregar a página.

A ponte activa-se em qualquer endereço específico autorizado, nunca em «todos os sites», e só actua em páginas com `<meta name="cis-app">`.

**Fluxo**: Mensagens → «Enviar pelo WhatsApp Web» (um cliente) ou «Enviar a fila pelo WhatsApp Web».
1. A extensão usa o separador do WhatsApp Web, se já estiver aberto, ou abre-o:
   - visível, com «Mostrar o WhatsApp Web durante o envio» ligado;
   - numa janela minimizada, com a opção desligada (é o padrão).
2. Sem sessão iniciada:
   - mostra o separador para ler o código QR;
   - aparece o aviso «WhatsApp Web sem sessão…»;
   - espera até 10 minutos e continua depois do login;
   - se o operador escolheu esconder, volta a minimizar.
3. Para cada mensagem:
   - abre `web.whatsapp.com/send?phone=…&text=…`;
   - espera pela caixa de escrita;
   - carrega em Enviar;
   - confirma a mensagem na conversa, sem o relógio de pendente.

   Número inválido → falhada, e passa ao seguinte.
4. O intervalo entre mensagens é de 8 a 18 segundos, variável.
5. Avisos no canto superior direito: «Mensagem enviada para …», «Não enviada para …: motivo». Continuam se o operador mudar de ecrã.
6. Registo em `cis_message_log` com `channel=whatsapp_web` e `status` enviada/falhada, mais o motivo. Instala-se com `install-message-center.sh` (migração 000100).

**Limites**:
- Imagens não são enviadas.
- O envio só corre com o navegador aberto.
- Os selectores do WhatsApp Web estão todos em `wa.js` (`SEL`); se o WhatsApp mudar a página, ajustam-se aí.
- O WhatsApp não autoriza envio automatizado; o intervalo reduz o risco de bloqueio, mas não o elimina.

**Imagens (extensão 1.2):**
- Em Mensagens, «Anexar imagem» aceita JPG, PNG ou WebP até 5 MB.
- A imagem vai com a mensagem ou com a fila toda, e o texto segue como legenda.
- A extensão:
  - cola a imagem na conversa e, se o WhatsApp não abrir a pré-visualização, larga-a sobre a conversa;
  - preenche a legenda e carrega em Enviar no ecrã de pré-visualização;
  - confirma quando a pré-visualização fecha e a imagem aparece na conversa.
- A imagem não é guardada no servidor.

**Registo de mensagens** (em Mensagens; e, no envio de um contrato, «Mensagens anteriores deste contrato»):
- Filtros: datas (por defeito, hoje), estado (enviada, falhada ou aberta) e canal.
- Totais por estado; o motivo das falhas; o texto aparece ao clicar na linha.
- Actualiza-se sozinho quando a extensão devolve resultados.
- Se um registo falhar, aparece um aviso no canto superior direito.
- Se faltar a coluna de estado, o CIS avisa para correr `bash deploy/install-message-center.sh`.

## Relatórios regulatórios (menu «Relatórios», só administrador)

As definições estão todas em `backend/app/Http/Controllers/Api/ReportController.php` (`catalog()` e um método por relatório), para ajustar quando o Banco de Moçambique confirmar os modelos oficiais. Exporta-se em CSV (Excel) e imprime-se ou guarda-se em PDF, com código de verificação.

1. **Relatório periódico de actividade** (Decreto 50/2024, arts. 165 e 167 d)). Escolhe-se um período de/até, com atalhos.
   - **Desembolsos:** contratos com a primeira Data de Inicio no período; número, valor, montante médio, taxa média e clientes novos.
   - **Carteira à data final:** prestações iniciadas e não pagas até essa data. Capital = Valor a Pagar − Juros.
   - **Carteira em risco:** qualquer atraso, mais de 30 dias e mais de 90 dias.
   - **Recebimentos:** capital, juros, multas e renovações.
   - **Distribuições:** desembolsos por montante e por género.
   - **Caixa:** sem transferências internas.
   - **Maiores exposições:** os 10 clientes com mais capital em curso.
2. **Central de Registo de Crédito** (Aviso 12/GBM/2024). Situação a uma data.
   - Uma linha por contrato com saldo, ou liquidado há 30 dias ou menos.
   - Inclui a identificação do cliente, montante, saldo, valor vencido, dias de atraso e situação: Regular, Em atraso, Incumprimento (mais de 90 dias) ou Liquidado.
   - Assinala os dados em falta.
   - Modelo provisório: o formato oficial é definido por Circular.
3. **Branqueamento de capitais, anual** (Aviso 10/GBM/2024, capítulo XIII e art. 116).
   - Movimento por cliente no ano: desembolsado + recebido.
   - Assinala quem está fora do regime simplificado (mais de 30 000 MT num mês ou mais de 240 000 MT no ano) e a identificação em falta.
   - Os dados anuais entregam-se até 31 de Janeiro.
4. **Qualidade dos dados de identificação** (art. 138).
   - Clientes com contratos e BI, NUIT, contacto ou morada em falta, ou com documento caducado.
   - O NUIB ainda não existe no cadastro.

### Relatórios de gestão (grupo «Gestão»)

1. **Folha de cobrança** (antes «Agenda de cobranças») (período futuro; por omissão, os próximos 30 dias): previsão por semana, prestações a vencer dia a dia com o contacto, e vencidas por cobrar.
2. **Antiguidade dos atrasos** (a uma data): faixas de 1–7, 8–30, 31–60, 61–90 e mais de 90 dias, mais a lista por contrato com contacto, último pagamento e operador.
3. **Desempenho por gestor de crédito** (período): por utilizador que registou o contrato (`usuario` da primeira prestação). Mostra desembolsado, recebido, juros e multas, contratos e clientes activos, carteira e % da carteira com mais de 30 dias.
4. **Evolução mensal** (ano): por mês, desembolsos, recebido, juros, multas, carteira no fim do mês, % da carteira com mais de 30 dias e Caixa.
5. **Caixa por conta** (período): por conta e por tipo de movimento (cobranças, desembolsos, transferências internas, outros). O tipo é deduzido da descrição.
6. **Mutuários para novo crédito**: clientes sem contratos em aberto, classificados assim:
   - Excelente: nenhuma prestação paga em atraso;
   - Bom: até 20% das prestações pagas em atraso;
   - Atenção: mais de 20%.

## Configurações da empresa (Definições, administrador)

Modelo do GEMIC, guardado em `cis_company_settings` (chave/valor). Instalação: `bash deploy/install-company-settings.sh`.

- **Informações gerais**: logótipo, nome, NUIT (9 dígitos), licença BM, início de actividade, capital inicial, funcionários, província/distrito, morada, contactos, email e proprietário. Usado no cabeçalho dos extractos (PDF) e dos relatórios (Documento).
- **Cobrança e multa**: limite mensal de dias de mora, tolerância, penalidades automáticas, taxa diária de mora, carência, fins de semana, modalidade do calendário, lembrete e canal. Registado como política; a multa continua a ser calculada pela taxa do contrato e só é cobrada quando o operador escolhe.
- **Credit scoring**: score mínimo, limiares de baixo/médio risco, máximo de créditos activos e em atraso, taxa de esforço. Usado em «Mutuários para novo crédito» (score = % de prestações pagas sem atraso, −20 com crédito activo em atraso).
- **Incentivos**: por cliente novo, por renovação, % do capital vigente e % dos juros recebidos. Usado em «Desempenho por gestor de crédito» (coluna «Incentivo estimado»).

## Definições: navegação e parametrização

As Definições do administrador têm uma lista lateral (como nos Relatórios): Empresa (Informações gerais, Cobrança e multa, Credit scoring, Incentivos), Parametrização (Tabela de preços, Tipos de movimento) e Sistema (Aparência, Ligação ao servidor). Os outros utilizadores vêem só a Aparência.

- **Tabela de preços** (`cis_tabela_juros`): uma linha por faixa (De/Até MT) com a taxa de 15 e de 30 dias; taxa em branco = prazo indisponível. Recusa faixas invertidas, sobrepostas, sem taxa ou com taxa > 100%; assinala lacunas. Grava a tabela inteira numa transacção; só afecta simulações e contratos novos.
- **Tipos de movimento** (`cis_tipos_movimento` + grupo/activo/protegido): Entradas (Receita operacional, Entrada não operacional) e Saídas (Operação de crédito, Despesa operacional, Investimento, Saída não operacional). Criar, renomear, mudar de grupo, activar/desactivar; os tipos base (Receita de Juros, Desembolso de Empréstimo, Aumento de Crédito) estão protegidos. Os tipos activos são a lista da «Entrada / saída» da Caixa.
- Cada alteração fica em `cis_parametrizacao_log` (antes/depois, utilizador, data).

## Glossário (linguagem de actuação)

Os nomes que o utilizador vê (ecrãs, documentos, relatórios e mensagens de erro da API) seguem o glossário de microcrédito (ver `frontend/src/glossario.ts` e Definições › Glossário). Só muda o texto: as colunas e os valores gravados na base (ex.: `Valor de Multa`, `Pagamento Atrasado`) mantêm-se.

| Antes | Agora |
|---|---|
| Cliente / Clientes (menu, cadastro, contratos, relatórios, mensagens) | Mutuário / Mutuários |
| Quantia creditada / Total creditado | Capital desembolsado |
| Valor a pagar | Valor da prestação |
| Parcela | Prestação |
| Multa | Juros de mora |
| Data de cobrança | Data de vencimento |
| Modalidade de pagamento | Periodicidade |
| Estratégia de cálculo | Sistema de amortização |
| Plano de prestações | Plano de pagamentos |
| Em dívida | Saldo em dívida |
| Vencidas / Pagamento atrasado | Em atraso |
| Pendente / Finalizado (contrato) | Em curso / Liquidado |
| Pago / Pago em atraso / Renovado (prestação) | Paga / Paga com atraso / Renovada |
| Pagamento integral | Liquidação da prestação |
| Renovar empréstimo | Renovação |
| Carteira em risco > 30 dias | Carteira em risco a 30 dias (PAR 30) |
| Operador | Gestor de crédito |
| Agenda de cobranças | Folha de cobrança |
| Extracto do cliente | Extracto de conta |
| Taxa de juro (%) | Taxa de juro (% por período) |

## Numeração anual dos contratos

Formato `PREFIXO-ANO-SEQUÊNCIA` (ex.: `CAPTA-2026-0001`), com a sequência a recomeçar em 1 em cada ano, pelo **ano do desembolso** (primeira `Data de Inicio` do contrato). O prefixo e os dígitos ficam em Definições › Numeração dos contratos, que mostra a pré-visualização por ano.

- **Renumeração dos contratos existentes** (desde o primeiro ano, ex.: 2023): `bash deploy/renumber-contracts.sh`. Mostra o plano, pede `RENUMERAR`, faz cópia de segurança (`backups/antes-renumeracao-*.sql.gz`), e actualiza numa só transacção: `Contractos.Nr do Contracto`, `Extracto.Contracto` e o número dentro da `Descrição`, `cis_message_log`. Ordem dentro do ano: data de desembolso e, no mesmo dia, ordem de registo. Contratos sem data mantêm o número.
- O **histórico imutável** (`cis_contractos_historico`) não é alterado: a tabela `cis_contract_numbers` liga cada número antigo ao actual, e o histórico, a ficha do contrato, a pesquisa e o Extracto aceitam o número antigo.
- Depois da renumeração, os **novos contratos** recebem o próximo número do ano do desembolso. Antes dela, continuam com `C-0000`.
- Mudar o prefixo depois só afecta os novos contratos, até se voltar a correr o script.

## Numeração anual dos recibos

Recibos `PREFIXO-ANO-SEQUÊNCIA` (ex.: `REC-2026-0001`), a recomeçar em 1 em cada ano pela **data do pagamento**; prefixo em Definições › Numeração dos contratos (os dígitos são os mesmos dos contratos). Tabela `cis_receipts` (chave estável: `E:<Nr do Extracto>` ou `P:<Ordem>` para pagamentos antigos sem movimento). Na primeira consulta de recibos todo o histórico é numerado por ordem cronológica; depois, cada novo recebimento recebe o próximo número do ano e o número não muda.
