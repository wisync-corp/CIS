# CIS no VPS RackNerd — instalação controlada

## Estado e segurança

Código no GitHub, VPS Ubuntu 24.04 com Docker. A configuração em `deploy/compose.vps.yaml` prepara **um servidor MySQL 8, Laravel e React/Caddy**. Os registos originais continuam no FreeSQLDatabase; **esta configuração não migra os dados nem activa a consulta financeira**.

A base MySQL usa volume persistente `cis_mysql_data`, não publica 3306, e o Laravel só é alcançável pelo proxy HTTPS. A aplicação utiliza a **mesma instância MySQL**, sem PostgreSQL. O banco `cis` criado no arranque começa vazio; não se deve confundir com a base original.

## Antes de iniciar

- Confirmar que o administrador `cisadmin` tem sudo e acesso SSH por chave; manter sessão alternativa até confirmar firewall.
- Definir domínio real e registo DNS A para o IPv4 do VPS. HTTPS público com Caddy **depende disso**; não usar o domínio de exemplo.
- Configurar cópia de segurança remota cifrada, permissões, actualizações de segurança, e plano de restauro.
- O Docker pode expor portas independentemente do UFW: esta composição publica só HTTP/HTTPS, **nunca MySQL**. Verificar rede e portas no VPS.
- O Dockerfile Laravel actual usa `php artisan serve`; é apropriado apenas para *pré-produção*. Antes de alojar dados financeiros reais, substituir por servidor PHP de produção (PHP-FPM + web server ou FrankenPHP), efectuar revisão de segurança e testes.

## Preparar segredos localmente no VPS

Na raiz do repositório (`/opt/cis`):

```bash
cp deploy/.env.example deploy/.env
chmod 600 deploy/.env
```

Editar `deploy/.env` com `nano deploy/.env`, preenchendo `CIS_DOMAIN` (domínio real), `ACME_EMAIL`, `APP_KEY` (Laravel, formato `base64:...`), `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD` com valores fortes, únicos. Não enviar segredos para chat, GitHub ou logs. Usar `sudo docker compose --env-file deploy/.env -f deploy/compose.vps.yaml config --quiet` para validar a sintaxe sem imprimir os segredos.

**Não iniciar o stack até confirmar domínio, HTTPS, backups e plano de migração.**

Quando estiver autorizado a executar o ambiente de homologação:

```bash
cd /opt/cis
sudo docker compose --env-file deploy/.env -f deploy/compose.vps.yaml up -d --build
sudo docker compose --env-file deploy/.env -f deploy/compose.vps.yaml ps
```

O backend executa migrações Laravel no seu banco de autenticação. Por isso, **não apontar esta configuração para o FreeSQLDatabase** nem para um banco já populado antes de revisar as migrações e ter backup.

## Migração dos dados reais — etapa separada

1. Exportar SQL directamente do FreeSQLDatabase para armazenamento privado cifrado, **nunca para GitHub**.
2. Testar compatibilidade de MySQL 5.5 com MySQL 8 num restauro isolado; garantir que as tabelas `Usuarios`, `Clientes`, `Contractos`, `Extracto` e os dados estão correctos antes de comutar.
3. Rever especialmente `Password INT` na `Usuarios`, colunas acentuadas, `FLOAT`, charset/collations e diferenças de SQL.
4. Confirmar reconciliação financeira, isolamento por instituição e controlo de permissões. Depois, executar migração aprovada com plano de rollback.
5. Não executar `docker compose down -v` nem remover volumes persistentes.

## Situação

A composição de instalação é apenas **preparação**. A execução no VPS, o domínio, a migração de dados e a verificação do serviço estão pendentes de confirmação no servidor.
