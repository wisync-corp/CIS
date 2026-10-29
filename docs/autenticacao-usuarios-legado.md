# CIS — autenticação integrada com Usuarios (preparação)

## Implementado na branch main

O endpoint `POST /api/auth/login` mantém o login CIS habitual e dispõe de uma alternativa **desactivada por defeito**, que valida uma palavra-passe armazenada na tabela MySQL legada `Usuarios`.

Este modo só se aplica se **todas** as condições forem verdadeiras:
- Utilizador já criado na base de autenticação CIS, activo, com email e `legacy_username` associados pelo administrador.
- `CIS_LEGACY_LOGIN_ENABLED=true` (o valor por defeito é `false`).
- CA confiável presente em `LEGACY_DB_SSL_CA`, PDO configurado para verificar o certificado do MySQL e `Ssl_cipher` não vazio.
- Tabela/colunas de utilizador e hash correctamente identificadas em variáveis privadas.
- Exactamente uma conta encontrada para `legacy_username` na origem.
- Hash bcrypt/Argon2i/Argon2id validado com `password_verify`. **Não** aceita palavra-passe em texto simples, MD5, SHA1 ou outras variantes do VBA.
- Base de autenticação CIS **persistente**, para armazenar utilizadores e tokens Sanctum.

A pesquisa SQL usa parâmetros e não lê nem apresenta a palavra-passe em claro; erros não são devolvidos ao utilizador. A ligação MySQL legada deve ter privilégios exclusivamente SELECT, preferencialmente restringidos à tabela `Usuarios` para esta função.

## Estado do servidor original

O MySQL original respondeu `have_ssl: DISABLED`. Portanto **não activar** `CIS_LEGACY_LOGIN_ENABLED` neste servidor. É necessário servidor com TLS verificado, CA confiável e base persistente para o CIS. O Render gratuito com SQLite efémero não satisfaz o requisito de persistência.

## Diagnóstico seguro das colunas

No computador onde a ligação SQL já está configurada:

```powershell
docker compose exec cis-api php artisan cis:inspect-legacy-users
```

O comando devolve **somente nomes e tipos das colunas** `Usuarios`/`Users`, sem consultar registos, nomes próprios ou passwords. Confirme também *como* o VBA faz a comparação da palavra-passe (não partilhar palavras-passe nem hashes pessoais).

## Configuração futura privada (não colocar segredos no GitHub)

```dotenv
CIS_LEGACY_LOGIN_ENABLED=false
LEGACY_AUTH_TABLE=Usuarios
LEGACY_AUTH_USERNAME_COLUMN=
LEGACY_AUTH_PASSWORD_HASH_COLUMN=
LEGACY_DB_SSL_CA=
```

Os campos de coluna deverão ser preenchidos **apenas depois da inspecção**. A interface usa o email da conta CIS previamente associada a `legacy_username`. Os papéis de acesso são atribuídos localmente pelo administrador CIS; nunca são automaticamente promovidos pela tabela legada.

Se as passwords do VBA estiverem em texto simples/MD5, a integração **não** as aceita: criar um procedimento de recuperação/redefinição num novo armazenamento seguro. Para os utilizadores existentes, a continuidade do *nome da conta* pode ser preservada, mas a password antiga poderá ter de ser substituída.

## Pendente

1. Confirmar nomes e tipos das colunas da tabela `Usuarios`.
2. Rever o modo de verificação de passwords do VBA, sem transmitir segredos.
3. Disponibilizar MySQL com TLS validado e conta SELECT-only.
4. Disponibilizar base de autenticação CIS persistente e mapear utilizadores e permissões existentes com aprovação.
5. Validar fluxo em homologação sem dados reais; autorizar activação separadamente.

Não há autenticação legada operacional enquanto estas condições não forem cumpridas.
