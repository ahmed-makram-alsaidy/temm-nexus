# Onboarding Wizard

> Route: `/admin/onboarding` · Permission: any authenticated panel user

## Flows

The wizard replaces the flat "New Project" flow with two flows:

1. **CREATE NEW PROJECT** — steps: identity → environment → database → auth →
   storage → realtime → API → secrets → SDK/client → health check → finish.
2. **IMPORT EXISTING PROJECT** (Supabase) — steps: identity → source type →
   read-only source connection → analyze → compatibility → environment →
   migration plan → migration rehearsal → client setup → readiness → finish.
   The import flow **reuses the Migration Center** (creates the project shell
   + a read-only `MigrationSource`, then hands off) — analysis logic is never
   duplicated inside the wizard.

## Deferred creation

Nothing is created until the final confirmation step, which requires
re-typing the project name. Only then does `OnboardingService::completeCreate`
create the Project + canonical environments + starter secrets, or
`completeImport` create the Project + read-only source.

## Persistence / resume

State lives in `onboarding_sessions` (user, flow, current_step, state JSON).
An operator can leave mid-wizard and resume later — `OnboardingService::resume`
returns the newest `in_progress` session. Abandoning marks the session and
returns to the start screen.

## Starter templates

Minimal by design: the wizard carries the platform's existing defaults
(environment set, redis/storage/realtime namespaces, auth bootstrap) rather
than a template marketplace.

## Tests

`tests/Feature/Phase24/OnboardingTest.php` — deferred creation, import
source read-only + secret-ref-only, resume/abandon, flow validation.
