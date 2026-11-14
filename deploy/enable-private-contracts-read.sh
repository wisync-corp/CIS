#!/usr/bin/env bash
# Activar apenas consulta privada de contratos (nunca escrita).
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo "ERRO: deploy/.env em falta" >&2; exit 1; }
grep -qx 'CIS_CLIENTES_PREVIEW_APPROVED=true' deploy/.env || { echo "ERRO: pré-visualização privada ainda não configurada." >&2; exit 1; }
if grep -qx 'CIS_CONTRACTS_READ_APPROVED=true' deploy/.env; then
 echo "Consulta privada de contratos já autorizada. Nenhuma alteração necessária."
 exit 0
fi
if grep -q '^CIS_CONTRACTS_READ_APPROVED=' deploy/.env; then
 echo "BLOQUEADO: variável já existe; rever o ficheiro privado sem duplicar linhas." >&2
 exit 1
fi
echo "Esta acção permite ao administrador visualizar contratos e prestações financeiras reais"
echo "SOMENTE no túnel SSH, usando a conta cis_reader (SELECT); não autoriza edições."
read -r -p "Confirma a consulta privada? Escreva CONSULTAR: " confirm
[[ "$confirm" == "CONSULTAR" ]] || { echo "Cancelado"; exit 0; }
umask 077
printf '\nCIS_CONTRACTS_READ_APPROVED=true\n' >> deploy/.env
chmod 600 deploy/.env
echo "Consulta de contratos autorizada neste VPS privado. Reinicie api-preview e web-preview."
