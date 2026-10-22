# CIS — API Laravel em ambiente isolado

Este procedimento instala Laravel e a base de autenticação SQLite em Docker no computador do operador. Não liga o sistema a dados financeiros de produção.

## Preparação

Requisitos: Git e Docker Desktop (incluindo Docker Compose). Na raiz do repositório:

```sh
git clone https://github.com/wisync-corp/CIS.git
cd CIS
cp backend/.env.example backend/.env
```

Gerar APP_KEY **localmente**, sem a publicar ou partilhar:

```sh
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Colocar o resultado em `APP_KEY=` no ficheiro privado `backend/.env`.

Iniciar:

```sh
docker compose up --build -d
docker compose logs --tail=100 cis-api
```

Confirmação local:

```sh
curl -i http://127.0.0.1:8000/up
curl -i http://127.0.0.1:8000/api/auth/me
```

Esperado: /up = 200; /api/auth/me sem token = 401.

Criar primeiro administrador (pede palavra-passe sem a escrever no terminal):

```sh
docker compose exec cis-api php artisan cis:bootstrap-admin admin@exemplo.test
```

**Não utilize palavras-passe reais da Capta**. A conta é apenas para testes. Se o comando ou as migrações falharem, consultar `docker compose logs cis-api`.

Login:

```sh
curl -X POST http://127.0.0.1:8000/api/auth/login \
-H 'Content-Type: application/json' \
-d '{"email":"admin@exemplo.test","password":"SUA_PASSWORD_DE_TESTES"}'
```

Não coloque o token em imagens públicas, Issues ou mensagens partilhadas. O teste de login só deve ocorrer num terminal privado.

## Segurança

A porta é exposta apenas em `127.0.0.1` do computador. Não existe endpoint público HTTPS; esta instalação **não pode ser utilizada directamente pelo botão Testar API no GitHub Pages**, cujo navegador não alcança o localhost de outro computador e aplica restrições HTTPS/CORS. Não usar esta configuração em produção. A autenticação usa volume local independente da base legada.

A ligação MySQL de testes é opcional. Nunca execute migrações contra a base legada. Crie um utilizador MySQL com SELECT exclusivamente e forneça `LEGACY_DB_*` no ambiente privado.

Para parar: `docker compose down`. NÃO usar `docker compose down -v` se pretender manter as contas de teste.
