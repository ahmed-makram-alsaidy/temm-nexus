#!/usr/bin/env sh
# Read-only inspections (safe for owner-console read model + runbooks).
set -eu
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
PSQL="psql -h "${PGHOST:-localhost}" -U ${POSTGRES_USER:-postgres} -d postgres -v ON_ERROR_STOP=1"
case "${1:?usage: $0 {sizes|connections|slow|tables <db>}}" in
  sizes)       $PSQL -c "SELECT datname AS db, pg_size_pretty(pg_database_size(datname)) AS size FROM pg_database WHERE datistemplate=false ORDER BY pg_database_size(datname) DESC;" ;;
  connections) $PSQL -c "SELECT datname AS db, usename AS usr, count(*) AS conns, max(now()-backend_start) AS oldest FROM pg_stat_activity GROUP BY 1,2 ORDER BY 3 DESC;" ;;
  slow)        $PSQL -d "${2:-postgres}" -c "SELECT query, calls, round(mean_exec_time::numeric,1) AS mean_ms, round(max_exec_time::numeric,1) AS max_ms FROM pg_stat_statements ORDER BY mean_exec_time DESC LIMIT 20;" ;;
  tables)      $PSQL -d "${2:?usage: $0 tables <db>}" -c "SELECT count(*) AS tables, pg_size_pretty(pg_database_size(current_database())) AS db_size FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema');" ;;
  *) echo "unknown: $1" >&2; exit 1 ;;
esac
