#!/usr/bin/env bash
# Gera o pacote da extensão para descarregar a partir do CIS (frontend/public/cis-whatsapp-extension.zip).
set -euo pipefail
cd "$(dirname "$0")"
out=../frontend/public/cis-whatsapp-extension.zip
rm -f "$out"
(cd cis-whatsapp && zip -q -X -r "../$out" manifest.json background.js bridge.js wa.js options.html options.js icons)
echo "Pacote: frontend/public/cis-whatsapp-extension.zip ($(du -h "$out" | cut -f1))"
