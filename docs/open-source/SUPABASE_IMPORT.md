# Importing a Supabase Project

Initial supported import source (connector architecture — see
../platform/productization/CONNECTOR_ARCHITECTURE.md for adding more).

## Flow

Admin console → Projects → **Import existing project (Supabase)**:

1. **Connect account** — a Supabase Personal Access Token (PAT) is stored
   encrypted at rest. The connection test reports PASS / INVALID_TOKEN /
   INSUFFICIENT_SCOPE / NETWORK_ERROR / RATE_LIMITED honestly.
2. **Select project** — safe management metadata only (name, ref, region,
   organization). Unavailable fields stay empty; nothing is invented.
3. **Capability matrix** — per-channel (database, auth, storage, functions)
   with NEEDS_CREDENTIAL states where database credentials are missing.
4. **Read-only probe & analysis** — schema, RLS policies, RPC/edge
   functions, extensions inventory. The source is enforced read-only at
   the DB level AND application level; the source can never be a migration
   target.
5. **Link client repository (optional)** — an operator-approved local path
   (path-contained, read-only) is scanned for supabase-js/dart callsites,
   producing a conversion manifest with statuses. Secret-like findings are
   stored as hashes, never values.
6. **Plan & rehearse** — migration plan against a disposable target with
   row-count validators. Nothing is applied without explicit approval.

## Where AI fits

With a configured AI key, the Migration Copilot assists classification and
patch drafting on top of the same read-only analysis. Without AI, the whole
flow above works unchanged.

## Safety summary

- Source is read-only by contract and guarded structurally.
- No auto-migration: every apply is an explicit, audited operator action.
- PATs and DB credentials never appear in logs, listings or exports.
