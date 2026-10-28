# CIS — validar consultas com MySQL isolado e dados fictícios

O CIS não precisa de executar consultas de clientes reais na cloud durante esta validação. Este ambiente utiliza a estrutura da tabela `Clientes` que foi partilhada (18 colunas), com dois registos sintéticos. `Contractos` e `Extracto` têm apenas as colunas requeridas pelos endpoints desta fase, **não** são réplicas completas das tabelas originais.

## Iniciar no Windows PowerShell

```powershell
cd C:\Users\Mvala\CIS
git pull origin main
docker compose -f compose.yaml -f compose.test.yaml up --build -d
docker compose -f compose.yaml -f compose.test.yaml exec cis-api php artisan cis:test-db
docker compose -f compose.yaml -f compose.test.yaml exec cis-api php artisan cis:inspect-schema
```

Aceder ao React em `http://localhost:5173` e iniciar sessão com a conta **já existente no CIS local**. No menu **Clientes**, deverão surgir `Cliente Fictício Alfa` e `Cliente Fictício Beta`.

**Atenção:** o serviço `cis-api` conserva a base SQLite de autenticação; não criar outra conta. O contêiner MySQL de testes não expõe a porta 3306 e o utilizador `cis_test_reader` tem somente `SELECT` na base `cis_test_replica`. As palavras-passe no fixture são exclusivamente para dados inventados e ambiente local, e não devem ser reutilizadas.

Se a API apresentar 503, confirmar que se iniciou o serviço com **ambos** os ficheiros Compose, não apenas `compose.yaml`.

## Voltar à configuração normal

```powershell
docker compose -f compose.yaml -f compose.test.yaml stop cis-api cis-test-mysql
docker compose -f compose.yaml up -d --force-recreate cis-api
```

Não executar `docker compose down -v` ou `migrate:fresh`. Não activar `CIS_FINANCIAL_TEST_APPROVED` na ligação cloud existente. A ligação cloud anterior não teve TLS confirmado e apresentou privilégios de escrita; este ambiente de testes não altera esses factos.
