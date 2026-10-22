# CIS — Ligação TLS à base MySQL cloud

A WiSync pode manter a API Laravel no computador e o MySQL na cloud. O código do CIS passou a aceitar um certificado CA para verificar o servidor MySQL. **Não colocamos palavras-passe ou certificados privados no GitHub.**

## Preparação

1. Solicitar ao fornecedor o **certificado CA oficial**, a configuração TLS suportada e a confirmação de que o serviço permite verificar a identidade do servidor. Não criar nem descarregar um certificado arbitrário de sites desconhecidos.
2. Guardar o ficheiro CA fornecido em `backend/certs/ca.pem` no computador. O directório não publica o ficheiro no GitHub.
3. No ficheiro privado `backend/.env`, adicionar `LEGACY_DB_SSL_CA=/run/cis-db-certs/ca.pem`.
4. Executar `docker compose up --build -d --force-recreate cis-api` e `docker compose exec cis-api php artisan cis:test-db`.
5. Executar `docker compose exec cis-api php artisan cis:check-db-safety`; verificar que a cifra TLS da sessão é apresentada como activa.

**Limitações:** TLS activo não prova que o certificado foi validado; a configuração com CA e a verificação do servidor devem funcionar em conjunto. Se a ligação falhar ao activar o certificado, não desligar a verificação para continuar com dados reais. A cloud pode não suportar TLS com verificação adequada nesse plano. Nesse caso, prosseguir apenas com dados fictícios e procurar túnel protegido ou outro serviço.

**Permissões:** o diagnóstico anterior detectou permissões de escrita. Uma exportação não elimina o risco de alteração, perda, fuga ou inconsistência dos dados. É necessário utilizar uma conta limitada a `SELECT`, de preferência numa réplica de testes. `CIS_FINANCIAL_TEST_APPROVED` permanece `false` por defeito.
