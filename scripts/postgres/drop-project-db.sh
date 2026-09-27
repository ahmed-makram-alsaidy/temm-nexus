#!/usr/bin/env sh
# Permanently DROP a project database + role. DESTRUCTIVE.
# Usage: DROP_CONFIRM=<db_name> drop-project-db.sh <db_name> <db_user>
# Requires a prior revoke; refuses unless no sessions remain.
set -eu
DB_NAME="${1:?usage: $0 <db_name> <db_user>}"
DB_USER="${2:?usage: $0 <db_name> <db_user>}"
[ "${DROP_CONFIRM:-}" = "$DB_NAME" ] || { echo "ERROR: set DROP_CONFIRM=$DB_NAME to proceed" >&2; exit 1; }
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
PSQL="psql -h "${PGHOST:-localhost}" -U ${POSTGRES_USER:-postgres} -d postgres -v ON_ERROR_STOP=1 -qAt"
SESS="$($PSQL -c "SELECT count(*) FROM pg_stat_activity WHERE datname='$DB_NAME';")"
[ "$SESS" = "0" ] || { echo "ERROR: $SESS session(s) still on $DB_NAME — revoke first" >&2; exit 1; }
$PSQL -c "DROP DATABASE \"$DB_NAME\";"
$PSQL -c "DROP ROLE \"$DB_USER\";"
echo "OK dropped db=$DB_NAME user=$DB_USER"
