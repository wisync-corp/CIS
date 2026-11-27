#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  exec -T api-preview php artisan migrate --path="database/migrations/2026_11_27_060001_create_rh_rules.php" --force
sudo docker compose --env-file deploy/.env -f deploy/mysql-only.yaml -f deploy/compose.preview.yaml \
  exec -T api-preview php artisan migrate --path="database/migrations/2026_11_27_060002_create_rh_records.php" --force
