# Backend Laravel — CIS (pré-produção)

Estrutura mínima de aplicação Laravel 11, com API Sanctum e migrações de autenticação próprias. **Ainda não foi testada contra o servidor MySQL do cliente**.

## Preparação local

Necessário PHP 8.2+, Composer e extensões habituais do Laravel (incluindo SQLite e MySQL).

```bash
cd backend
composer install
cp .env.example .env
# Retire DB_DATABASE do .env para usar o caminho SQLite padrão
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan cis:bootstrap-admin administrador@exemplo.test
php artisan serve
```

Definir `FRONTEND_URL=http://localhost:5173` para a origem React. As contas CIS usam o SQLite configurado por `DB_CONNECTION`, nunca a tabela `Usuarios` legada.

## MySQL legado

Configurar `LEGACY_DB_*` em `.env` para **réplica de testes**, utilizando conta de base de dados com privilégio exclusivamente SELECT. `mysql_legacy` é configurada separadamente no provider Laravel. Não executar migrations na base de dados legada.

As rotas GET de Clientes e Contractos aceitam apenas utilizadores **administrador** e **gestor**, enquanto não estiver implementada autorização por carteira. Agentes/cobradores recebem 403. Todas as rotas são autenticadas. Não existem rotas de escrita financeira.

## Testes e limitações

```bash
php tests/domain_smoke.php
php artisan route:list --path=api
```

O teste financeiro apenas valida a aritmética e invariantes. Não determina as prioridades de imputação nem substitui os testes contra o VBA. A integração com uma instância real não foi validada.
