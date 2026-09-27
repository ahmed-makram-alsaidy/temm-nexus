#!/usr/bin/env sh
# Disk + database growth snapshot (VPS cron daily; appends to a CSV for trends).
# Usage: disk-db-check.sh [out_csv]
set -eu
cd "$(dirname "$0")/../.."
OUT="${1:-/var/log/infra-growth.csv}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
TS="$(date -u +%FT%TZ)"
DISK="$(df -P / | awk 'NR==2{print $5}')"
DBS="$(docker compose exec -T postgres psql -h localhost -U "${POSTGRES_USER:-postgres}" \
  -d postgres -tAc "SELECT datname||'='||pg_database_size(datname) FROM pg_database WHERE datistemplate=false;" | tr '\n' ';')"
printf '%s disk=%s %s\n' "$TS" "$DISK" "$DBS" >> "$OUT"
echo "OK snapshot appended to $OUT"
