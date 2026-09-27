# AI Data Privacy

> 25G.9 / MIGRATION SOURCE PRIVACY · `AiContextBuilder::redact` + scoped packs

## What never leaves the platform

Before ANYTHING is sent to an external AI provider:

- password hashes (bcrypt `$2…`, argon2) → `[REDACTED_*]`
- JWTs and bearer-shaped tokens → `[REDACTED_JWT]`
- `password/secret/token/api_key/service_role/anon_key = value` shapes → `[REDACTED]`
- long hex / base64 blobs (likely keys) → `[REDACTED_HEX]` / `[REDACTED_B64]`
- real customer/financial row content — never included (only schema shapes,
  counts and identifiers enter packs)
- vault secrets, PATs, AI keys, DB passwords — never in any context pack
  (they are not readable by the context builder at all)

## Context minimization (25G.9)

The Copilot never receives the whole project, database, or repository:

- Analysis packs: summary counts + ONLY the item kind relevant to the action
  (e.g. policies for RLS analysis), capped (60–80 items), trigger/function
  bodies stripped to summaries.
- Client packs: the secret-free callsite manifest (bounded), plus at most 3
  operator-selected files, each truncated (4–6 k chars) after redaction.
- Everything wrapped as UNTRUSTED DATA (see PROMPT_INJECTION_SECURITY.md).

## Provider privacy consent

The Copilot page documents that project schema/business material is sent to
the configured provider according to operator configuration, that only
minimized/redacted content is sent, and that keys are stored encrypted with
no browser exposure. FakeAiDriver keeps the entire suite provider-free.

## Key hygiene

AI provider keys and Supabase PATs are encrypted at rest (APP_KEY cast — the
same mechanism as the vault), never rendered, never logged, never audited
with values; cross-test leakage scans cover HTML, logs, and stored ciphertext.
