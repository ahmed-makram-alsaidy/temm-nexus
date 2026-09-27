# Backup & Restore Walkthrough

## Backup inventory

| Data | Where | Backed up by |
|---|---|---|
| Platform + project databases | postgres volume | Backup Center / pg_dump |
| Uploads & private files | app storage volume | file/volume backup |
| Configuration & secrets | .env (0600) | operator file backup |
| TLS certs | caddy data volume | volume backup (re-issuable) |

## Scheduled backups

Backup Center (admin UI) defines destinations, per-project policies
(frequency/retention) and tracks runs; failures surface on the dashboard.

## Manual dump (platform DB)

```bash
PGUSER=$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2)
PGPASS=$(grep -E '^POSTGRES_PASSWORD=' .env | cut -d= -f2)
DB=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)
docker compose -f docker-compose.prod.yml exec -T postgres \
  sh -lc "PGPASSWORD='$PGPASS' pg_dump -U '$PGUSER' -d '$DB' -Fc" \
  > backups/data/platform-$(date +%F).dump
```

## Restore drill (disposable database — never live)

```bash
# 1. create a scratch database, then restore into it
docker compose -f docker-compose.prod.yml exec -T postgres \
  sh -lc "PGPASSWORD='$PGPASS' psql -U '$PGUSER' -c 'CREATE DATABASE drill_restore;'"
docker compose -f docker-compose.prod.yml exec -T postgres \
  sh -lc "PGPASSWORD='$PGPASS' pg_restore -U '$PGUSER' -d drill_restore --no-owner /backups/platform-YYYY-MM-DD.dump"
# 2. spot-check tables/records, then drop the scratch DB
```

`/backups` in the container maps to backups/data on the host.

## Real restore (incident)

1. Stop application services (`compose stop app horizon scheduler reverb`).
2. Drop/recreate the target database, then restore **with the right ownership
   flags** — verified in the 26.1 restore drill:

   ```bash
   # the app role must OWN the database BEFORE pg_restore (Postgres 15+ makes
   # the public schema non-writable for non-owners):
   PGPASSWORD='$PGPASS' psql -U "$PGUSER" -d postgres \
     -c "DROP DATABASE IF EXISTS $DB;" -c "CREATE DATABASE $DB;" \
     -c "ALTER DATABASE $DB OWNER TO $DBUSERNAME;"
   # then restore, re-assigning objects to the app role:
   PGPASSWORD='$PGPASS' pg_restore -U "$PGUSER" -d "$DB" \
     --no-owner --role="$DBUSERNAME" --exit-on-error /backups/<file>.dump
   ```

   Without `--role` + pre-assigned ownership, the restored tables belong to
   the superuser and the application gets `permission denied`.
3. Restore the storage volume from your volume/file backup.
4. Start the stack, verify /api/health + login + project data.

## After an upgrade

Keep pre-upgrade backups (upgrade.sh creates one automatically) until the
new version has run for a full week.
