# CIS — Sistema Web de Gestão de Microcrédito

Projecto da Capta Microcrédito para migração gradual do sistema Excel/VBA para uma aplicação web.

## Estado
**Fase 1: engenharia inversa e preparação técnica.** O software ainda não está pronto para produção.

### Arquitectura alvo
- Backend: Laravel (PHP), API protegida.
- Frontend: React + TypeScript (arquitectura e empacotamento por finalizar).
- Base de dados: MySQL existente; integração inicial apenas de leitura e numa cópia de desenvolvimento.

### Regras inegociáveis
- Separar **capital**, **juros**, **amortização**, **multa** e **prestação**.
- Cada recebimento deve ter distribuição verificável por componente e trilho de auditoria.
- Pagamentos parciais, atrasos, renovações e liquidações exigem testes de equivalência com VBA.
- Alterar/dispensar multas exige aprovação de gestor ou administrador.
- Não alterar esquema nem registos da base de produção durante a descoberta.
- Não publicar dumps SQL, dados de clientes, palavras-passe ou ficheiros de configuração com segredos.

## Estrutura inicial
- `docs/` — investigação do legado, decisões e critérios de aceitação.
- `database/` — observações seguras sobre a estrutura existente (sem dados).
- `backend/` — reservado para implementação Laravel.
- `frontend/` — reservado para implementação React.

## Como contribuir
Consultar `docs/legado.md` e `docs/financeiro.md` antes de implementar operações financeiras. Não inferir regras não confirmadas.


## Dashboard Streamlit (adicionado em 22/10/2026)

A pedido da Capta, os painéis analíticos utilizam **Python + Streamlit** em `streamlit/app.py`, seguindo a organização do `Dashboard.py` fornecido. Mantêm-se **React** para os módulos transaccionais e **Laravel** como serviço de autenticação. Não existem credenciais SQL no código publicado.

Aceder às instruções em [streamlit/README.md](streamlit/README.md). A aplicação encontra-se em fase de desenvolvimento: para executar é necessário o backend Laravel, uma conta CIS e uma cópia da base MySQL com permissões apenas SELECT.

**Segurança urgente:** o ficheiro legado `Dashboard.py` fornecido contém uma password MySQL embutida. Recomenda-se rotacionar/revogar essa credencial antes de alojar qualquer nova versão. Não publicar o ficheiro original com segredos.
