# Environment Manager

> Route: `/admin/projects/{record}/environments` · Permission: view `projects.view`, manage `environments.manage`

## Model

`project_environments` — per project: name, slug, type
(development|staging|production), status (active|inactive), api_base_url,
database connection coordinates (`database_connection` JSON + vault ref for
the password), redis/storage/realtime namespaces, node association, default
flag and a **disposable** flag (may be reset by drills / clean runs).

`EnvironmentService::ensureDefaults` gives every project the canonical
Development (active, disposable) / Staging / Production triple. The
environment **type is immutable after creation** — it can never be mutated to
slip past the disposable/production guardrails.

## Isolation contract (proven in tests)

Development DB ≠ Staging DB ≠ Production DB; redis prefixes, storage
namespaces, realtime namespaces, secrets, logs and queues are all
environment-scoped. `EnvironmentsTest` asserts namespace divergence per
environment and secret scoping (`DEV_ONLY` invisible to Staging).

## Switcher (24C.3)

The workspace subnav renders an environment selector under the project pill
(`EnvironmentContext::active`). Switching stores the choice in the session
and updates every environment-scoped module (Secrets, Backups, Resources,
Readiness, Migration Center, Connect) with no stale data. Switching to an
inactive environment is refused; every switch is audited
(`ENVIRONMENT_SWITCHED`).

## Promotion (24C.4)

`EnvironmentService::attemptPromotion` computes a check list (confirmation,
source not production, statuses active). Production promotion requires the
strong confirmation phrase `PROMOTE <slug> TO PRODUCTION` and remains
local-only. Sensitive data is never copied between environments.

## Diff (24C.5)

`EnvironmentService::diff` compares type, status, api_base_url, latest schema
fingerprint per environment, secrets completeness and config keys — honestly
reporting "(no snapshot)" when a fingerprint is missing.
