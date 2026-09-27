# Client Dependency Scanner

> 25E · `ClientDependencyScanner` · Manifest table: `client_callsites`

## Detection patterns

Real SDK idioms, per language:

- **supabase-js / TS / React** — `supabase.auth.signInWithPassword|signUp|signOut|…`, `.from('table')`, `.rpc('fn')`, `.functions.invoke('name')`, `.storage.from('bucket')`, `.channel('name')`
- **supabase-dart / Flutter** — `Supabase.instance.client…`, `client.auth.signInWithPassword(email:, password:)`, `.from(...)`, `.rpc(...)`, `.storage.from(...)`, `.stream(executable:)`
- **Generic** — `createClient(`/`Supabase.initialize(` (client_init), hard-coded `https://*.supabase.co|.in` URLs (url), service-role/anon-key markers (secret)

Each manifest row: file, line, category, target (table/function/bucket where
inferable), language, confidence, status
(`DISCOVERED|MAPPED|CONVERTED|REVIEW|IGNORED_WITH_REASON`) and a hashed
evidence digest.

## No false conversion (25E.2)

`CONVERTED` is only ever set by an operator-verified apply — the scanner
itself never upgrades a callsite. Wrapping a call in a helper does not count;
conversion means the Laravel/backend-mode execution no longer invokes
Supabase for that operation.

## Secret scan (25E.3)

Env/config files are scanned for high-risk markers: service-role keys,
database passwords, OAuth client secrets, WhatsApp tokens, payment secrets.
Findings store ONLY the marker name, file, and a sha256 evidence hash — raw
values never enter the database, logs, UI, or AI context
(`secretFindings` returns masked evidence: `f3ab12c9d001…`).

## Manifest for AI (25E.1)

`ClientDependencyScanner::manifest` returns the secret-free callsite list
(file/line/category/target/language/status) — this is what the Copilot
consumes for client conversion planning.
