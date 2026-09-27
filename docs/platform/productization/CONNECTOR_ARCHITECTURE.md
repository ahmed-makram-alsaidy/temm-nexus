# Connector Architecture (Phase 26O — Phase 27 extraction point)

The platform imports existing backends through **source connectors**. Supabase
is the first supported connector; the architecture is deliberately generic so
future connectors (MongoDB, Firebase, PostgreSQL, MySQL, PocketBase,
Appwrite, …) plug in without touching the engine.

## Where the abstraction lives (reuse, don't refactor)

| Concern | Interface / service | Introduced |
|---|---|---|
| Read-only source adapter | `App\Services\ControlPlane\Migration\Contracts\SourceAdapter` | Phase 24 |
| Supabase implementation | `Migration\SourceAdapters\SupabaseSourceAdapter` | Phase 24 |
| Test/rehearsal implementation | `Migration\SourceAdapters\SqliteSourceAdapter` | Phase 24 |
| Capability probing (db/rls/auth/storage/functions) | `Supabase\SourceCapabilityService` | Phase 25C |
| Account discovery / management API | `Supabase\SupabaseAccountService` | Phase 25A |
| Write prevention (structural) | `Supabase\SourceWriteGuard` | Phase 25C |
| Registry / resolution boundary | `Connectors\ConnectorRegistry` | **Phase 26O** |

## Rules every connector must obey

1. **Strictly read-only.** Analysis, inventory, preview and export never
   mutate the source; the write guard rejects all write verbs structurally.
2. **Honest capabilities.** Unavailable channels are reported as missing —
   never pretended.
3. **Credentials encrypted at rest**, never logged, never listed.
4. **Unknown connector → `ConnectorNotSupported`.** The UI/CLI surfaces the
   supported list; nothing falls back to a "generic" connector that might
   silently mishandle a source.

## Phase 27 extraction plan

- Add `SourceConnector` lifecycle wrappers around `SourceAdapter`
  (discovery → credentials → import → analyze) so flows other than
  Supabase's account-based wizard can be expressed.
- Move the Supabase import wizard steps behind the connector descriptor
  (`import_flow` id in `ConnectorRegistry::all()`).
- Register new adapters in `ConnectorRegistry` only; the plan/run engine,
  guard rails and rehearse/apply pipeline are untouched.
