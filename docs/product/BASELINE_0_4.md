# TEMM Nexus — Phase 0.4.0 Baseline Record

**Captured:** before any 0.4.0 change
**Stable base:** v0.3.0
**Stable tag commit:** `ad693fc` (`ad693fcddcda394bd7dbc9efcab2cb8fbeb8249a`)
**Stable modified:** NO
**Development line:** `develop/0.4.0`, created from `v0.3.0`

> This document is the frozen reference for every "regression?" question in the
> 0.4.0 phase gates. It must not be edited after Phase A closes; append
> addenda instead.

---

## 1. Repository

| Item | Value |
| --- | --- |
| Application repo | `temm-nexus/` (nested repository) |
| Root repo `E:\Laravel Infrastructure` | empty (no commits) — **not** the release repo |
| Branch at capture | `release/0.3.0` |
| HEAD | `ad693fc` |
| `git describe --tags --exact-match` | `v0.3.0` |
| Working tree | clean |
| Remote | `https://github.com/ahmed-makram-alsaidy/temm-nexus.git` |
| Existing tags | `v0.2.0-rc.2`, `v0.2.0-rc.3`, `v0.3.0-rc.1`, `v0.3.0-rc.2`, `v0.3.0-rc.3`, `v0.3.0` |

**Verified:** `git rev-list -n1 v0.3.0` == HEAD == `ad693fc` at capture, and again
after branching. The tag was not moved, deleted, or re-pointed.

## 2. Toolchain

| Item | Value |
| --- | --- |
| Host default PHP | **8.3.33** — too old |
| Required PHP | `>= 8.4.1` (enforced by `vendor/composer/platform_check.php`) |
| PHP used for all 0.4.0 work | **8.4.25** at `…\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe` |
| Extensions present (8.4) | `bcmath curl dom fileinfo gd intl mbstring openssl pdo_sqlite sqlite3 tokenizer xml zip` |
| Extension missing | **`redis` (phpredis)** — see §4 |
| Framework | Laravel 13, Filament 5.8, PHPUnit 12.5.35 |
| Composer | not on PATH; `public-release/_composer.phar` available |
| Node / npm | v24.18.0 / 11.16.0 |
| Playwright browsers | `ms-playwright/chromium-1243` (real-browser QA) |

## 3. Disk

| Drive | Free |
| --- | --- |
| C: | 5.98 GB |
| D: | 22.81 GB |
| **E: (repo drive)** | **0.10 GB** |
| G: | 11.14 GB |

**Constraint:** the repository drive has ~100 MB free. Build outputs, screenshot
archives, and test caches must not be written to E:.

## 4. Test baseline

### Full suite (`phpunit.xml`)

```
Tests: 427, Assertions: 2202, Errors: 1, Failures: 10, Skipped: 3
```

### Blocking release suite (`phpunit-release.xml`) — the release gate

```
Tests: 427, Assertions: 2202, Errors: 1, Failures: 10, Skipped: 3
Runtime: 03:17.675, Memory: 192.00 MB
```

**BASELINE IS RED — 10 failures + 1 error, all in
`tests/Feature/Phase26/SetupWizardTest.php`.**

**Root cause: host environment only, not a code regression.**

```
Error: Class "Redis" not found
  vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php:84
    ← App\Services\HealthService.php:19
    ← App\Http\Controllers\Api\HealthController.php:13
```

`App\Services\HealthService` probes Redis unconditionally. With no `Redis` class
the health endpoint returns **503**, which cascades:

| # | Failing test | Proximate cause |
| --- | --- | --- |
| 1 | `test_fresh_instance_keeps_health_probes_reachable` | 503 from missing `Redis` class |
| 2 | `test_full_wizard_creates_exactly_one_platform_owner` | wizard step blocked by failed system check |
| 3 | `test_weak_password_is_rejected` | never reaches validation |
| 4 | `test_default_style_admin_email_is_rejected` | never reaches validation |
| 5 | `test_replaying_wizard_steps_does_not_duplicate_admin_or_settings` | wizard never completes |
| 6 | `test_setup_is_locked_after_completion` | setup never completes |
| 7 | `test_replay_of_completion_after_lock_redirects_without_duplicates` | setup never completes |
| 8 | `test_system_check_reports_all_expectations_and_passes_on_test_stack` | blocking Redis check fails |
| 9 | `test_setup_reset_command_requires_confirmation` | platform not initialized |
| 10 | `test_setup_reset_command_unlocks_when_confirmed` | platform not initialized |
| E1 | `test_bootstrapped_admin_can_reach_dashboard` | `User::first()` is null → "password on null" |

**Decision (operator):** record as an **environment-only baseline red**. No code
change is made for it on 0.4.0. Every 0.4.0 gate compares against **exactly this
set of 11 findings** — any *additional* failure is a 0.4.0 regression; this set
disappearing without a code change is not required.

Raw logs: `release-artifacts/baseline-release-suite-0.3.0.txt`,
`release-artifacts/baseline-tests-0.3.0.txt`.

## 5. Doctor (`php artisan platform:doctor`)

```
 6 pass · 6 warnings · 3 failures · 8 not configured · 1 n/a
```

| Check | State | Note |
| --- | --- | --- |
| Platform version | PASS | reports `0.3.0-rc.1` ← **stale, see §6** |
| APP_ENV | WARNING | `local` |
| APP_DEBUG | WARNING | enabled |
| APP_KEY | PASS | present |
| PostgreSQL | PASS | *(SQLite is the configured driver — probe is optimistic)* |
| **Redis** | **FAIL** | unreachable — no redis extension/service |
| Storage writable | PASS | |
| Required directories | PASS | |
| Queue worker (Horizon) | WARNING | status indeterminate |
| Scheduler heartbeat | WARNING | no heartbeat |
| Realtime (Reverb) | NOT_CONFIGURED | `REVERB_APP_KEY` unset |
| Reverse proxy (Caddy) | N/A | self-host edge config |
| Public URL | WARNING | `http://localhost:8000` |
| HTTPS expectation | NOT_CONFIGURED | not https |
| Backup destinations | NOT_CONFIGURED | none defined |
| Last successful backup | NOT_CONFIGURED | none recorded |
| Last restore drill | NOT_CONFIGURED | none verified |
| **Disk space** | **FAIL** | 0.1 GB free — below 2 GB floor (E:) |
| Pending migrations | PASS | schema up to date |
| Platform initialization | WARNING | not initialized |
| First admin | FAIL | none (by design before `/setup`) |
| Setup lock | NOT_CONFIGURED | setup incomplete |
| AI providers | NOT_CONFIGURED | none enabled — platform works without AI |
| Mail | NOT_CONFIGURED | mailer `log` |

## 6. Baseline defects observed (carried into 0.4.0)

These are **pre-existing** and are recorded, not fixed, at baseline:

1. **Brand identity wrong.** Panel brand name renders as **"Backend Control
   Plane"** (`AdminPanelProvider::brandName('Backend Control Plane')`) instead of
   "TEMM Nexus" (`config('platform.brand')`).
2. **Version resolution is fragile.** `config/platform.php` checked
   `base_path('VERSION')` before the repo-root `VERSION`. The audit instance's
   footer and `platform:doctor` displayed `0.3.0-rc.1` while the release is
   `0.3.0`.

   **Corrected during Phase C (verified):** at `v0.3.0` the tracked
   `apps/owner-console/VERSION` is an **empty blob** (git empty-file hash
   `e69de29b…`), and `base_path('VERSION')` resolves to it under
   `artisan serve`. The `0.3.0-rc.1` text came from a **local, untracked**
   artifact written by release/deploy tooling — it is *not* part of the frozen
   release. The genuine defect is the **precedence order**: any deployment that
   writes a version file into the application root silently overrides the
   canonical repo-root `VERSION`. Fixed in 0.4.0 by checking the repo root
   first.
3. **Dead navigation targets.** `/admin/team-management` and
   `/admin/project-switcher` return **404**. Real routes are `/admin/team` and
   `/admin/switcher`. Confirmed in the real browser.
4. **`.env.example` is not loadable.** `APP_NAME=TEMM Nexus` is unquoted, so
   Laravel's dotenv parser throws `Encountered unexpected whitespace at [TEMM
   Nexus]`. A fresh `composer setup` fails at `key:generate`.

## 7. Scale of the surface to redesign

| Metric | Count |
| --- | --- |
| Total routes | 121 (105 GET) |
| Admin GET routes | 56 |
| Admin panel pages (non-project) | 11 |
| **Project-scoped pages** | **47** |
| Project sidebar groups (v0.3.0) | 5 (`Database`, `Authentication`, `Build`, `Operate`, `Project`) |
| Eloquent models | 59 |
| Migrations | 19 |
| Test files | 96 |
| `cp.css` design layer | 845 lines (dark + light token sets) |
| `cp-erd.js` | 32.7 KB |

### Project sidebar inventory (v0.3.0)

| Group | Pages |
| --- | --- |
| Database | Tables, ERD, SQL Editor, Schema, Functions, Inspector, Migrations, Schema Diff, Health, Connections |
| Authentication | Users, Roles, Permissions, Sessions, Providers, Secrets |
| Build | Storage, API, Connect, API Keys, Functions, Realtime, Webhooks, Scheduler |
| Operate | Logs, Queues, Monitoring, Backups, Infrastructure, Resources, Readiness |
| Project | Environments, Migration Center, Client Repository, AI Copilot |
| *(ungrouped)* | Overview, Settings |

### Global (non-project) navigation inventory (v0.3.0)

| Group | Pages |
| --- | --- |
| *(ungrouped)* | Dashboard, Search |
| Projects | Onboarding Wizard, Project Switcher, Projects |
| Infrastructure | Nodes, Services, Health, Topology |
| Governance | Audit Log, Team |
| Migration center | Connector catalog |

## 8. Browser baseline

Captured with Playwright (Chromium 1243) at **1440×900**, authenticated as a
local demo owner against `http://127.0.0.1:8123`.

| Page | Route | HTTP |
| --- | --- | --- |
| Dashboard | `/admin` | 200 |
| Projects | `/admin/projects` | 200 |
| Search | `/admin/search` | 200 |
| Onboarding | `/admin/onboarding` | 200 |
| Infra Health | `/admin/infra-health` | 200 |
| Infra Nodes | `/admin/infra-nodes` | 200 |
| Infra Services | `/admin/infra-services` | 200 |
| Infra Topology | `/admin/infra-topology` | 200 |
| Connector Catalog | `/admin/connector-catalog` | 200 |
| Team (correct URL) | `/admin/team` | 200 |
| Switcher (correct URL) | `/admin/switcher` | 200 |
| ~~Team~~ | `/admin/team-management` | **404** |
| ~~Project Switcher~~ | `/admin/project-switcher` | **404** |

**500 errors: 0. Broken assets: 0.**

Screenshots: `temm-qa/shots/baseline-*.png` (external to the repo, see §3).

### Visual state of the baseline Home page

- Generic Filament shell: topbar with brand + global search + avatar, left
  sidebar with 5 collapsible groups.
- Metric-card row carries infrastructure detail (**DB STORAGE**, **LAST BACKUP**)
  *above* user-relevant information — the exact §7 inversion 0.4.0 must fix.
- Content ends ~500 px down at 1440×900, leaving the majority of the viewport
  empty while the useful "Needs attention" list is one line of prose.
- Raw internal vocabulary in the first screen: `DB STORAGE`, `LOCAL`,
  `UNKNOWN`, `demo_project_a_db · pending`.
- No workspace/client grouping anywhere.

## 9. Local development instance (throwaway)

| Item | Value |
| --- | --- |
| Server | `php artisan serve` → `http://127.0.0.1:8123` |
| Driver | SQLite at `apps/owner-console/database/database.sqlite` |
| Fixtures | `OwnerConsoleSeeder` with `ENABLE_DEMO_SEED=true` (**local only**) |
| Demo owner | `demo-owner@localhost.test`, random password printed once |

This instance is **local, disposable, and contains no customer data**. No
production or customer system is contacted by any 0.4.0 step.

## 10. Baseline gate

| Item | Status |
| --- | --- |
| v0.3.0 tag unmodified | **PASS** |
| v0.3.0 tree unmodified | **PASS** |
| Clean tree before branching | **PASS** |
| `develop/0.4.0` created from `v0.3.0` | **PASS** |
| Release suite executed & recorded | **PASS** (red, environment-only — §4) |
| Doctor executed & recorded | **PASS** |
| Routes recorded | **PASS** |
| Navigation recorded | **PASS** |
| Schema recorded | **PASS** |
| Browser baseline + screenshots | **PASS** |

**Baseline status: ESTABLISHED.**
