# CIS Web — validação da versão de desenvolvimento

## Interface visual publicada
- Login responsivo com marca Capta, validação de sessão e opção de mostrar/ocultar senha.
- Menu de navegação, dashboard sem métricas inventadas, distinção visual entre capital, amortização, juros, multa e prestação.
- Listagem paginada e pesquisa de **Clientes** por nome/ID.
- Listagem paginada e pesquisa de **Contratos** por número de contrato.
- Mensagens de carregamento, erro, ausência de resultados e acesso negado.

Todos os números financeiros aparecem como "—" enquanto não forem reconciliados com o VBA. A interface não cria, altera nem elimina registos.

## Testes automatizados
A workflow `.github/workflows/ci.yml` deverá compilar a interface e iniciar as dependências Laravel num runner GitHub. **A publicação do workflow não é comprovação de execução bem sucedida.**

## Para testar localmente
1. Instalar PHP 8.2+, Composer, Node.js 20+ e extensões PHP necessárias.
2. No `backend`: `composer install`; copiar `.env.example` para `.env`; criar `database/database.sqlite`; executar `php artisan key:generate`, `php artisan migrate` e `php artisan cis:bootstrap-admin admin@exemplo.test`.
3. Executar `php artisan serve` no backend.
4. No `frontend`: `npm install`, `npm run build`, `npm run dev`.
5. Abrir `http://localhost:5173`, iniciar sessão com a conta criada no passo 2 e confirmar que o perfil é apresentado.
6. Configurar no backend **apenas uma cópia de testes** do MySQL legado, com conta de base de dados com permissões `SELECT`, através de `LEGACY_DB_*`.
7. Validar paginação, pesquisa e consulta, incluindo entradas sem resultados e respostas HTTP 403 para agentes/cobradores.
8. Validar que sem login todas as rotas de consulta devolvem HTTP 401.
9. Validar num dispositivo móvel a navegação, o formulário de entrada e as tabelas.

## Bloqueios e questões de segurança
- **Ainda não foi comprovado um teste completo com a base real.**
- Não adicionar credenciais de produção ou dados pessoais aos commits.
- A tabela `Usuarios.Password` do VBA **não** deve ser reutilizada para logins web.
- A migração cria contas independentes na base de autenticação; jamais executar `migrate` contra a base de produção.
- A aplicação contém apenas consultas de leitura. O motor financeiro continua em estudo e não recebe pagamentos.
- Para produção futura, rever armazenamento de tokens no browser, controlo de escopo por carteira, TLS, política de sessão, logs de auditoria e testes de segurança.

## Critério de conclusão do marco
O marco só será considerado **testado** quando houver: build do React bem sucedido, migrações num banco de autenticação isolado, login/logout verificados, testes de HTTP 401/403, consulta de clientes/contratos na cópia MySQL e revisão de resultados com o legado.
