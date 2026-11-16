# CAPTA — Implementação segura da Caixa com Extracto.account_id

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
