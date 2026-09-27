# Contributing

Thank you for helping improve the platform. This document covers the rules
of engagement; setup lives in [INSTALL.md](INSTALL.md) and the architecture
in [ARCHITECTURE.md](ARCHITECTURE.md).

## Ground rules

1. **Security issues are not contributions.** Report them privately —
   see [SECURITY.md](SECURITY.md).
2. **Read-only stays read-only.** Anything touching the migration/source
   connectors must preserve the strict read-only guarantees
   (see docs/platform/productization/CONNECTOR_ARCHITECTURE.md).
3. **No private fixtures in PRs.** Do not include customer data, production
   refs, machine paths or credentials. Tests use factories and the
   FakeAiProvider — no paid APIs, no network.
4. **Honest errors over silent fallbacks.** A missing capability reports
   "not configured/unsupported"; it never pretends to work.

## Development setup

```bash
cp .env.example .env            # fill secrets for local run
docker compose up -d            # dev stack (includes optional devtools via --profile devtools)
cd apps/owner-console
docker compose exec -T owner-console composer install
docker compose exec -T owner-console php artisan migrate
```

Code style: `vendor/bin/pint` (PHP). Tests: `vendor/bin/phpunit`.

## Pull requests

- One topic per PR; keep diffs reviewable.
- Add or extend tests for behavior changes. New features without tests are
  not merged.
- Update docs for user-visible changes (README, relevant `docs/*.md`).
- The CI workflow (lint, full test suite, secret scan, distribution scan,
  container build) must pass.

## Project conventions

- **SemVer** via the root `VERSION` file; changelog entries for every
  user-visible change.
- **Generic by construction.** No machine-specific paths, private names or
  environment assumptions in runtime code — configuration comes from env,
  the setup wizard, or safe defaults.
- **Connector proposals** — open a proposal issue describing the source
  system, its read-only access path, and what the capability matrix can
  honestly report. Implementation guidance:
  docs/platform/productization/CONNECTOR_ARCHITECTURE.md
