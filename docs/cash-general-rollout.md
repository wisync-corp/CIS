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
