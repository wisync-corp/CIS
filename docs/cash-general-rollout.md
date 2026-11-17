# CAPTA — Implementação segura da Caixa com Extracto.account_id

## Fase seguinte: cadastro seguro de contas (sem operações financeiras)

A interface e a API foram adaptadas para que o **cadastro administrativo das contas** seja activado separadamente das movimentações. A opção `CIS_CASH_ACCOUNT_SETUP_APPROVED` (inicialmente `false`) só controla `POST /api/cis/caixa/contas` e **não autoriza** saldo inicial, activação da conta, desembolso, cobrança, transferências ou receitas/despesas.

O cadastro exige sessão de **administrador activo**, aprovação em variável de ambiente e credenciais MySQL próprias. A conta `cis_cash_setup` recebe **somente SELECT, INSERT na tabela `cis_cash_accounts`**; nenhuma permissão de escrita na tabela `Extracto`. Contas novas nascem `em_preparacao`, sem saldo, sempre inactivas para movimentos.

**Antes de activar:** aplicar a migração correctiva e confirmar a estrutura por `deploy/postflight-cash-direct-account.sh`. A execução do ensaio isolado não demonstra que o servidor operacional foi corrigido.

**Activação voluntária em janela supervisionada, somente após corrigenda confirmada:**

```bash
cd /opt/cis
git pull origin main
bash deploy/postflight-cash-direct-account.sh
bash deploy/activate-cash-account-registration.sh
```

O script de activação executa verificações de esquema, cria um utilizador MySQL com privilégios limitados, guarda a credencial apenas em `deploy/.env` privado, solicita `ACTIVAR-CADASTRO-CAIXA` e reconstrói a pré-visualização. **Não insere contas de exemplo**. Para cadastrar contas reais, entrar com uma sessão de administrador e ir a **Caixa → Contas e saldos → Nova conta**.

**Não activar `CIS_CASH_WRITES_APPROVED`.** A abertura/validação de saldos exige controlo documental e segregação de funções; os fluxos financeiros permanecem bloqueados.


## Estado real após instalação com imagem antiga (16/11/2026)

**Diagnóstico confirmado no servidor pelo utilizador:** a migração histórica `2026_11_16_000030_create_cis_cash_tables` aparece como executada, mas foi executado o esquema antigo da imagem Docker. Existem `cis_cash_accounts` e `cis_cash_movements`, **ambas vazias**; `Extracto.account_id` não existe. Os 1.295 movimentos do Extracto e 680 registos de Contractos continuam com as contagens esperadas. O processo **não** deve ser repetido ou revertido automaticamente.

Existe agora uma **migração correctiva NOVA** (`2026_11_17_000032_repair_legacy_cash_schema_to_direct_accounts.php`), que falha caso encontre registos nas tabelas antigas; em ambiente homologado, adiciona `opening_extracto_nr` a `cis_cash_accounts`, cria `Extracto.account_id` nullable e remove **apenas a tabela vazia** `cis_cash_movements`. A migração 000030 original fica registada, sem ser reexecutada.

**Próximo passo: ensaio numa cópia actual do banco, depois da instalação antiga; NÃO na produção.**

```bash
cd /opt/cis
git pull origin main
bash deploy/backup-before-auth.sh
bash deploy/rehearse-cash-repair.sh
```

O último comando utiliza o backup privado mais recente; confirma que ele contém exactamente o esquema antigo e ambas as tabelas vazias, executa só a corrigenda numa instância MySQL descartável, compara as contagens e soma dos valores financeiros, e destrói os recursos de ensaio.

**Só após aprovar o ensaio e rever o resultado** preparar um comando de instalação real da corrigenda com autorização e janela de manutenção. **Não executar agora** `migrate`, `rollback`, `DROP TABLE` ou `install-cash-direct-account.sh`.


**Arquitectura aprovada:** criar apenas `cis_cash_accounts` e acrescentar `Extracto.account_id` nullable. Não criar `cis_cash_links` nem `cis_cash_movements`. Ver `docs/cash-hybrid-treasury-architecture.md` para pormenores.

## Estado de entrega
- Migração e consultas do backend actualizadas no GitHub; **não executadas na VPS**.
- O painel da Caixa continua em modo de consulta. Os endpoints de movimentos e transferências devolvem 503 até implementar gravação atómica e autorizações.
- Os contratos existentes e o Extracto original permanecem intactos. Novos desembolsos continuam bloqueados pela protecção de segurança já implementada.
- Os saldos históricos não serão atribuídos automaticamente às contas.

## Primeiro passo autorizado: diagnóstico sem alteração de dados

Na VPS, após obter a versão actual do código, executar exclusivamente:

```bash
cd /opt/cis
git pull origin main
bash deploy/preflight-cash-direct-account.sh
```

Este procedimento **não executa migrações nem altera dados**. Mostra apenas o esquema, a compatibilidade da tabela `Extracto`, a existência de tabelas antigas e o estado de migração. A saída termina em **PRE-VOO ESTRUTURAL OK** quando os requisitos estruturais são satisfeitos. Qualquer resultado **BLOQUEADO** exige análise antes de prosseguir.

O preflight não constitui autorização de migração e não verifica, por si só, a qualidade do backup, o restauro nem possíveis escritores externos. **Não executar a migração produtiva apenas por este resultado.**

## Pré-voo obrigatório na VPS (apenas leitura)

Antes de aplicar qualquer migração, efectuar backup restaurável e ensaio numa **cópia** da base. Verificar:
1. Que `cis_cash_accounts`, `cis_cash_links` e `cis_cash_movements` não estão instaladas;
2. Que `Extracto.account_id` não existe; se existir, **interromper** e estudar migração diferencial sem destruir dados;
3. Que `Extracto.Nr` é a PK esperada e a tabela é InnoDB;
4. Que os registos antigos não dependem de regras implícitas de valores nulos;
5. Que todas as aplicações externas que escrevem em `Extracto` toleram uma nova coluna nullable;
6. Que existe uma janela de manutenção e um plano de recuperação caso o DDL do MySQL termine parcialmente.

**Não reutilizar o procedimento anterior para a versão de duas tabelas.** O ficheiro tem o mesmo nome histórico, mas a definição foi alterada.

## Ensaio seguro após o backup

Depois de criar o backup, **não aplicar a migração em produção**. Executar o ensaio automatizado que restaura a cópia numa **rede Docker interna, volume temporário e container MySQL separados**. A migração Laravel é executada **apenas no restauro isolado**.

```bash
cd /opt/cis
git pull origin main
bash deploy/rehearse-cash-migration.sh
```

O script selecciona o backup `cis-before-auth-*.sql` mais recente da pasta privada `$HOME/cis-private/backups`. Também aceita o caminho explícito como argumento. Necessita de Docker, OpenSSL e espaço disponível para um MySQL temporário. Não publica portas e elimina a infraestrutura temporária ao terminar, com sucesso ou erro.

Verificações efectuadas: restauro completo, execução da migração Laravel real, manutenção das quantidades de Contractos, manutenção da quantidade e soma financeira de Extracto, criação de `cis_cash_accounts`, inclusão de `Extracto.account_id` nullable e **zero movimentos históricos com conta atribuída**.

Resultado esperado: `ENSAIO DE RESTAURO E MIGRACAO: APROVADO (somente ambiente isolado).`

**Importante:** esta validação não verifica toda a compatibilidade de escritores externos (Excel/VBA) nem substitui janela de manutenção e autorização de alteração produtiva.

## Instalação controlada após ensaio aprovado (alteração real; requer confirmação no terminal)

O cliente confirmou que CAPTA é o único escritor do `Extracto`. O teste de restauro e migração isolados foi concluído com preservação de **1.295** linhas no `Extracto` e **680** registos em `Contractos`. Isto permite preparar a janela de manutenção, **mas não equivale a aprovar automaticamente alterações produtivas**.

Disponível o instalador supervisionado:

```bash
cd /opt/cis
git pull origin main
bash deploy/install-cash-direct-account.sh
```

O script efectua pré-voo de leitura, gera novo backup, solicita confirmação textual `MIGRAR-CAIXA`, pára temporariamente a API, compara a contagem e soma de Extracto, executa **apenas** a migração do esquema de Caixa e verifica que os movimentos e contratos não foram alterados. Reinicia a API ao sair, inclusive se ocorrer erro. O comando usa a conta MySQL configurada no serviço Laravel, que **precisa de privilégios `CREATE` e `ALTER`**. Um erro de permissão não autoriza alteração manual sem revisão.

**Antes de confirmar no terminal:** garantir acesso de manutenção, indisponibilidade temporária da API aceite, ausência de outras sessões/escritores, backup testado e autorização de alteração. Se algo não estiver confirmado, digitar qualquer coisa diferente de `MIGRAR-CAIXA` e cancelar.

Não repetir automaticamente após erro: o MySQL pode deixar DDL parcialmente aplicado. Inspeccionar o esquema e preparar recuperação específica. O instalador não activa gravação de novos movimentos; a Caixa permanece protegida.

## Ficheiro de migração proposto

`backend/database/migrations/2026_11_16_000030_create_cis_cash_tables.php`

Executar *somente depois* de ensaio e aprovação expressa de alteração da base produtiva. Não usar `php artisan migrate` sem `--path`: há outras migrações do legado com dependências próprias.

## Caixa e Tesouraria

Caixa (padrão): gerir contas, saldos, entradas, saídas, transferências e comprovativos operacionais, após homologação dos lançamentos.

Tesouraria (opcional): importar extractos externos, reconciliar movimentos, identificar diferenças, projectar liquidez e produzir relatórios pré-contabilísticos. Utiliza **as mesmas contas e o mesmo Extracto**, sem novos registos monetários duplicados.

A política de desembolsos directos ou com aprovação depende do utilizador e das permissões institucionais, **não** da activação da Tesouraria.


## Fase 2 — abertura e validação independente de saldos (17/11/2026)

**Estado do código:** a Caixa padrão já possui cadastro real de contas e agora inclui preparação de saldo inicial e validação por **segundo administrador**, independentes de transacções monetárias. A implementação foi criada no GitHub, **mas não deve ser confundida com activação na base operacional**.

### Fluxo com segregação de funções

1. Um administrador cadastra a conta (`status=em_preparacao`), sem saldo e sem lançamento financeiro.
2. **O mesmo responsável pelo cadastro** pode preencher a proposta de abertura (saldo em MT, data/hora de corte de Maputo e referência documental real). `status` muda para `pendente_validacao`, sem saldo disponível.
3. **Outro administrador activo**, com ID de utilizador distinto do `created_by`, confere a evidência, o montante e a data de corte. A confirmação da aprovação actualiza `validated_by`, `validated_at`, `opening_extracto_nr=max(Extracto.Nr)` e `status=activa`. Estes campos constituem o trilho mínimo de quem aprovou, não um arquivo digital do documento.
4. A conta activa expõe o saldo inicial e movimentos com `Extracto.account_id` e `Nr` maior do que o marcador, evitando atribuir movimentos históricos sem origem à conta. **Não existe escrita em Extracto nas operações de abertura.**
5. A proposta/validação falha se já houver movimentos vinculados à conta, os dados estiverem incompletos, o estado for incompatível ou o próprio criador tentar aprovar.

Cada fase usa uma credencial MySQL distinta com `SELECT` e `UPDATE` **somente nas colunas da tabela cis_cash_accounts** que a respectiva função necessita:
- `cis_cash_open_submit`: `opening_balance`, `cut_at`, `evidence_reference`, `status`, `updated_at`.
- `cis_cash_open_validate`: `opening_extracto_nr`, `validated_by`, `validated_at`, `status`, `updated_at`.

Nenhum destes dois utilizadores MySQL pode inserir/actualizar/apagar movimentos de `Extracto`.

### Execução controlada da fase de abertura (somente se houver segundo aprovador)

```bash
cd /opt/cis
git pull origin main
bash deploy/postflight-cash-direct-account.sh
bash deploy/audit-cash-readiness.sh
bash deploy/activate-cash-openings.sh
bash deploy/verify-cash-openings.sh
```

O instalador exige a confirmação textual `PREPARAR-CAIXA`, faz um backup privado, cria duas credenciais restritas e actualiza exclusivamente a API privada. É **opcional** e não deve ser repetido após um erro parcial. As contas já cadastradas são preservadas. Não foi criada tabela de movimentos nem foi feita migração.

**Critérios de aceitação manual antes de utilizar saldos reais:**
- Testar duas contas de administrador distintas, A e B, numa conta real com comprovativo válido (sem valores de teste em produção).
- A prepara uma abertura, mas não consegue aprová-la.
- B consegue ver a proposta, conferir o comprovativo e aprovar; a conta passa a `activa`.
- A e B não conseguem lançar entradas, saídas, cobranças, transferências ou desembolsos por estes endpoints.
- As contagens e os valores do `Extracto` e `Contractos` anteriores à activação continuam inalterados.

### Pendências materiais para Caixa Geral plenamente operacional

**Não activar `CIS_CASH_WRITES_APPROVED`.** As funções `record()` e `transfer()` continuam a responder com HTTP 503, e as cobranças/desembolsos permanecem bloqueadas. Para autorizar movimentos reais ainda é necessário:

1. **Idempotência persistente e verificável** para impedir duplicação quando a rede falha após o commit. A arquitectura aprovada tem somente uma tabela nova e uma coluna `Extracto.account_id`; ainda não existe no esquema um campo único para uma chave de operação. Não inventar persistência paralela ou acrescentar outra coluna sem decisão explícita.
2. Escrita InnoDB atómica e testes de concorrência para saídas e transferências (2 linhas em Extracto), controlo de saldo por conta, rollback e ordem de locks.
3. Matriz de permissões por utilizador, conta, tipo de operação e limite monetário; dupla aprovação para operações acima dos limiares aprovados.
4. Regras definitivas de comprovativo, reconciliação de depósitos, estorno/reversões e limitação de movimento retroactivo.
5. Integração de desembolsos e cobranças de contratos com `Contractos` + `Extracto.account_id` na mesma transacção, sem substituir a lógica legal/contratual das prestações.

**A fase 2 não constitui conclusão dessas pendências.** A Tesouraria premium permanece opcional, mas estas garantias da Caixa padrão são obrigatórias antes de mover dinheiro.


## Decisão actualizada — administrador único (17/11/2026)

Por decisão expressa do responsável pela instituição, o CAPTA deve permitir que **um único administrador activo** cadastre contas, prepare o saldo inicial e confirme documentalmente a sua abertura.

A opção é **explícita e desactivada por omissão**: `CIS_CASH_SINGLE_ADMIN_APPROVED=true`, configurada apenas pelo instalador `deploy/activate-cash-openings.sh` após confirmação `ADMIN-UNICO-CAIXA`.

A confirmação exige o comprovativo real com referência, a data de corte, o valor e a frase de confirmação `CONFIRMO-SALDO-REAL`. O campo `validated_by` regista o próprio administrador; **isto não é uma validação independente**, facto que a interface e o relatório de auditoria devem declarar. O sistema mantém duas identidades **técnicas MySQL** de permissões limitadas para preparar e confirmar a abertura, sem exigir duas pessoas nem conceder acesso ao Extracto.

O instalador anteriormente exigia dois administradores activos; essa exigência foi retirada. O script de diagnóstico permite uma aprovação pelo criador apenas quando o modo explícito estiver activo e comunica essa ausência de segregação humana.

**Continua proibido** activar cobranças, desembolsos, transferências ou entradas/saídas de caixa. Alterar o modelo de aprovação de saldo não fornece permissões para escrituração financeira.


## Etapa seguinte — simulação de movimentos e transferências (17/11/2026)

Foi preparada uma camada de **prévia financeira somente leitura** sem alterar o modelo de dados aprovado. A interface da Caixa Geral dispõe de **Simular entrada/saída** e **Simular transferência**, na secção Livro de Movimentos. As duas operações chamam endpoints autenticados e limitados por frequência:

- `POST /api/cis/caixa/simular-movimento`: exige conta activa em MZN, natureza `entrada` ou `saida`, montante positivo com duas casas, e saldo suficiente nas saídas. Devolve saldo actual e estimado.
- `POST /api/cis/caixa/simular-transferencia`: exige duas contas activas diferentes em MZN, montante positivo e saldo suficiente na origem. Devolve os dois saldos actuais e os dois saldos estimados.

A API **não executa `INSERT`/`UPDATE`**, não reserva saldo e não cria recibos. Os cálculos são reexecutados no servidor a partir de `cis_cash_accounts` e `Extracto.account_id`; o resultado é provisório e poderá ficar desactualizado caso ocorram movimentos por outra via. O `CashController::record()` e `::transfer()` continuam bloqueados com HTTP 503, independentemente do estado da interface.

**Verificação e teste:** `backend/tests/cash_operation_preview_smoke.php` inspeciona rotas autenticadas e cálculos usando SQLite efémero com `ROLLBACK`, sem tocar no MySQL da VPS. Não faz lançamento real.

### Decisão de esquema ainda pendente

Para concluir a **execução real**, é necessária uma chave de idempotência persistente e única no Extracto, preferencialmente `operation_key` nullable com índice UNIQUE, utilizada em novos lançamentos e transferências. Isto **acrescentaria uma segunda coluna à tabela Extracto**, além de `account_id`; por isso **não foi executado sem aprovação explícita**. A chave deverá ser persistida dentro da mesma transacção InnoDB das linhas financeiras. Numa transferência de duas linhas, o modelo de chave por operação deve distinguir os dois lados sem reutilizar o mesmo valor UNIQUE, por exemplo com sufixos determinísticos validados pelo servidor.

As restantes condições para activação são: ensaio de migração em restauro isolado, backup, permissões mínimas MySQL de INSERT Extracto, bloqueio de saldo na mesma transacção, validação de operações duplicadas, limites e aprovação por conta, comprovativos e estorno auditável. Estas capacidades não foram activadas nesta etapa.

### Verificação operacional antes de testar a interface

```bash
cd /opt/cis
git pull origin main
bash deploy/verify-cash-openings.sh
bash deploy/audit-cash-readiness.sh
bash deploy/refresh-private-preview.sh
```

Após actualizar, no CAPTA, entre em `Caixa Geral → Livro de movimentos` e experimente as simulações com contas realmente activas. Se existir apenas uma conta activa, a prévia de transferência precisa de outra conta real; não criar conta fictícia para testar. A fase de abertura com administrador único não implica necessariamente que o saldo inicial já tenha sido proposto e confirmado; verificar `status=activa` antes de simular.


## Decisão aprovada — operação única persistente em Extracto (17/11/2026)

O utilizador **autorizou a inclusão da coluna `operation_key`**. Foi criada a migração **NOVA e aditiva**
`backend/database/migrations/2026_11_17_000050_add_operation_key_to_extracto.php`, sem modificar migrações já registadas.

- Campo `Extracto.operation_key`: `VARCHAR(120) NULL`, índice `UNIQUE cis_extracto_operation_key_uq`.
- `NULL` em todas as linhas históricas: nunca gerar artificialmente chaves em movimentos antigos.
- Não se adiciona nenhuma tabela. `cis_cash_accounts` e `Extracto.account_id` continuam intactos.
- A chave deve ser obrigatória **na API para qualquer lançamento NOVO da Caixa**, antes de activar escritas.
- Transferências terão duas chaves distintas e correlacionáveis (`...:source` e `...:destination`), ambas na mesma transacção, para respeitar o UNIQUE por linha.
- O índice UNIQUE é condição necessária, **não suficiente**, para impedir duplicações: antes da escrita real faltam ainda replay sem efeitos repetidos, locks e transacção completa, autorização e prova documental.

**Scripts de segurança criados:**
`deploy/rehearse-cash-operation-key.sh` — restaura o backup privado em MySQL Docker isolado, executa SOMENTE a nova migração, compara contagens/somas do Extracto, contratos e contas, verifica coluna/index e elimina recursos temporários.

`deploy/install-cash-operation-key.sh` — preflight e conferência dos dados, compila imagem atual, verifica SHA do ficheiro de migração dentro da imagem, exige confirmação literal `ADICIONAR-OPERATION-KEY`, efectua backup privado, pára apenas API privada, confirma novamente e executa SOMENTE esta migração; reinicia API e faz postflight. MySQL DDL não é automaticamente reversível.

`deploy/verify-cash-operation-key.sh` — diagnóstico posterior SOMENTE LEITURA.

**Sequência sugerida na VPS (não executar instalador antes de ensaio aprovado):**

```bash
cd /opt/cis
git pull origin main
sudo -v
bash -n deploy/rehearse-cash-operation-key.sh
bash -n deploy/install-cash-operation-key.sh
bash deploy/backup-before-auth.sh
bash deploy/rehearse-cash-operation-key.sh
# PROSSEGUIR APENAS se o ensaio isolado for aprovado e sem outros escritores:
bash deploy/install-cash-operation-key.sh
bash deploy/verify-cash-operation-key.sh
```

**A instalação ainda não foi executada no servidor real por este desenvolvimento.**
As rotas de `record()` e `transfer()` continuam a devolver HTTP 503. Não activar `CIS_CASH_WRITES_APPROVED` com base apenas na presença da nova coluna. O passo seguinte será implementar e testar lançamentos idempotentes atomicamente e a integração com cobranças/desembolsos.


## Fase 3 — movimentos reais de Caixa Geral (17/11/2026)

**Código implementado, ainda não activado na VPS operacional.** Os endpoints `POST /api/cis/caixa/movimentos` e `POST /api/cis/caixa/transferencias` estão agora encaminhados para `CashPostingController`. A API só permite gravação se **ambas** as flags `CIS_CASH_WRITES_APPROVED` e `CIS_CASH_LEDGER_POSTING_APPROVED` estiverem `true`, além de `CIS_PRIVATE_PREVIEW`. Por omissão ambas permanecem `false`.

### Invariantes do lançamento

- **Somente administrador activo** na pré-visualização privada; no futuro, substituir por matriz fina de funções.
- **Conta activa com saldo inicial documentado, marcador histórico e moeda MZN**.
- **Contas bloqueadas com `SELECT FOR UPDATE`**, sempre por ID crescente em transferências, usando a mesma transacção InnoDB para saldo e INSERT.
- **`Extracto.operation_key` obrigatória e UNIQUE**, chave raiz UUID fornecida pelo browser, duas pernas distintas `:1` e `:2` na transferência. Retry com mesmo pedido devolve referência original; mesma chave com valores diferentes devolve conflito.
- **Saldo nunca negativo** na saída e na origem da transferência. Entrada só com referência documental.
- **Transferência interna**: exactamente duas linhas ('Saídas' e 'Entradas'), associadas às duas contas, com `Nr` diferentes, em **uma transacção atómica**, sem ser tratada como receita/despesa externa.
- **Campos legados preservados**: `Cliente='CAIXA GERAL'`, `Contracto=''`, `Parcela=0`, `usuario=ID`, `Data da Operação` local de Maputo. Categoria e referência documental ficam codificadas na descrição; é necessária futura evolução do modelo para trilho documental mais rico.
- **O Extracto é sempre o livro único**. Nunca actualizar/apagar movimentos; o utilizador técnico não deve receber privilégios UPDATE/DELETE no Extracto.
- **Prévia separada da confirmação** na interface; o botão de registo real só aparece com ambas as flags autorizadas.

### Validadores automáticos disponíveis

`backend/tests/cash_real_posting_smoke.php`: entradas/saídas, saldo insuficiente, replay, payload alterado, transferência atómica e **falha injectada na segunda perna com rollback**; SQLite CI descartável.

`backend/tests/cash_real_mysql_rehearsal.php`: insere saídas e transferências reais em **restauro MySQL temporário**, testa replay de ambos e executa `ROLLBACK` integral. Só corre se `DB_HOST=cash-real-mysql` e `CIS_ISOLATED_REHEARSAL=true`.

`deploy/rehearse-cash-real-posting.sh`: restaura backup privado, cria rede/volume/MySQL temporários, testa código actualizado, compara movimentos/contas/contratos e elimina recursos. Produz prova privada por hash do código ensaiado apenas após sucesso.

### Sequência segura de preparação e activação

```bash
cd /opt/cis
git pull origin main
sudo -v
bash deploy/verify-cash-operation-key.sh
bash deploy/backup-before-auth.sh
bash deploy/rehearse-cash-real-posting.sh
# Continuar somente se o ensaio no MySQL isolado for aprovado.
bash deploy/prepare-cash-ledger-writer.sh
bash deploy/verify-cash-ledger-writer.sh
# Activação real requer pelo menos 1 conta activa, 0 contas em preparação,
# comprovativos reais, acesso administrativo e autorização expressa:
bash deploy/activate-cash-real-posting.sh
```

**ATENÇÃO:** `prepare-cash-ledger-writer.sh` cria apenas o utilizador MySQL restrito com `SELECT` em contas, `UPDATE(updated_at)` em contas para permitir `SELECT FOR UPDATE`, e `SELECT/INSERT` no `Extracto`. Ambas as flags de gravação continuam **falsas** após a preparação.

O comando `activate-cash-real-posting.sh` só deve ser executado depois da prova de ensaio MySQL e da conferência real dos saldos/comprovativos. Ele verifica o hash do código, o esquema, as permissões e a existência de contas activas sem propostas pendentes, faz backup e exige a confirmação literal `ACTIVAR-CAIXA-REAL`. Não activa desembolsos nem cobranças contratuais.

### Interrupção imediata

```bash
cd /opt/cis
bash deploy/disable-cash-real-posting.sh
```

Desactiva ambos os mecanismos de escrita e reinicia apenas a API, sem alterar os movimentos já confirmados.

### Limitações que permanecem nesta entrega

- **Cobranças e desembolsos contratuais não estão ligados à nova transacção financeira**: ainda proibidos.
- **Estornos, recibos, reconciliação e fecho de caixa** exigem fases adicionais.
- Enquanto a escrita financeira está activa, **novas propostas de saldos de abertura ficam bloqueadas** por segurança; é necessário implementar novo fluxo concorrente de abertura antes de voltar a permitir novos cadastros com activação.
- A tabela `Extracto` legada não tem colunas separadas para comprovativo/categoria/identidade de transferência; a informação vai na descrição e está limitada. Isto não equivale a um arquivo documental.
- A escrita real está condicionada a **ensaios de concorrência MySQL** e verificação dos privilégios em VPS; CI em SQLite, por si só, não demonstra compatibilidade com o MySQL legado.

**Não considerar o módulo de Caixa integralmente concluído** antes destas limitações estarem homologadas.

## Fase 4 — Estornos imutáveis em homologação (17/11/2026)

**Implementação concluída no código e nas verificações SQLite, mas NÃO activada na operação real.** O endpoint de escrita usa a flag exclusiva `CIS_CASH_REVERSALS_APPROVED`, que vem **false por omissão no Docker Compose**. Mesmo com entradas e saídas gerais activadas, os estornos permanecem protegidos independentemente.

### Percurso da interface

`Caixa Geral → Movimentos → Ver → Analisar estorno` abre uma **página própria** com as pernas originais. A API fornece `GET /api/cis/caixa/estornos/{nr}/previsualizar` (somente leitura). Se a autorização não estiver activa, a interface apresenta a prévia, sem botão de lançamento. Quando homologado, o formulário exige motivo (10 a 80 caracteres), referência do comprovativo (6 a 65) e confirmação explícita. `POST /api/cis/caixa/estornos/{nr}` é restrito ao administrador e sujeito a `throttle:5,1`.

### Regras de escrituração

- Só originais `cash:<UUID>:1` ou pernas verificáveis `cash:<UUID>:1/2` de movimentos **gerais**, sem contrato/prestação. Histórico não associado, desembolsos e cobranças contratuais não podem ser estornados por este fluxo.
- O `Extracto` original não é editado ou apagado: o estorno cria uma linha de sentido contrário por conta com `operation_key` única **derivada do Nr original**, evitando estornos repetidos, mesmo que o utilizador tente outra referência.
- Para transferências, o estorno cria **duas linhas inversas** na mesma transacção InnoDB; a conta receptora original não pode ficar negativa após o débito de reversão.
- A API bloqueia as contas em ordem crescente e recalcula o saldo antes de inserir.
- O relatório de Fluxo de Caixa distingue estornos de transferências internas dos estornos de entradas/saídas externas.
- O formulário distingue **reversão contabilística** de **devolução efectiva via banco**: uma não prova automaticamente a outra. Exigir comprovativo real e verificação externa.

### Ensaios e próximos controlos

1. `backend/tests/cash_reversal_smoke.php`: SQLite efémero, com teste de falha injectada na segunda perna, prevenção de estornos duplicados, bloqueio por saldo e de estorno de estorno, e rollback integral.
2. `deploy/rehearse-cash-reversals.sh`: restaura backup privado ACTUAL numa base MySQL Docker isolada; `backend/tests/cash_reversal_mysql_rehearsal.php` faz INSERTs de ensaio em transacção que é revertida e compara contagens/somas depois.
3. O script de ensaio guarda hash do controlador em `~/cis-private/cash-verification/reversal-rehearsal.sha256` somente após aprovação. Este hash é evidência de teste, não uma autorização para activar a flag.
4. A **activação de estornos reais fica explicitamente adiada**, pendente de homologação do ensaio MySQL, análise do resultado, comprovativos e procedimento de autorizações. **Não editar manualmente deploy/.env** nem ligar `CIS_CASH_REVERSALS_APPROVED`.

Comandos seguros de preparação:

```bash
cd /opt/cis
git pull origin main
bash deploy/refresh-private-preview.sh
bash deploy/backup-before-auth.sh
bash deploy/rehearse-cash-reversals.sh
```

**Não executar qualquer estorno fictício na VPS operacional.** O ensaio é o próximo passo recomendado.


## Fase 5 — Fecho diário de controlo sem invadir Tesouraria (17/11/2026)

**Decisão aprovada:** o fecho diário faz parte da **Caixa Geral Padrão**. A Tesouraria Premium mantém as suas funcionalidades de importação e conciliação bancária, previsão e planeamento de tesouraria, liquidez e controlo de pagamentos. **Não foi implementado bloqueio de períodos** nem fecho contabilístico com impacto transaccional.

Foi criada **UMA tabela de controlo não financeiro** `cis_cash_daily_closures`, separada de `cis_cash_accounts` e do livro ÚNICO `Extracto`. A restrição histórica de uma tabela financeira nova mantém-se, pois este registo é documental, não duplica movimentos. **Nenhuma coluna de `Extracto` foi alterada.**

### Regras funcionais

- Fecho por **conta** e **dia**, chave `UNIQUE(account_id, closing_date)`; permanece imutável após gravação. Sem editar/apagar nem recriar fechos.
- O sistema calcula saldo anterior, entradas, saídas, saldo contabilístico esperado, quantidade de movimentos e marcador `ledger_max_nr` respeitando `opening_extracto_nr`.
- O administrador informa saldo realmente observado, método conforme tipo de conta (contagem física / extracto bancário / comprovativo de carteira), referência documental e justificação obrigatória se houver diferença.
- `difference_amount=observed_balance-expected_balance`; mesmo com divergência, **não** se lança regularização automática, **não** se altera o saldo financeiro ou se confirma reconciliação.
- **Não há fecho geral bloqueante**: fechar uma conta não bloqueia entradas, saídas, transferências ou outras contas. O fecho é uma fotografia auditável na data e hora, sujeita a novas movimentações; exige conciliação externa para aferir discrepâncias posteriores.
- Não é possível fechar datas futuras, nem antes da data de abertura. Fechos retroactivos de mais de 31 dias são bloqueados.
- Apenas o administrador activo pode consultar e confirmar. Escrita independente exige `CIS_CASH_DAILY_CLOSE_APPROVED=true`, **false por omissão** e utilizador MySQL `cis_cash_closures` com `SELECT` contas/Extracto, `INSERT/SELECT` na tabela de fechos e **sem UPDATE/DELETE em Extracto**.
- A página de fecho está **separada do Resumo** e é aberta por `Caixa Geral → Conferência Diária → Conferir saldos → Preparar`. O sistema distingue prévia, fecho já existente e fecho ainda bloqueado.

### Estado de implementação

1. Nova migração `2026_11_17_000060_create_cis_cash_daily_closures.php`, sem edição de migrações existentes.
2. `CashClosureController`: prévia `GET /api/cis/caixa/fecho-diario?date=YYYY-MM-DD&account_id=N` e gravação independente `POST /api/cis/caixa/fecho-diario`.
3. UI com formulário documental completo e comparação do saldo observado com o contabilístico. Fechos já gravados não podem ser repetidos.
4. Testes CI SQLite `cash_daily_closure_smoke.php`; teste MySQL restaurado `cash_daily_closure_mysql_rehearsal.php`, com ROLLBACK de contas e fechos temporários.
5. `deploy/rehearse-cash-daily-closures.sh` restaura backup actual em MySQL Docker **isolado**, instala tabela e ensaia POST, comparando valores de Extracto/Contractos/contas; só após sucesso guarda hash privado da migração.
6. `deploy/install-cash-daily-closure-schema.sh` faz preflight, exige esse hash, recompila e confere imagem, cria backup e **somente instala tabela**. Não activa fechos.
7. `deploy/prepare-cash-daily-closure-writer.sh` cria utilizador técnico com privilégios mínimos, mantém flag **false**, e prepara API. Ainda **não** deve ser executado sem validação do ensaio e da instalação.

### Sequência segura proposta (somente após verificações de CI aprovadas)

```bash
cd /opt/cis
git pull origin main
sudo -v
bash deploy/backup-before-auth.sh
bash deploy/rehearse-cash-daily-closures.sh
# Continuar APENAS com a mensagem ENSAIO DO FECHO DIÁRIO APROVADO:
bash deploy/install-cash-daily-closure-schema.sh
bash deploy/verify-cash-daily-closures.sh
# Depois, se a instalação e postflight forem aprovados:
bash deploy/prepare-cash-daily-closure-writer.sh
bash deploy/verify-cash-daily-closure-writer.sh
bash deploy/refresh-private-preview.sh
```

`verify-cash-daily-closure-writer.sh` (somente leitura) confirma que `cis_cash_closures` tem apenas SELECT em `Extracto`, SELECT/INSERT na tabela de fechos e `UPDATE(updated_at)` nas contas (necessário ao lock), sem privilégios globais ou de base; e testa, dentro da API e numa transacção revertida, o mesmo `lockForUpdate` do POST e a recusa de UPDATE no `Extracto`.

**Não activar ainda `CIS_CASH_DAILY_CLOSE_APPROVED`**. Primeiro validar no MySQL real as permissões e a consulta de fecho; só depois preparar activação específica com confirmação explícita. A Tesouraria não foi alterada por esta fase.
