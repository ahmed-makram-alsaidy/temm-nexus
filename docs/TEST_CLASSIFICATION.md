# Test suite classification (Phase 36)

The repository's PHPUnit tree is NOT uniformly hermetic. This document is
the canonical classification of every suite, what each category requires,
and which ones gate a release. It exists because calling "the full suite
green" would be dishonest: part of it cannot run without operator
workstation fixtures, and lumping it into the release gate would either
fake a green badge or block releases on unrelated environment debt.

Release gating uses **`apps/owner-console/phpunit-release.xml`** — the
hermetic blocking suite. It must report **0 failures, 0 errors**.

## A. Hermetic blocking release tests

`vendor/bin/phpunit -c phpunit-release.xml` (working directory
`apps/owner-console`). SQLite in-memory, array cache, `FakeAiProvider`,
no network, no docker, no operator fixtures. Covers Phases 24-35.6
(connectors, migration engine, CDC core + 35.6 real-capture units, cutover
center, AI copilot, marketplace, doctor, backups UI, support bundle,
scheduler heartbeat) plus the SDK suites and infrastructure script tests
(run separately, see B). Excluded from it are exactly the suites in C and
D below; every exclusion is commented in `phpunit-release.xml`.

The JS/PHP SDK suites (`packages/backend-sdk-js`,
`packages/backend-sdk-php`) and `scripts/tests/*.sh` are equally hermetic
and equally blocking (CI runs them as dedicated jobs).

## B. Live self-contained tests (blocking, spin up their own stack)

These need docker but nothing else — they build/run their own disposable
stack, so CI runs them blocking:

- **fresh-install job** — `scripts/install.sh` against a real compose
  stack, then the setup gate and edge asset delivery checks (Filament
  CSS/JS/fonts, Livewire, MIME types).
- **container-build job** — production image build + generated frontend
  asset assertion + compose config validation.
- **script tests** — `scripts/tests/fpm-healthcheck.test.sh`,
  `scripts/tests/installer-banner.test.sh`.

The Phase 35.5/35.6 **live connector/CDC verification** (PostgreSQL WAL,
MySQL binlog, MongoDB change streams against disposable sources) is
executed by operator scripts (see `docs/connectors/REAL_CDC_RUNBOOK.md`
and the `dev-php84` helpers); it is not part of CI but is mandatory
evidence for a release that claims CDC support.

## C. Operator/gate-fixture tests (NOT blocking, NOT hermetic)

These tests exercise the Phase 20/21/22 control-plane studios against live
project databases. On a machine without the operator fixtures they ERROR
(not skip) — with one aggravating effect: a mid-transaction failure leaves
an open transaction on the cached SQLite connection and every subsequent
test in the same process dies with "cannot start a transaction within a
transaction". On a fixture-less machine the broad `phpunit.xml` suite
therefore reports hundreds of errors that are ALL this cascade, not
regressions (observed: 479 errors / 4 failures locally vs the Phase 35.6
operator-workstation baseline of 86 errors / 30 failures).

Affected files (excluded from `phpunit-release.xml`):

| File | Fixture required |
|------|------------------|
| `tests/Feature/Phase20/*` (whole directory) | `gate-a` / `gate-b` projects with resolvable database credentials + reachable `postgres` host |
| `tests/Feature/Phase21/ErdServiceTest.php` | gate-a/gate-b |
| `tests/Feature/Phase21/ServiceMappingTest.php` | gate-a/gate-b |
| `tests/Feature/Phase22/ConnectPageTest.php` | `gate-project` project |
| `tests/Feature/ControlPlaneTest.php` | gate-a/gate-b |
| `tests/Feature/ControlPlaneSecurityTest.php` | gate-a/gate-b |
| `tests/Feature/BackupsAuditTest.php` | `control_plane_demo_db` demo database |
| `tests/Feature/RecordBrowserTest.php` | control-plane demo database |
| `tests/Feature/WorkspacePagesTest.php` | control-plane demo database |
| `tests/Feature/TemplateGateTest.php` | live Redis extension + reachable docker stack |

What they need, concretely: the operator compose stack running (a
`postgres` host resolvable from PHP), control-plane projects with slug
`gate-a`, `gate-b`, `gate-project` whose credentials resolve through
`ProjectConnectionManager`, and the disposable `control_plane_demo_db`
database. How to run them: on the operator workstation with that stack up,
`vendor/bin/phpunit` (default `phpunit.xml`) — they are deliberately kept
out of CI's blocking path. CI runs the broad suite NON-blocking
(`continue-on-error` with this document as the reason) so their signal
stays visible without faking green. Making this subset hermetic (or
skip-clean) is standing accepted debt; PRs that do so remove files from
this table.

### C.1 `SetupWizardTest` — skip-clean inside the blocking suite (0.4.0 Phase K)

`tests/Feature/Phase26/SetupWizardTest.php` stayed IN the blocking suite
after Phase 36, where it reported a documented environment-only red set
(10 failures + 1 error) on any host without the phpredis extension: the
setup wizard's system check probes Redis, the probe fails, and the whole
wizard cascade fails with it (`docs/product/BASELINE_0_4.md` §4 froze that
set as the 0.4.0 baseline red).

Since 0.4.0 Phase K the suite **skip-cleans when `phpredis` is absent**
(`markTestSkipped` in `setUp`). The coverage is real where it matters: the
CI blocking job runs on PHP 8.4 WITH phpredis AND a `redis:8` service
container, so every test in the file executes there. On a phpredis-less
workstation the blocking gate reports `0 failures / 0 errors` with the 19
skips visible in the summary — an honest skip, not a fake green.

## D. External-provider optional tests (self-skipping)

Guarded by environment variables and marked skipped when absent — safe to
run anywhere, never blocking:

- `tests/Feature/Phase30/PostgresCatalogLiveTest.php` — needs
  `POSTGRES_LIVE_*` env pointing at a disposable live PostgreSQL.
- `tests/Feature/Phase28/MongodbDockerDogfoodTest.php` — needs a local
  MongoDB (docker).
- `tests/Feature/Phase25/SupabaseConnectorTest.php` — the live-import
  subset needs a disposable Supabase project; the rest is hermetic.

No capability is ever claimed SUPPORTED from unit tests alone — live
verification evidence for that lives in the Phase 35.5 / 35.6 reports and
is re-proven on release artifacts (Phase 36, Step 18).
