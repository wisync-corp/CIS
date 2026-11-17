#!/usr/bin/env bash
# Interruptor de emergência. Desactiva novas operações gerais; não altera saldos.
set -euo pipefail
cd "$(dirname "$0")/.."
[[ -f deploy/.env ]] || { echo 'BLOQUEADO: deploy/.env inexistente.' >&2; exit 1; }
sudo -v
echo '=== DESACTIVAR NOVOS MOVIMENTOS DA CAIXA ==='
python3 - <<'PY'
from pathlib import Path
import os,tempfile
p=Path('deploy/.env')
contents=p.read_text().splitlines()
flags=('CIS_CASH_WRITES_APPROVED','CIS_CASH_LEDGER_POSTING_APPROVED')
clean=[x for x in contents if not any(x.startswith(k+'=') for k in flags)]
clean.extend(k+'=false' for k in flags)
fd,temporary=tempfile.mkstemp(prefix='.cash-off-',dir=str(p.parent))
try:
    os.fchmod(fd,0o600)
    with os.fdopen(fd,'w') as f:
        f.write('\n'.join(clean)+'\n');f.flush();os.fsync(f.fileno())
    os.replace(temporary,p)
finally:
    if os.path.exists(temporary):os.unlink(temporary)
PY
sudo -n docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  up -d --no-deps --force-recreate api-preview
echo 'NOVOS MOVIMENTOS GERAIS DESACTIVADOS. Nenhum registo financeiro foi alterado.'
