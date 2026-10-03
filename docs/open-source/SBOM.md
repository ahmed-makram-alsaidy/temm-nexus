# Dependency Inventory / SBOM (Phase 26K.6)

A pragmatic software bill of materials. This is an inventory, not a
vulnerability audit — do not assume it is vulnerability-free; run your own
scanner (e.g. `composer audit`, `npm audit`, Trivy/Grype on the images).

Generated for `0.4.0` · Regenerate on every release.

> 0.4.0 note: the dependency inventory is UNCHANGED from 0.3.0 — Phases
> A–K and the Phase 41 product closure (AI settings, New Project wizard,
> Arabic/English localization) added no new PHP or npm runtime dependency.
> 133 runtime + 33 dev entries in the lock at 0.4.0.
> `composer audit` at 0.4.0: **0 security advisories**.

## Application (owner-console) — PHP

Authoritative source: `apps/owner-console/composer.lock` (exact resolved
versions ship with the repository; 133 packages at 0.3.0).
`composer audit` at release time: 0 security advisories.

Direct requirements:

| Package | Constraint |
|---|---|
| php | ^8.3 |
| filament/filament | ^5.8 |
| krowinski/php-mysql-replication | ^11.1 |
| laravel/framework | ^13.17 |
| laravel/horizon | ^5.49 |
| laravel/pulse | ^1.8 |
| laravel/reverb | ^1.11 |
| laravel/sanctum | ^4.3 |
| laravel/tinker | ^3.0 |

## Phase 29-32/35.6 connector additions (0.3.0)

- **`krowinski/php-mysql-replication` ^11.1** — the ONE new PHP runtime
  dependency: MySQL/MariaDB row-based binlog decoding for real CDC
  (Phase 35.6). Pure PHP, no ext-* requirement. The GTID-dump packet of
  this library receives only heartbeats on MySQL 8.0 (verified live), so
  the platform uses file+position resume with the GTID set recorded as
  evidence.
- `league/commonmark` upgraded to 2.10.3 (security advisories), locked.
- The Firebase (Phase 29), generic PostgreSQL (Phase 30) and CDC core
  (Phase 32) additions added **no other new PHP or npm runtime
  dependencies**: PostgreSQL logical decoding and the MongoDB change
  stream are pure-PHP wire implementations. No new container base images.

## Client SDKs — JavaScript / Dart / PHP

| Package | Source of truth |
|---|---|
| `packages/backend-sdk-js` | package-lock.json / npm |
| `packages/backend-sdk-php` | composer.json (self-contained, no framework dependency) |
| `packages/backend_sdk_dart` | pubspec.yaml / pub |

## Container base images

| Image | Used for |
|---|---|
| php:8.4.25-fpm-alpine | application runtime (app, worker, scheduler, reverb) |
| postgres:17-alpine | PostgreSQL 17 database |
| redis:8-alpine | Redis cache/queues |
| caddy:2-alpine | TLS edge / reverse proxy |

Pin strategy: minor-version tags (e.g. `postgres:17-alpine`); the production
stack pulls these at install time. For fully pinned installs, operators may
pin digests in `docker-compose.prod.yml`.

## Runtime tooling (build/install)

| Tool | Role |
|---|---|
| Docker Engine + Compose plugin | deployment |
| Composer 2.8 (installed inside image build) | PHP dependencies |
| Node/npm (dev only) | SDK build/test |

## SDK/CI test tooling

phpunit ^12, faker, mockery, collision, pint, laravel/pail (see
`apps/owner-console/composer.json` require-dev).
