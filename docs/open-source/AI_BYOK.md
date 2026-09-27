# AI Migration Copilot — Bring Your Own Key

## Model

- **Optional by design.** Without any AI key, every platform feature works:
  projects, imports, analysis, migration planning/rehearsal, backups,
  auth, storage, realtime.
- **BYOK:** the operator configures providers in the admin UI (AI Providers):
  OpenAI, Gemini, Anthropic, OpenRouter, or any OpenAI-compatible endpoint
  (SSRF-guarded custom base URL).
- **Encrypted at rest.** Keys are stored with Laravel's encrypted cast and
  never logged, listed or exposed to the frontend.
- **Privacy minimization.** External calls happen ONLY when an operator has
  configured a provider AND invokes AI functionality. Context packs sent to
  the provider are scoped and redacted (no secrets, no credentials).
  Model profiles (planner/builder/validator) and usage records are local.

## Cost control

Usage is recorded per call (tokens, mode); cost is estimated ONLY with
operator-configured pricing. Test the provider from the UI before enabling
copilot features.

## Provider test

AI Providers → Test: a connectivity + auth probe against the configured
provider; failures surface verbatim (invalid key, quota, bad base URL).

## No keys ship with the platform

The repository contains no provider keys — ever. The automated test suite
uses a FakeAiProvider; CI needs no paid API.
