#!/usr/bin/env bash
# Interruptor independente: desliga pagamentos e desembolsos ligados à Caixa.
# Movimentos e histórico já gravados mantêm-se; simulações continuam disponíveis.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || exit 1
sudo -v
python3 -c 'import pathlib,os,tempfile; k="CIS_CASH_CONTRACT_FLOWS_APPROVED"; p=pathlib.Path("deploy/.env"); x=[l for l in p.read_text().splitlines() if not l.startswith(k+"=")]; x.append(k+"=false"); fd,n=tempfile.mkstemp(prefix=".contract-cash-off-",dir=str(p.parent)); os.fchmod(fd,0o600); f=os.fdopen(fd,"w"); f.write("\n".join(x)+"\n"); f.flush(); os.fsync(f.fileno()); f.close(); os.replace(n,p)'
sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml up -d --no-deps --force-recreate api-preview
echo 'PAGAMENTOS E DESEMBOLSOS DESACTIVADOS. Histórico e movimentos existentes mantidos.'
