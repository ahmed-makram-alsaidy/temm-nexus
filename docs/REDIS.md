# Redis architecture (Phase 4)

Server: `redis:8.2-alpine`, internal networks only, auth required
(`--requirepass $REDIS_PASSWORD`; no password in committed files).
Persistence: AOF everysec + RDB snapshots; `maxmemory 384mb`, `noeviction`
(queues must never silently drop — memory pressure surfaces as errors, not loss).

## Isolation model (shared server, logically separated)

No `SELECT` logical DBs (deprecated in Redis 8 / cluster-incompatible) and no
`FLUSHDB` ever. Each project gets:

| Concern | Convention |
|---|---|
| Cache prefix | `{project}:cache:` (`CACHE_PREFIX` in project `.env`) |
| Queue names | `{project}-default`, `{project}-high`, … |
| Session prefix | `{project}:session:` (only where Redis sessions fit) |
| Horizon prefix | `{project}:horizon:` |
| Broadcast/Reverb | separate channels per project, app-id scoped |

Laravel template wires these from `REDIS_PREFIX` (see `projects/_template/.env.example`).
Redis ACL users per project are a documented upgrade path once a project needs
hard command-level separation (`docs/RESOURCE_TUNING.md`).

## Scripts (`scripts/redis/`, mounted at `/scripts/redis`)

```powershell
docker compose exec -T redis sh /scripts/redis/check-isolation.sh <prefix_a> <prefix_b>
$env:FLUSH_CONFIRM='<prefix>'; docker compose exec -T -e FLUSH_CONFIRM=$env:FLUSH_CONFIRM redis sh /scripts/redis/flush-namespace.sh <prefix>
```

`flush-namespace.sh` uses SCAN + DEL under `<prefix>:*` only — never FLUSHDB/FLUSHALL.

## GATE 4 evidence (2026-09-16, live local run)

- `check-isolation.sh gateapp_a gateapp_b` → each `SCAN <prefix>:*` listed only its
  own canary + queue set; `OK isolation holds` ✅
- Seeded `gateapp_a:keep` + `gateapp_b:keep`, flushed only `gateapp_a` → `deleted 1`,
  `MGET` showed A gone / B surviving, then B cleaned explicitly ✅
- Post-gate `SCAN gateapp_*` → empty, `EXISTS` → 0 (no residue) ✅
