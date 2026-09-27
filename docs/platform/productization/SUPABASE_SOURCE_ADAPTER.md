# Supabase Source Adapter

> `app/Services/ControlPlane/Migration/SourceAdapters/SupabaseSourceAdapter.php`

## Scope

Connects to a PostgreSQL database carrying a Supabase-shaped schema
(public + auth + storage + cron schemas). Intended sources are LOCAL or
STAGING snapshots and explicitly provided test endpoints — **the platform
never contacts production Supabase** (the dogfood regression uses a local
local snapshot database and
local dev stack, both read-only).

## Read-only enforcement

1. `SET default_transaction_read_only = on` at connect (session-wide);
2. every inventory/stream query runs inside `BEGIN TRANSACTION READ ONLY … COMMIT/ROLLBACK`;
3. the SQLite adapter equivalent opens with `PRAGMA query_only = ON`.

## Inventory coverage

| Domain | Query basis |
|---|---|
| schemas | information_schema.schemata (system schemas excluded) |
| tables | pg_class/pg_namespace + per-table columns (information_schema.columns), PK (pg_index), FK (constraint usage), indexes (pg_index unique/cols), RLS flag (relrowsecurity), row estimates (reltuples) |
| views / matviews | pg_class relkind v / m |
| enums | pg_type + pg_enum (array_agg parsed via `parsePgArray` — PDO pgsql returns `{a,b}` strings) |
| functions | pg_proc + pg_language (security definer/invoker, language) |
| triggers | pg_trigger (non-internal) with definitions |
| RLS policies | pg_policies (schema, table, command, roles, using, with_check) |
| extensions | pg_extension |
| auth | auth.users count, auth.identities + providers, hash strategy from a sample prefix ($2a→bcrypt, $argon2→argon2, else unknown — never fabricated) |
| storage | storage.buckets + storage.objects counts/bytes (metadata→>'size' cast before SUM) |
| realtime | pg_publication_tables for supabase_realtime |
| cron | cron.job |
| edge functions / client dependencies | operator-imported manifest only (never provider API calls) |

## Credentials

Passwords are resolved from the project vault through `secret_refs`
(secret *names*); the connection record never contains a password, and the
DSN is built from structured fields with identifier validation (`qi()`).
PG array literals are parsed losslessly for identifier/label sets.
