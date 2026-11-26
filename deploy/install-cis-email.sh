#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  exec -T api-preview php artisan migrate --path="database/migrations/2026_11_26_230001_create_cis_mail_tables.php" --force
