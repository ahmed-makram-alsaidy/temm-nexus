# Backups

The platform ships with a Backup Center (in the admin UI) for scheduled,
destination-backed backups with retention and restore drills, plus scripts
for staging raw `pg_dump` files.

## What to back up

| Data | Location | Method |
|---|---|---|
| Platform database (owners, projects, settings, audit) | `owner_console` database in the `postgres` service | pg_dump |
| Project databases | one database per project in the same PostgreSQL instance | pg_dump |
| Project uploads | Docker volume `backend-plane-app-storage` | volume or file backup |
| TLS certificates + platform configuration | `.env`, Caddy data volume | file backup |

## Manual backup (any time)

```bash
PGUSER=$(grep -E '^POSTGRES_USER=' .env | cut -d= -f2)
PGPASS=$(grep -E '^POSTGRES_PASSWORD=' .env | cut -d= -f2)
DB=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2)

docker compose -f docker-compose.prod.yml exec -T postgres \
  sh -lc "PGPASSWORD='$PGPASS' pg_dump -U '$PGUSER' -d '$DB' -Fc" > backups/data/manual-$(date +%F).dump
```

Repeat per project database (list databases inside the postgres container).
Pre-upgrade backups are taken automatically by `scripts/upgrade.sh`.

## Backup Center (scheduled)

Admin console → **Backup Center**: define destinations (local staging or
external), policies per project (frequency, retention), trigger manual
backups, and verify restores. Statuses surface in the dashboard.

## Restore drill

A backup you have never restored is a hope, not a backup. Run a restore
drill regularly:

1. Restore the dump into a **disposable** database (never over the live one
   during a drill):
   ```bash
   docker compose -f docker-compose.prod.yml exec -T postgres \
     sh -lc "PGPASSWORD='$PGPASS' pg_restore -U '$PGUSER' -d '<drill-db>' --no-owner /backups/<file>.dump"
   ```
   (`/backups` inside the container is `backups/data` on the host.)
2. Spot-check tables, users, recent records.
3. Drop the drill database.

Full walkthrough including uploads: [docs/open-source/BACKUP_RESTORE.md](docs/open-source/BACKUP_RESTORE.md).

## Retention guidance

- Keep at least one backup outside the same server (off-box or object storage).
- Keep pre-upgrade backups until the upgraded version has run for a full week.
- Test restores monthly; record the drill in the Backup Center.
