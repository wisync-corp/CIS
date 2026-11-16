# CAPTA — Caixa Geral: instalação pendente do modelo híbrido

**Decisão confirmada:** a Caixa padrão utiliza `Extracto` como **fonte única dos lançamentos**, acrescentando apenas `cis_cash_accounts` (contas) e `cis_cash_links` (associação de Extracto.Nr a conta). A Tesouraria é opcional e disponibiliza reconciliação bancária e gestão avançada **sem criar outro livro de movimentos**.

**Arquitectura detalhada:** `docs/cash-hybrid-treasury-architecture.md`.

## Estado actual
- Código e interface actualizados no GitHub; **nenhuma migração aplicada pelo assistente na VPS**.
- A Caixa é consulta apenas até homologar o novo processo; criação de movimentos e transferências pelo fluxo antigo é bloqueada com HTTP 503.
- Abertura de crédito com desembolso continua bloqueada até ligar `Contractos + Extracto + cis_cash_links` numa transacção atómica e validar a conta/saldo/permissão.
- Nenhum movimento ou saldo fictício foi introduzido.
- Os saldos iniciais carecem de evidência documental e data de corte por conta; não atribuir o legado automaticamente.

## Antes de executar qualquer migração

1. **Não aplicar a migração anterior** do modelo independente com `cis_cash_movements`. O mesmo nome de ficheiro foi revisto para a arquitectura híbrida.
2. Confirmar **backup restaurável** e testar numa **cópia** da base real; não executar em produção por mera indicação deste documento.
3. Consultar `information_schema.TABLES` para identificar instalações anteriores de `cis_cash_accounts`, `cis_cash_links` ou `cis_cash_movements`. Se alguma existir, **parar** e definir migração de transição sem eliminar dados.
4. Confirmar a estrutura de `Extracto.Nr`, `Extracto.Valor`, as restrições, a engine InnoDB e o impacto dos escritores legados externos.
5. Validar as regras de associação de movimentos históricos `counts_after_opening=false`, evitando dupla contagem.
6. Só depois homologar a migração isolada; não executar `php artisan migrate` sem `--path` na base legada.

## Actualização do frontend sem activação financeira

```bash
cd /opt/cis
git pull origin main
bash deploy/refresh-private-preview.sh
```

A interface poderá manter aviso **"O esquema da Caixa ainda não foi instalado"** enquanto as duas tabelas híbridas não forem instaladas.

## Migração isolada — apenas após validação e autorização expressa

O ficheiro de migração continua a chamar-se:

`backend/database/migrations/2026_11_16_000030_create_cis_cash_tables.php`

Agora cria **`cis_cash_accounts` + `cis_cash_links`**, não cria `cis_cash_movements`.

**Não usar um comando de instalação de migração em produção antes de executar o pré-voo acima e testar em cópia.** Se existir tabela anterior ou parcial, o código bloqueia a migração em vez de sobrescrever dados.

## Próximas tarefas antes de operações reais

- Cadastro da conta e abertura documental com validação das permissões por utilizador.
- Registo de despesas e receitas gerais pelo Extracto + associação, atomicamente.
- Transferências com duas linhas Extracto e duas ligações com `operation_id` comum.
- Integração de desembolsos e cobranças usando conta obrigatória.
- Permissões híbridas, controlo de reserva, correcção/estorno e testes concorrentes.
- Módulo Tesouraria futuro: importação de extractos, conciliação bancária, planeamento e pré-contabilidade. Não duplicar movimentos.

**Nota:** a flag `CIS_CASH_WRITES_APPROVED` NÃO permite reactivar os endpoints de movimentos do fluxo antigo; esses endpoints foram explicitamente bloqueados enquanto o backend híbrido não estiver pronto.
