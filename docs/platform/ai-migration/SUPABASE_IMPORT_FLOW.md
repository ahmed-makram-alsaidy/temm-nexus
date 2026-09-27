# Supabase Import Flow

> 25B/25L · Onboarding wizard import branch + `SupabaseAccountService::selectProject`

## Steps (persisted + resumable in `onboarding_sessions`)

1. **Identity** — project name/slug.
2. **Connect account** — pick an existing connected connection or save a new
   PAT (encrypted, immediately test-verified with the result shown).
3. **Select project** — single choice from discovered projects; prepares the
   import draft immediately.
4. **Draft preparation (25B)** — creates the Platform Project (if new), the
   canonical environments, and a project-scoped READ-ONLY `MigrationSource`
   with management metadata (management_project_ref, region, organization,
   api_url). **No migration executes.**
5. **Capability matrix** — per-channel status before any analysis.
6. **Database read-only credential (25C.1)** — structured coordinates
   (host/port/database/username) + password into the vault; the source keeps
   only the secret NAME. The wizard explains WHY each credential is needed.
7. **Analyze** — capability probe then full read-only analysis via the
   Phase 24 engine; compatibility classification runs automatically.
8. **Link client repository** — operator-approved local root (or git metadata).
9. **Scan client** — Supabase callsite manifest + secret scan.
10. **AI Copilot plan** — advisor run (skipped with a warning if no provider
    is configured — never silently faked).
11. **Review → Finish** — the flow stops before migration. Continuing to a
    real migration happens only through the Migration Center with explicit
    operator action.

## Auto-fill policy (25B.2)

Only safe, discovered metadata is pre-filled (ref, region, name, api URL).
The wizard never pretends database credentials exist when they do not — the
capability matrix shows `NEEDS_CREDENTIAL` until the operator supplies them.
