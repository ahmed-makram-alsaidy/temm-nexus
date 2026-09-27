# Source Credential Security

> 25C · `SourceCapabilityService`, `SourceWriteGuard`, Phase 24 runner guards

## Capability channels

Each access channel is reported honestly — `CONNECTED`, `NEEDS_CREDENTIAL`,
`VIA_DATABASE`, `PARTIAL`, `NOT_CONNECTED`, `NOT_LINKED`:

| Channel | Basis |
|---|---|
| Account discovery | connected `ExternalAccountConnection` |
| Database | vault password ref **AND** structured coordinates (host/port/database) |
| Auth / Storage / Functions | via the read-only database channel |
| Client repository | linked `ClientRepository` |

`SourceCapabilityService::probe` performs a safe read-only probe and reports
PASS/PARTIAL per domain (database, rls, auth, storage, functions, views,
extensions) — never COMPLETE without evidence. Results persist on the source
for the wizard and UI.

## Database credential (25C.1)

Operator-supplied structured credentials (host, port, database, username,
password, optional ssl mode). The password lives only in the vault; the
source row stores coordinates + secret NAME. The full URI with password is
never displayed after save and never logged.

## Read-only enforcement (25C.2) — two layers

1. **DB-level**: adapters open with `SET default_transaction_read_only = on`
   and run inventory/stream queries inside explicit `READ ONLY` transactions.
2. **Application-level**: `SourceWriteGuard::assertReadOnly` rejects
   INSERT, UPDATE, DELETE, MERGE, CREATE, ALTER, DROP, TRUNCATE, GRANT,
   REVOKE (plus CALL/DO/VACUUM/REINDEX/LISTEN/NOTIFY/COPY). Comments and
   string literals are stripped before matching, so "DELETE" inside a string
   cannot fool the guard — and SELECT queries containing those words pass.

## Source/target confusion guard (25C.3)

`MigrationRunManager::start` refuses a target whose connection key matches
the plan's source **or ANY other read-only source of the project** (keyset =
host+port+database+path, sqlite included). Reset/truncate/clean are only
reachable for explicitly disposable targets, and never against production.
