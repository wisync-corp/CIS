# CIS Backend — Laravel

**Estado:** módulo de integração, não aplicação Laravel instalável isoladamente.

## Preparação
1. Criar projecto Laravel estável numa pasta `backend` e integrar os ficheiros em `app/` e `routes/api.php`.
2. Instalar/configurar Sanctum e habilitar middleware `auth:sanctum` com utilizadores web novos e hashes seguros — **não usar `Usuarios.Password`**.
3. Utilizar credenciais MySQL de um utilizador estritamente **SELECT** ligado a uma cópia isolada dos dados.
4. Configurar ligação `mysql_legacy` em `config/database.php` (exemplo em `config/legacy-connection.example.php`).
5. Validar autorizações de acesso por perfil e visibilidade de registos antes de abrir as rotas.

## Rotas planejadas
- `GET /api/cis/clientes` — paginação de clientes, protegida.
- `GET /api/cis/contractos` — paginação de prestações, protegida.
- `GET /api/cis/contractos/{numero}/prestacoes` — prestações de contrato, protegida.

**Aviso:** os ficheiros PHP publicados são componentes a integrar no projecto Laravel, não um backend em funcionamento. Nenhuma rota de pagamento/alteração foi publicada.
