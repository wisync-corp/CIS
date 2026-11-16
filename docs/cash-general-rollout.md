# CAPTA — Implementação segura da Caixa com Extracto.account_id

**Arquitectura aprovada:** criar apenas `cis_cash_accounts` e acrescentar `Extracto.account_id` nullable. Não criar `cis_cash_links` nem `cis_cash_movements`. Ver `docs/cash-hybrid-treasury-architecture.md` para pormenores.

## Estado de entrega
- Migração e consultas do backend actualizadas no GitHub; **não executadas na VPS**.
- O painel da Caixa continua em modo de consulta. Os endpoints de movimentos e transferências devolvem 503 até implementar gravação atómica e autorizações.
- Os contratos existentes e o Extracto original permanecem intactos. Novos desembolsos continuam bloqueados pela protecção de segurança já implementada.
- Os saldos históricos não serão atribuídos automaticamente às contas.

## Pré-voo obrigatório na VPS (apenas leitura)

Antes de aplicar qualquer migração, efectuar backup restaurável e ensaio numa **cópia** da base. Verificar:
1. Que `cis_cash_accounts`, `cis_cash_links` e `cis_cash_movements` não estão instaladas;
2. Que `Extracto.account_id` não existe; se existir, **interromper** e estudar migração diferencial sem destruir dados;
3. Que `Extracto.Nr` é a PK esperada e a tabela é InnoDB;
4. Que os registos antigos não dependem de regras implícitas de valores nulos;
5. Que todas as aplicações externas que escrevem em `Extracto` toleram uma nova coluna nullable;
6. Que existe uma janela de manutenção e um plano de recuperação caso o DDL do MySQL termine parcialmente.

**Não reutilizar o procedimento anterior para a versão de duas tabelas.** O ficheiro tem o mesmo nome histórico, mas a definição foi alterada.

## Ficheiro de migração proposto

`backend/database/migrations/2026_11_16_000030_create_cis_cash_tables.php`

Executar *somente depois* de ensaio e aprovação expressa de alteração da base produtiva. Não usar `php artisan migrate` sem `--path`: há outras migrações do legado com dependências próprias.

## Caixa e Tesouraria

Caixa (padrão): gerir contas, saldos, entradas, saídas, transferências e comprovativos operacionais, após homologação dos lançamentos.

Tesouraria (opcional): importar extractos externos, reconciliar movimentos, identificar diferenças, projectar liquidez e produzir relatórios pré-contabilísticos. Utiliza **as mesmas contas e o mesmo Extracto**, sem novos registos monetários duplicados.

A política de desembolsos directos ou com aprovação depende do utilizador e das permissões institucionais, **não** da activação da Tesouraria.
