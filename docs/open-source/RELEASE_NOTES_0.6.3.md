# TEMM Nexus v0.6.3

**Stable patch — 2026-10-09.**

- TEMM-managed destinations now provision a project database through the destination step or the Connections page, with an explicit active environment.
- Connections provides Provision database, Configure connection, and retry actions when a connection is missing or unreachable.
- Data, Health, and real migration targets share the same canonical environment connection. Health reports the project database separately from the source.
- Strong credentials are encrypted in the existing vault and bound to the selected environment. Provisioning is authorized, auditable, idempotent, and safe to retry; unknown existing databases or roles are refused without destructive overwrite.
- English and Arabic distinguish project classification from the active environment. Technical identifiers remain left-to-right, and transfer descriptions update with the selected mode.

No schema migration is required. Existing production migration, disposable-reset, and source/target safety guards remain enforced. Published v0.6.1 and v0.6.2 remain unchanged.
