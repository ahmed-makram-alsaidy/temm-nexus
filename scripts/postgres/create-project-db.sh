#!/usr/bin/env sh
# Create an isolated project database + least-privilege role.
# Usage: create-project-db.sh <db_name> <db_user> [password]
#   - password omitted  -> securely generated and printed ONCE (store in project .env)
#   - reads POSTGRES_USER/POSTGRES_PASSWORD from repo .env if set
#   - validates identifiers to block SQL injection via arguments
set -eu

DB_NAME="${1:?usage: $0 <db_name> <db_user> [password]}"
DB_USER="${2:?usage: $0 <db_name> <db_user> [password]}"
DB_PASS="${3:-}"

valid() { printf '%s' "$1" | grep -Eq '^[a-z][a-z0-9_]{0,62}$'; }
valid "$DB_NAME" || { echo "ERROR: invalid db name '$DB_NAME' (a-z, 0-9, _, start with letter)" >&2; exit 1; }
valid "$DB_USER" || { echo "ERROR: invalid db user '$DB_USER'" >&2; exit 1; }

if [ -z "$DB_PASS" ]; then
  DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
  GENERATED=1
else
  GENERATED=0
fi

export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set (source .env)}"
SUPERUSER="${POSTGRES_USER:-postgres}"
PSQL="psql -h "${PGHOST:-localhost}" -U $SUPERUSER -d postgres -v ON_ERROR_STOP=1 -qAt"

if [ "$($PSQL -c "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'")" = "1" ]; then
  echo "ERROR: role '$DB_USER' already exists — refusing to overwrite" >&2; exit 1
fi
if [ "$($PSQL -c "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'")" = "1" ]; then
  echo "ERROR: database '$DB_NAME' already exists — refusing to overwrite" >&2; exit 1
fi

ESCAPED_PASS="$(printf '%s' "$DB_PASS" | sed "s/'/''/g")"
$PSQL -c "CREATE ROLE \"$DB_USER\" WITH LOGIN PASSWORD '$ESCAPED_PASS' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION;"
$PSQL -c "CREATE DATABASE \"$DB_NAME\" OWNER \"$DB_USER\" ENCODING 'UTF8';"
# Least privilege: only the owner role may CONNECT; revoke defaults from PUBLIC.
$PSQL -c "REVOKE CREATE ON DATABASE \"$DB_NAME\" FROM PUBLIC;"
$PSQL -c "REVOKE CONNECT ON DATABASE \"$DB_NAME\" FROM PUBLIC;"
$PSQL -c "GRANT CONNECT ON DATABASE \"$DB_NAME\" TO \"$DB_USER\";"
# Harden the public schema inside the new DB.
psql "host=${PGHOST:-localhost} user=$SUPERUSER dbname=$DB_NAME" -v ON_ERROR_STOP=1 -q \
  -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" \
  -c "GRANT ALL ON SCHEMA public TO \"$DB_USER\";" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE \"$DB_USER\" IN SCHEMA public GRANT ALL ON TABLES TO \"$DB_USER\";" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE \"$DB_USER\" IN SCHEMA public GRANT ALL ON SEQUENCES TO \"$DB_USER\";"

echo "OK db=$DB_NAME user=$DB_USER"
if [ "$GENERATED" = "1" ]; then
  echo "GENERATED_PASSWORD=$DB_PASS"
  echo "NOTE: password printed once — store it in the project .env now." >&2
fi
