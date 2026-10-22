# Estado técnico — 22 de Outubro de 2026

## Publicado
- Frontend React/TypeScript, login, logout, navegação, dashboard sem dados e listagens de clientes/contratos via API.
- Backend mínimo Laravel 11: bootstrap, rotas, autenticação Sanctum, migrations independentes do legado, autorização por perfil.
- Ligação MySQL legada separada, com desenho de acesso apenas para leitura numa réplica.
- Núcleo financeiro que valida pagamentos já distribuídos em amortização, juros e multa, com centavos inteiros.
- GitHub Actions CI para instalar dependências, compilar frontend, executar smoke financeiro e migrations SQLite.

## Não validado/concluído
- O ambiente actual não tinha Composer nem acesso de rede para instalar dependências; portanto não foi possível confirmar a execução fim-a-fim.
- GitHub Actions: workflow publicado, resultados ainda não verificados.
- Não foi ligada base MySQL, nem existem dados reais exibidos.
- Não há implementação de escrita financeira nem autorização de carteiras/agências.
- A engenharia inversa da distribuição de pagamentos do VBA continua necessária.
- O backend e as rotas devem ser testados antes de qualquer utilização real.

## Segurança
- Não colocar credenciais nos ficheiros versionados.
- Nunca usar exportações de clientes em ambientes públicos.
- Não executar migrations na base legada de produção.
