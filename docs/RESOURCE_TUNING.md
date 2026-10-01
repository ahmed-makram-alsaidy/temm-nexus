# Resource tuning for the first VPS (Phase 15)

Target: 4 vCPU / 8 GB RAM / 80 GB SSD / ~300 registered users (NOT concurrent;
plan for ~30 concurrent, bursts to ~100).

## Measured idle footprint (local, 2026-09-16, `docker stats`)

| Service | Idle RSS | Limit |
|---|---|---|
| postgres:17 | 69 MB | 1536 MB |
| pgAdmin | 211 MB | 512 MB (local-only; NOT deployed to VPS, or deployed capped) |
| redis:8 | 23 MB | 512 MB |
| caddy | 12 MB | 256 MB |
| owner-console php-fpm | 84 MB | 512 MB |
| mailpit | 8 MB | 128 MB (local-only; not on VPS) |
| **Infra total (no pgAdmin/mailpit)** | **~180 MB idle** | — |

Per product app (estimate from owner-console php-fpm + Horizon + Reverb +
scheduler in one php image): **~150–250 MB idle**, ~400–600 MB under burst.
5 apps ≈ 1.0–1.5 GB. Postgres working set (shared_buffers 512 MB + connections)
≈ 0.8–1.2 GB. Redis ≤ 384 MB cap. OS + Docker daemon ≈ 1 GB.
**Comfortable headroom on 8 GB: ~3.5–4 GB free at expected load.**

## Pinned tunables (already in repo)

- Postgres (`infrastructure/postgres/postgresql.conf`): `shared_buffers 512MB`,
  `effective_cache_size 3GB`, `max_connections 100`, `work_mem 4MB`.
  Per-app DB pools: Laravel `DB_POOL` default 10–20; total connections budget
  5 apps × 15 + console 10 + monitor 5 ≈ 90 < 100.
- PHP-FPM (`conf/www.conf`): dynamic, max_children 20, start 4, spare 2–6,
  max_requests 1000. Memory cap per app: 512 MB container.
- Redis (`redis.conf`): `maxmemory 384mb`, `noeviction`, AOF everysec.
- Horizon: 1 supervisor per queue, `maxProcesses 5`, `balance auto` (per app).
- Reverb: default single process; scale horizontally later (Redis pub/sub already on).
- Logs: compose json-file 10m×3 per container; Laravel `daily` keep 14.

## Upgrade path (no rebuild)

- 8→16 GB: raise `shared_buffers` → 1 GB, `effective_cache_size` → 6 GB,
  FPM `max_children` → 30, Redis `maxmemory` → 768 MB (one compose env change each).
- 4→8 CPU: Horizon `maxProcesses` up, add `schedule:work` replicas.
- 80 GB→larger: move `/var/lib/docker` OR attach volume for `pgdata` +
  S3 offload for uploads (Phase 16). Postgres/Redis/storage each move to
  dedicated hosts by changing one hostname + secret (documented in runbooks).

## GATE 15

Idle measured ✅, headroom estimated ✅, 4vCPU/8GB/80GB confirmed acceptable ✅.

## Phase 18 measured footprint (2026-09-16, docker stats, with demo app + horizon + reverb running)

| Service | RSS | Notes |
|---|---|---|
| owner-console php-fpm | 21 MB | limit 512 MB |
| postgres:17 | 84 MB | limit 1536 MB, shared_buffers 512 MB reserved |
| redis:8 | 8 MB | maxmemory 384 MB cap |
| caddy | 13 MB | limit 256 MB |
| pgAdmin | 212 MB | local-only; STOP on VPS until needed |
| mailpit | 10 MB | local-only; not deployed |
| demo app (serve) | 93 MB | per-product app baseline |
| demo horizon (master+workers) | 152 MB | per-product queue tier |
| demo reverb | 55 MB | per-product realtime tier |

Per product runtime total: ~300 MB (serve + horizon + reverb). Five products ~= 1.5 GB; infra ~= 0.4 GB; OS/Docker ~= 1 GB; Postgres working set ~= 1 GB. Total ~= 4 GB of 8 GB � roughly half headroom at expected load.

Recommendation: keep pgAdmin stopped on the VPS (docker compose stop pgadmin) except during admin sessions; Horizon/Reverb run per product only where the product uses queues/realtime. Do not lower safety controls (no shared DB users, no disabled auth) to save RAM.

## GATE 18Q

Measured live. 4 vCPU / 8 GB / 80 GB REMAINS ACCEPTABLE � verdict YES.

