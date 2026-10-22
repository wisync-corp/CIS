# Validação

`php tests/domain_smoke.php`: testes mínimos de aritmética e distribuição explícita.

`php artisan migrate`: cria tabelas **separadas** para autenticação no banco configurado como `DB_CONNECTION`. Nunca usar a base legada de produção como destino desta migração.

A workflow `.github/workflows/ci.yml` executa compilação React, instalação Laravel, migrações SQLite e teste financeiro. Nenhuma ligação à produção é necessária.

Este conjunto é um teste básico, **não** constitui certificação da lógica financeira do sistema VBA.
