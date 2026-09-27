# Dependency Inventory / SBOM (Phase 26K.6)

A pragmatic software bill of materials. This is an inventory, not a
vulnerability audit — do not assume it is vulnerability-free; run your own
scanner (e.g. `composer audit`, `npm audit`, Trivy/Grype on the images).

Generated for `0.2.0-rc.1` · Regenerate on every release.

## Application (owner-console) — PHP

Authoritative source: `apps/owner-console/composer.lock` (exact resolved
versions ship with the repository).

Direct requirements:

| Package | Constraint |
|---|---|
| php | ^8.3 |
| filament/filament | ^5.8 |
| laravel/framework | ^13.17 |
| laravel/horizon | ^5.49 |
| laravel/pulse | ^1.8 |
| laravel/reverb | ^1.11 |
| laravel/sanctum | ^4.3 |
| laravel/tinker | ^3.0 |

## Phase 27/28 connector additions (0.2.0)

- The Connector SDK and the Supabase/MongoDB/Example-JSON connectors added
  **zero new PHP or npm runtime dependencies**: the MongoDB connector uses a
  pure-PHP wire-protocol client (no ext-mongodb), and the Supabase connector
  builds on the framework's HTTP client. No new container base images.
- `phpseclib`-style crypto was NOT introduced; secret encryption remains the
  framework's APP_KEY-based encryption.

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
