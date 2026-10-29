#!/bin/sh
set -eu
# O Dockerfile já invoca o entrypoint com migração SQLite.
# Render publica a porta indicada pela variável PORT.
exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
