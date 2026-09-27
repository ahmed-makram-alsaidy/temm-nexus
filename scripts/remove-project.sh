#!/usr/bin/env sh
# Remove a project created by create-project.sh. DESTRUCTIVE — confirmation required.
# Usage: REMOVE_CONFIRM=<slug> remove-project.sh <slug>
# Removes: Caddy stanza, owner-console row, DB + role (data!), project dir.
set -eu
SLUG="${1:?usage: REMOVE_CONFIRM=<slug> $0 <slug>}"
[ "${REMOVE_CONFIRM:-}" = "$SLUG" ] || { echo "ERROR: set REMOVE_CONFIRM=$SLUG" >&2; exit 1; }
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB="$(printf '%s' "$SLUG" | tr '-' '_')_db"
ROLE="$(printf '%s' "$SLUG" | tr '-' '_')_user"
# Prevent msys/git-bash from rewriting /scripts/... container paths.
export MSYS_NO_PATHCONV=1
set -a; . "$ROOT/.env"; set +a

echo "==> backup first (safety)"
docker compose -f "$ROOT/docker-compose.yml" exec -T postgres sh /scripts/backups/backup-one-db.sh "$DB" || echo "WARN: backup failed/skipped"

echo "==> drop DB + role"
docker compose -f "$ROOT/docker-compose.yml" exec -T -e DROP_CONFIRM="$DB" postgres sh /scripts/postgres/drop-project-db.sh "$DB" "$ROLE"

echo "==> unregister + Caddy stanza"
docker compose -f "$ROOT/docker-compose.yml" exec -T postgres psql -h localhost -U "${POSTGRES_USER:-postgres}" \
  -d owner_console_db -v ON_ERROR_STOP=1 -q -c "DELETE FROM projects WHERE slug='$SLUG';"
# Remove stanza between BEGIN/END markers (awk, portable; prefix match).
awk -v s="# BEGIN $SLUG" -v e="# END $SLUG" 'index($0,s)==1{skip=1} !skip{print} index($0,e)==1{skip=0}' \
  "$ROOT/infrastructure/caddy/Caddyfile" > "$ROOT/infrastructure/caddy/Caddyfile.tmp" \
  && mv "$ROOT/infrastructure/caddy/Caddyfile.tmp" "$ROOT/infrastructure/caddy/Caddyfile"

echo "==> remove dir"
rm -rf "$ROOT/projects/$SLUG"
echo "OK project $SLUG removed (backup retained in backups/data)"
