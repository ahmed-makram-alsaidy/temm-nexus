#!/usr/bin/env sh
# Terminate all sessions on one project DB (for restore/maintenance windows).
# Usage: KILL_CONFIRM=<db_name> terminate-project-connections.sh <db_name>
set -eu
DB_NAME="${1:?usage: $0 <db_name>}"
[ "${KILL_CONFIRM:-}" = "$DB_NAME" ] || { echo "ERROR: set KILL_CONFIRM=$DB_NAME" >&2; exit 1; }
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
psql -h "${PGHOST:-localhost}" -U "${POSTGRES_USER:-postgres}" -d postgres -v ON_ERROR_STOP=1 -q \
  -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$DB_NAME' AND pid <> pg_backend_pid();"
echo "OK terminated sessions on $DB_NAME"
