#!/usr/bin/env sh
# Ensure the platform database + login role exist and match the configured
# environment. Idempotent: safe on fresh AND existing volumes.
#
# Runs inside the postgres container (env_file provides the variables):
#   DB_DATABASE, DB_USERNAME, DB_PASSWORD      — platform database config
#   POSTGRES_USER, POSTGRES_PASSWORD           — superuser (bootstrap)
#
# Note: the role password is re-synced to DB_PASSWORD on every run so that a
# rotated password in .env takes effect without volume deletion.
set -eu

: "${DB_DATABASE:?DB_DATABASE must be set}"
: "${DB_USERNAME:?DB_USERNAME must be set}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
SUPERUSER="${POSTGRES_USER:-postgres}"
PSQL="psql -h localhost -U $SUPERUSER -d postgres -v ON_ERROR_STOP=1 -tAc"

# Identifiers are validated (no quoting games); passwords go through psql
# as a single-quoted literal — generated secrets are hex, no quotes inside.
case "$DB_DATABASE" in *[!a-z0-9_]*|""|*[A-Z]*) echo "ERROR: invalid DB_DATABASE" >&2; exit 1;; esac
case "$DB_USERNAME" in *[!a-z0-9_]*|""|*[A-Z]*) echo "ERROR: invalid DB_USERNAME" >&2; exit 1;; esac
case "$DB_PASSWORD" in *"'"*) echo "ERROR: DB_PASSWORD must not contain single quotes" >&2; exit 1;; esac

if [ "$($PSQL "SELECT 1 FROM pg_roles WHERE rolname='$DB_USERNAME'")" = "1" ]; then
  $PSQL "ALTER ROLE \"$DB_USERNAME\" WITH LOGIN PASSWORD '$DB_PASSWORD';"
else
  $PSQL "CREATE ROLE \"$DB_USERNAME\" LOGIN PASSWORD '$DB_PASSWORD';"
fi

if [ "$($PSQL "SELECT 1 FROM pg_database WHERE datname='$DB_DATABASE'")" = "1" ]; then
  $PSQL "GRANT ALL PRIVILEGES ON DATABASE \"$DB_DATABASE\" TO \"$DB_USERNAME\";"
else
  $PSQL "CREATE DATABASE \"$DB_DATABASE\" OWNER \"$DB_USERNAME\";"
fi

echo "platform database '$DB_DATABASE' ready for '$DB_USERNAME'"
