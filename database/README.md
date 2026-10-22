# Integração com a base existente

Foram recebidos exports SQL com dados reais. **Não devem ser commitados**.

Observações do DDL:
- Contractos e Extracto armazenam valores monetários com FLOAT: evitar operações monetárias sem converter para decimal exacto na nova aplicação.
- Usuarios.Password está definido como INT: autenticação web exige migração controlada para hash seguro sem divulgação de credenciais.
- Identificadores e nomes de colunas incluem espaços, acentos e caracteres especiais: mapear explicitamente no ORM.
- Extracto usa latin1, enquanto as outras tabelas usam utf8: normalizar a comunicação sem modificar tabelas em produção.
- Nenhuma chave estrangeira foi comprovada nos quatro DDL recebidos.

Antes de escrever: cópia isolada e anonimizada para desenvolvimento, mapeamento de regras e backup validado.
