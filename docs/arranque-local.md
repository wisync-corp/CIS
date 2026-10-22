# CIS — instalação local sem pagar servidor

Este procedimento instala **a API Laravel e o SQLite de autenticação** no próprio computador, para testar sem alojamento pago. A interface React e o Streamlit são serviços adicionais e não são iniciados por estes scripts.

## Windows (PowerShell)

Instalar [Git](https://git-scm.com/downloads) e [Docker Desktop](https://www.docker.com/products/docker-desktop/) e iniciar o Docker Desktop.

Na janela PowerShell:

```powershell
git clone https://github.com/wisync-corp/CIS.git
cd CIS
powershell -ExecutionPolicy Bypass -File .\scripts\iniciar-cis.ps1
```

O script cria `backend/.env` privado, gera a chave da aplicação apenas quando ainda não existe, constrói e inicia o Docker. Não introduz credenciais financeiras.

## macOS/Linux

Instalar Git, Docker e Docker Compose:

```sh
git clone https://github.com/wisync-corp/CIS.git
cd CIS
sh scripts/iniciar-cis.sh
```

## Verificação

Abrir **http://127.0.0.1:8000/up** no mesmo computador. O esperado é uma resposta 200 da aplicação Laravel.

Criar a primeira conta (a password é solicitada interactivamente):

```sh
docker compose exec cis-api php artisan cis:bootstrap-admin admin@exemplo.test
```

Consultar `http://127.0.0.1:8000/api/auth/me` sem iniciar sessão deve devolver 401. É o resultado esperado de uma rota protegida.

Erros: `docker compose logs --tail=100 cis-api`. Parar: `docker compose down`.

## Restrições

A instalação **não publica nada na internet**. O GitHub Pages permanece uma demonstração sem acesso à API local. Não utilizar dados reais da Capta antes de implementar isolamento, permissões, auditoria e aprovação adequada. As consultas legadas permanecem desactivadas por defeito.
