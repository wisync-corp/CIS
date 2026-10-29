# CIS — publicação de demonstração no Render (GitHub main)

A raiz do repositório contém `render.yaml`. O Render constrói a API Laravel usando o Dockerfile já existente em `backend/Dockerfile`.

## Publicar

1. No Render, escolher **New > Blueprint**, ligar a conta GitHub e seleccionar o repositório `wisync-corp/CIS`, branch `main`.
2. Confirmar o serviço `cis-api-preview` e escolher o plano gratuito quando disponível.
3. Definir `APP_KEY` como segredo privado no Render, no formato `base64:<chave>`. Para gerar uma chave localmente, usar: `docker compose exec cis-api php artisan key:generate --show`. **Não enviar esta chave ao GitHub, nem substituir a chave do ambiente local em utilização.**
4. Publicar. Abrir `https://<servico>.onrender.com/up`; a API deve responder `Application up`.
5. NÃO ligar a base MySQL real nem definir `CIS_FINANCIAL_TEST_APPROVED=true` neste preview.

## Restrições fundamentais

- O Render Free **não tem disco persistente**: a base SQLite de autenticação criada no arranque perde-se em reinícios e deploys. Sem administrador criado nessa instância, o login online não estará disponível. **Este preview valida a construção e o endpoint /up, não é adequado para utilizar o CIS com utilizadores e dados reais.**
- O frontend React no GitHub Pages ainda pode ter de ser configurado com `VITE_API_URL=https://<servico>.onrender.com/api` durante o build da versão pública; nunca colocar uma palavra-passe neste parâmetro.
- O `FRONTEND_URL` define a origem autorizada pelo CORS, sem barra final nem caminho.
- `APP_ENV=production`, `APP_DEBUG=false`, e `CIS_FINANCIAL_TEST_APPROVED=false` são valores intencionais. Não mudar o middleware para liberar leitura de dados originais no preview.
- A base MySQL existente comunicou `have_ssl: DISABLED`; HTTPS na API Render **não** protege automaticamente a ligação Render→MySQL.
- Para uma instalação utilizável: base de autenticação persistente, ligação MySQL com TLS validado, utilizador MySQL somente SELECT, controlo de acesso por instituição e gestão de segredos. Não usar SQLite efémero para produção.
- O Render poderá mudar limites e preços; confirmar no painel antes de activar serviços.

## Desenvolvimento local

O fluxo local continua a utilizar `compose.yaml` e `backend/.env`; o ficheiro `render.yaml` não modifica os serviços locais nem o volume persistente `cis_auth_sqlite`.
