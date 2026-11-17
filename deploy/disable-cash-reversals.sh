#!/usr/bin/env bash
# Interruptor independente: desliga estornos, sem parar entradas/saidas.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || exit 1
sudo -v
python3 -c 'import pathlib,os,tempfile; p=pathlib.Path("deploy/.env"); x=[v for v in p.read_text().splitlines() if not v.startswith("CIS_CASH_REVERSALS_APPROVED=")]; x.append("CIS_CASH_REVERSALS_APPROVED=false"); fd,n=tempfile.mkstemp(prefix=".cash-reversal-disable-",dir=str(p.parent)); os.fchmod(fd,0o600); f=os.fdopen(fd,"w"); f.write("\n".join(x)+"\n"); f.flush(); os.fsync(f.fileno()); f.close(); os.replace(n,p)'
sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml up -d --no-deps --force-recreate api-preview
echo 'ESTORNOS DESACTIVADOS; movimentos originais e restantes funcoes mantidos.'
