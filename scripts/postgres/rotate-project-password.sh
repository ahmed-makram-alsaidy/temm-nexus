#!/usr/bin/env sh
# Rotate a project role password. Prints the new password ONCE.
# Usage: rotate-project-password.sh <db_user> [new_password]
set -eu
DB_USER="${1:?usage: $0 <db_user> [new_password]}"
printf '%s' "$DB_USER" | grep -Eq '^[a-z][a-z0-9_]{0,62}$' || { echo "ERROR: invalid user" >&2; exit 1; }
NEW_PASS="${2:-$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)}"
export PGPASSWORD="${POSTGRES_PASSWORD:?POSTGRES_PASSWORD must be set}"
ESCAPED="$(printf '%s' "$NEW_PASS" | sed "s/'/''/g")"
psql -h "${PGHOST:-localhost}" -U "${POSTGRES_USER:-postgres}" -d postgres -v ON_ERROR_STOP=1 -q \
  -c "ALTER ROLE \"$DB_USER\" WITH PASSWORD '$ESCAPED';"
echo "OK rotated user=$DB_USER"
echo "NEW_PASSWORD=$NEW_PASS"
echo "NOTE: update the project .env + restart workers, old sessions drop." >&2
