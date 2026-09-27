# Supabase Account Connector

> 25A · Service: `SupabaseAccountService` · Model: `ExternalAccountConnection`

## Connection model

`external_account_connections` — provider (`supabase`), display_name, owner
user, `secret_encrypted` (PAT encrypted at rest with APP_KEY — identical
crypto to the Secrets Vault; a `secret_ref` vault delegation column exists for
project-scoped usage), status, last result, last verified timestamp, metadata.

**PAT safety (25A.2)**: encrypted at rest (ciphertext asserted in tests),
excluded from listings, never returned after save, audited on
create/test/delete (`SUPABASE_ACCOUNT_CONNECTED` / `_TESTED` / `_DELETED`),
omitted from logs and error output.

## Connection test (25A.3)

`SupabaseAccountService::testConnection` calls the Supabase management API
(`GET https://api.supabase.com/v1/projects`) and classifies:
`PASS | INVALID_TOKEN (401) | INSUFFICIENT_SCOPE (403) | RATE_LIMITED (429) |
NETWORK_ERROR | PROVIDER_ERROR (5xx) | UNKNOWN_ERROR`. Raw provider responses
are never displayed — only the classification + HTTP code.

## Project discovery (25A.4)

`discoverProjects` returns safe metadata only: name, project ref, organization
id, region, status. Fields the API does not return stay `null` — never faked.
Database passwords are never requested automatically (management APIs do not
expose them).

## Project selector (25A.5)

The wizard renders discovered projects as a single-select (name — region).
One project per import flow; nothing is imported in bulk. Selecting a project
is audited (`SUPABASE_PROJECT_SELECTED`).
