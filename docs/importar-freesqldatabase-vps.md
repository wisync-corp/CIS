# CIS — preparação da importação do FreeSQLDatabase para o VPS

**Estado:** apenas preparação e pré-verificação; nenhum dado financeiro foi transferido pelo GitHub. Destino: MySQL 8 no VPS RackNerd; origem: FreeSQLDatabase MySQL 5.5.

## Exportar pela interface phpMyAdmin do FreeSQLDatabase

1. Seleccionar a base de dados verdadeira na barra esquerda, não apenas uma tabela.
2. **Export > Custom > SQL**; incluir as quatro tabelas `Usuarios`, `Clientes`, `Contractos`, `Extracto` (e outras estruturas necessárias apenas após confirmar a sua finalidade).
3. Preferir `CREATE TABLE` + `INSERT`, com nomes de colunas e charset preservados. NÃO seleccionar `DROP TABLE` ou `CREATE DATABASE` para a primeira importação sem revisão. As opções exactas variam entre versões do phpMyAdmin.
4. Guardar o ficheiro somente no computador privado, não no GitHub, não em mensagens e não numa pasta pública.
5. Exportar em período sem alterações de dados; se houver actividade contínua, definir janela de congelamento e plano de reconciliação para evitar cópias inconsistentes.

**Segurança:** os dumps podem conter dados pessoais, movimentos financeiros e `Usuarios.Password` numérico. Restringir o acesso e cifrar cópias de segurança. Não colar as linhas do SQL em issues, chats ou logs.

## Transferência privada pelo SSH

No **Windows PowerShell**, depois de exportar e confirmar o caminho real do ficheiro:

```powershell
ssh cisadmin@192.236.188.156 "mkdir -p ~/cis-private && chmod 700 ~/cis-private"
scp "C:\CAMINHO\DO\SEU\backup.sql" cisadmin@192.236.188.156:~/cis-private/original.sql
```

No **Ubuntu do VPS**:

```bash
chmod 600 ~/cis-private/original.sql
cd /opt/cis
git pull origin main
bash deploy/preflight-import.sh ~/cis-private/original.sql
```

O script calcula apenas a impressão digital SHA-256 e verifica se as quatro tabelas já existem no destino. **Não importa o ficheiro SQL, não lê registos nem altera a base.** Caso existam tabelas, devolve erro. Não executar importação sobre tabelas existentes.

## Antes da importação real (não autorizada por este guia)

- Fazer backup verificável do destino e do original; validar restauro e guardar cópias fora do VPS, cifradas.
- Validar no SQL (em ambiente privado, sem publicar os dados) as instruções `DROP`, `CREATE DATABASE`, `USE`, `DEFINER`, `GRANT`, `SET GLOBAL`, `LOAD DATA`, triggers e outras instruções potencialmente perigosas.
- Rever diferenças MySQL 5.5 → 8.0 (palavras reservadas, `sql_mode`, charset/collations, `utf8mb4`, nomes com acentos e integridade de `FLOAT`).
- Decidir se o esquema importado e as tabelas operacionais Laravel partilham a mesma base. **A composição actual já criou uma base `cis` vazia e pode conter tabelas próprias do Laravel noutras instalações.** Nunca correr migrações Laravel sem rever o destino.
- Importar somente com janela de manutenção, aprovação, teste de restauro e comparação de contagens/totais por contrato e extracto.
- A tabela `Usuarios.Password` é `INT`: **não reutilizar passwords do VBA na web**; validar identidades e definir novas credenciais seguras.
- Manter a porta 3306 fechada à Internet, com MySQL em rede Docker privada.

## Estado

O script `deploy/preflight-import.sh` foi preparado na branch `main`. A exportação, transferência, verificação de compatibilidade e importação efectiva são operações separadas, feitas pelo operador autorizado. Este guia não presume que o dump exista no VPS.
