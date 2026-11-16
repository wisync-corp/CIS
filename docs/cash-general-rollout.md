# CAPTA — Caixa Geral padrão: entrega inicial e activação segura

**Entrega:** código do módulo Caixa Geral, contas independentes, saldos de abertura com referência documental, diário de receitas/despesas gerais, transferências internas confirmadas e API privada.

**Estado operativo após git pull:** **DESACTIVADO PARA ESCRITAS** (`CIS_CASH_WRITES_APPROVED=false`). A aplicação pode apresentar o módulo Caixa em modo de consulta **após instalar apenas as novas tabelas**. Não há contas nem saldos de demonstração e não foram importados movimentos históricos.

## Limites desta etapa

- **Administração exclusivamente por administradores activos**. A matriz híbrida por perfil, utilizador, limite e conta está desenhada, mas **ainda não implementada** nesta entrega. Não distribuir credenciais de administrador para contornar esta restrição.
- **Abertura real de novos créditos está deliberadamente bloqueada** (`ContractOpeningController`) até associar a saída a uma conta activa, saldo e movimento da Caixa numa operação atómica. Esta entrega não reabre desembolsos.
- `Extracto`, `Contractos` e `Clientes` legados não foram modificados nem associados automaticamente a contas.
- Cobranças de crédito legadas continuam fora do livro de caixa novo. **Não lançar manualmente as mesmas cobranças no formulário de receita geral**, pois causaria duplicação.
- As contas abertas com saldo e referência documental são **validadas manualmente** por administrador, não reconciliadas automaticamente com um banco. A introdução de uma referência não verifica a autenticidade do documento. O anexo digital de comprovativos ainda não está implementado.
- **Caixa** permite confirmar movimentos manuais e consultar os seus saldos. **Tesouraria opcional** ficará responsável pela importação e conciliação de extractos, previsões e outros controlos avançados.
- Lançamentos do livro de caixa são confirmados e imutáveis pela interface actual; **o fluxo de estorno auditável ainda não existe** e terá de ser implementado antes de uso amplo.
- Transferências internas só podem ser registadas após confirmação documental de **débito da origem e crédito do destino**. Se estiverem pendentes, não registar como concluídas.

## Antes de qualquer execução na VPS

1. Confirmar plano de backup e restauração verificável da base usada para a Caixa.
2. Obter data de corte e comprovativos de cada conta real; não inventar saldos ou atribuir movimentos antigos.
3. Testar a migração numa cópia da base, identificar riscos de privilégios e validar as permissões MySQL.
4. Aprovar operacionalmente as escritas financeiras, os responsáveis e o processo de correcção/estorno antes de mudar a flag.

## Actualizar o código — não activa escrituras

```bash
cd /opt/cis
git pull origin main
bash deploy/refresh-private-preview.sh
```

O módulo permanecerá em estado de esquema não instalado até migrar as duas tabelas isoladas.

## Migração estritamente isolada (após backup e homologação)

**NÃO executar `php artisan migrate` sem `--path` na base legada.** Há outras migrações com dependências do esquema histórico.

Depois de garantir cópia de segurança e testar em cópia, aplicar apenas:

```bash
cd /opt/cis
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec -T api-preview \
  php artisan migrate --path=database/migrations/2026_11_16_000030_create_cis_cash_tables.php --force
```

**Sem seed ou saldos iniciais fictícios.** Para validar a instalação:

```bash
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml exec -T api-preview \
  php artisan route:list --path=api/cis/caixa
```

## Activação separada de movimentos (requer decisão operacional posterior)

Depois da validação do ambiente, os administradores poderão configurar a flag privada `CIS_CASH_WRITES_APPROVED=true` em `deploy/.env` e reconstruir o serviço com `bash deploy/refresh-private-preview.sh`. **Não activar essa flag apenas com base neste documento.** Ela permite escritas reais em contas novas e exige procedimentos definidos de evidência, auditoria, estornos e suporte.

## Critérios para avançar para desembolsos integrados

1. Cada conta tem data de corte, saldo e comprovativo verificado.
2. Permissões híbridas por perfil/utilizador/conta/limite aplicadas no servidor.
3. Fluxo explícito de confirmação da saída conforme numerário, banco ou carteira móvel.
4. `Contractos` + `Extracto` + `cis_cash_movements` gravados na mesma transacção, com rollback completo e chave idempotente.
5. Contas inactivas ou insuficientes recusadas no backend.
6. Não existem lançamentos duplicados por reenvio, falha de rede ou concorrência.
7. Testes de segurança e reconciliação histórica aprovados.
