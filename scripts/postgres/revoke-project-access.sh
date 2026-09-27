#!/usr/bin/env sh
# Revoke a project's access WITHOUT deleting its data (safe off-boarding step 1).
# Usage: REVOKE_CONFIRM=<db_user> revoke-project-access.sh <db_user>
# Kills sessions, locks role login, retains the database for later backup/drop.
set -eu
DB_USER="${1:?usage: $0 <db_user>}"
[ "${REVOKE_CONFIRM:-}" = "$DB_USER" ] || { echo "ERROR: set REVOKE_CONFIRM=$DB_USER to proceed" >&2; exit 1; }
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
PSQL="psql -h "${PGHOST:-localhost}" -U ${POSTGRES_USER:-postgres} -d postgres -v ON_ERROR_STOP=1 -q"
$PSQL -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE usename='$DB_USER' AND pid <> pg_backend_pid();"
$PSQL -c "ALTER ROLE \"$DB_USER\" WITH NOLOGIN;"
echo "OK revoked login for $DB_USER (data retained). Next: backup, then drop-project-db.sh"
