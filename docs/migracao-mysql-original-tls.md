# CIS — migração controlada da base original para MySQL com TLS

## Estado actual

A ligação à base original funciona com SELECT 1 e a estrutura de Clientes, Contractos e Extracto foi confirmada. O servidor de origem declarou `have_ssl: DISABLED`; a sessão não utiliza TLS e os privilégios da conta incluem operações de escrita. O acesso aos registos pessoais pela API continua bloqueado. Não contornar `RequireTestDataApproval` nem colocar dados reais no GitHub.

## Preparação do servidor de destino (ainda não executada)

- Escolher instância MySQL privada, com TLS 1.2+ e validação do nome do servidor/certificado, backups e controlo de IP/rede.
- Obter host, porta, versão MySQL, identificação da base, CA oficial e utilizador administrativo **por canal seguro do fornecedor**. Guardar segredos apenas no ambiente privado do servidor ou gestor de segredos, nunca no repositório, issues, prints ou chat.
- Criar primeiro um ambiente isolado de homologação. O backup existente deve ser verificado por restauro, contagens, relações e amostras financeiras autorizadas.
- Comparar a versão da origem com a versão de destino, collations, charset, nomes de colunas com acentos, tipos FLOAT e índices. Não normalizar automaticamente colunas.
- Não executar migração durante utilização operacional, sem plano de paragem e rollback.

## Sequência de migração

1. Fazer backup consistente da origem através do painel do fornecedor, num local cifrado e com controlo de acesso.
2. Restaurar no servidor de destino **via canal protegido**, sob controlo do operador autorizado. Nunca enviar dump para o GitHub.
3. Validar tabelas originais `Clientes`, `Contractos`, `Extracto` e `Usuarios` (se utilizada), sem expor registos.
4. Conferir contagens e totais por contrato, prestações, movimentos e histórico; registar diferenças para correcção antes de qualquer comutação.
5. Criar utilizador exclusivo `cis_reader` com apenas `SELECT` na base aprovada, restringindo origens de rede. A aplicação não deve usar o utilizador administrador.
6. Guardar a CA oficial em `backend/certs/ca.pem` **apenas na instalação privada**; este caminho está protegido por `.gitignore`.
7. Configurar no ficheiro privado `backend/.env`: `LEGACY_DB_HOST`, `LEGACY_DB_PORT`, `LEGACY_DB_DATABASE`, `LEGACY_DB_USERNAME`, `LEGACY_DB_PASSWORD`, `LEGACY_DB_SSL_CA=/run/cis-db-certs/ca.pem`.
8. Reiniciar a API; testar `cis:probe-verified-tls`, `cis:check-db-safety`, `cis:inspect-schema`. Não confiar apenas em `cis:test-db`, que valida somente conectividade.
9. Confirmar com o responsável pelos dados a autorização para acesso aos registos e o isolamento entre instituições/usuários. O middleware actual exige ambiente de homologação expressamente aprovado; **não remover essa condição por conveniência**.
10. Fazer testes de leitura com acesso mínimo e verificar auditoria; só depois planear comutação formal com possibilidade de rollback.

## Dados que podem ser partilhados para preparar os scripts

Pode partilhar **sem credenciais**: nome do fornecedor de destino, versão MySQL, porta, política TLS, confirmação de hostname/CA e modelo de rede, sem dados pessoais. Não partilhar passwords, tokens, chaves SSH privadas, connection strings ou dumps.

## Limitações

O GitHub armazena código e documentação; não activa TLS no servidor de origem. A disponibilização de um novo servidor e a transferência de dados requerem acesso administrativo autorizado e execução operacional controlada. O CIS continua sem permissão para consultar clientes reais no ambiente actual.
