# Marco de implementação — 22/10/2026

## Entregue no código
- Frontend React com formulário de login, estado de sessão e menu.
- Consulta paginada de Clientes e Contractos, dependente de API autenticada.
- Projecto Laravel inicial com rotas de autenticação Sanctum, migrations de contas web separadas dos utilizadores do VBA.
- Integração MySQL legado em ligação separada e apenas para consulta.
- Acesso aos listados restrito a administrador/gestor até existirem políticas por carteira para agente/cobrador.
- Núcleo de validação da distribuição entre amortização, juros e multa, sem assumir prioridade de imputação.

## Não concluído / sem validação
- Dependências PHP não instaladas neste ambiente; aplicação ainda não foi testada end-to-end.
- Base MySQL de desenvolvimento não ligada.
- Comparação com VBA para juros, renovações, pagamento parcial e multas não concluída.
- Políticas de acesso por cliente/carteira, gestão de utilizadores e dashboard com indicadores reais não implementados.
- Não existe qualquer funcionalidade de escrita de pagamentos.
- Nenhum dado pessoal ou ficheiro de exportação SQL foi publicado.

## Requisitos para execução local
- PHP 8.2+ e extensões exigidas pelo Laravel, Composer e Node 20+.
- Criar `backend/database/database.sqlite` vazio ou configurar base de autenticação independente.
- Executar `composer install`, `cp .env.example .env`, `php artisan key:generate`, `php artisan migrate`, `php artisan cis:bootstrap-admin email@empresa.local` e `php artisan serve`.
- Definir `FRONTEND_URL` para o frontend e uma ligação MySQL de testes com permissões SELECT.
- No frontend: `npm install`, `npm run dev`.
- O sistema ainda requer validação técnica antes de qualquer utilização com informação sensível.
