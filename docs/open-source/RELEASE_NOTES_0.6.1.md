# TEMM Nexus v0.6.1

**Stable patch — 2026-10-08.**

- Corrected the Sync stage after a completed dry run: it shows the completed migration without fabricating an active Live Sync operation.
- Connectors that do not declare change capture, including the Supabase connector, now show “Not supported by this connector”.
- Restored “Live transfer (real target)” for eligible TEMM-managed destinations. The action runs `mode=real` against the project's managed database with project-scoped credentials carried through encrypted vault references.
- Migration safety guards remain enforced: production targets are rejected, reset requires a disposable target, source and target must differ, and dry runs perform no target writes. Managed real transfers use `target_disposable=false` and `reset=false`; missing credentials fail before adapter defaults can apply.
- The same-database guard compares equivalent integer and string port values consistently when the managed endpoint comes from environment variables.

No database migrations or schema changes are included in this patch. Production verification must inspect the completed dry run and Sync wording only; starting the Wasla real transfer remains a manual operator action.
