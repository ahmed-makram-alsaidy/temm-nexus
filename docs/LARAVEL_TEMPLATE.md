# Laravel project template (Phase 5)

Derivation recipe (automated in `scripts/create-project.sh`, Phase 13):

1. `composer create-project laravel/laravel:^13.0 <dir>`
2. `composer require laravel/sanctum laravel/horizon laravel/pulse laravel/reverb -W --ignore-platform-req=ext-pcntl`
3. `php artisan install:api && horizon:install && reverb:install`
   + `vendor:publish --tag=pulse-config,pulse-migrations`
4. Copy `projects/_template/overlay/` over the skeleton.
5. Write `.env` from `projects/_template/.env.example` (DB role, redis prefix).
6. `php artisan migrate --force` → `php artisan test --filter=TemplateGateTest`.

## GATE 5 evidence (2026-09-16, live local run, disposable `projects/_tmp_gate5`)

- `migrate --force` on dedicated `template_test_db`: users, cache, jobs,
  `audit_logs`, sanctum tokens, pulse tables — all DONE ✅
- `artisan test --filter=TemplateGateTest`: **4 passed (11 assertions)** ✅
  (health ok:true, DB select 1, Redis ping+cache round-trip, TemplateProbeJob
  dispatched to `gate5-default` redis queue and processed by `queue:work --once`)
- Real HTTP: `GET /api/health` → 200
  `{"ok":true,…,"database":{"ok":true},"redis":{"ok":true}}` ✅
  (via `artisan serve`, fetched with wget from a sibling container)
- Known host quirk (not app): one-off `docker run -p` on this Docker Desktop host
  shows `Ports: {"8000/tcp":[]}` (mapping not realized), while compose-published
  ports (Mailpit 127.0.0.1:8026 → 200 OK from Windows host) work fine.
  Production serves behind Caddy `php_fastcgi`, unaffected.

## Fixes found while gating

- Overlay `bootstrap/app.php` used `Request::HEADER_X_FORWARDED_AWARE`
  (absent in this Symfony minor) → replaced with `trustProxies(at: '*')`.
- Overlay must not call `->withBootstrappers([])` and must not place code after
  `return …->create()` (dead code) → rate limiter lives in `AppServiceProvider`.
- Pulse 1.8 has no `pulse:install` command → publish `pulse-config` +
  `pulse-migrations` tags instead.
