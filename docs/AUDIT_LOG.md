# Audit log (control plane)

Every high-risk admin action writes an `admin_audit_entries` row: owner,
project, action (fixed vocabulary of 30), target type/id, IP, non-secret
metadata. The Audit Log resource is read-only (no create/edit/delete routes —
proven 404), searchable and filterable by action/project/date.

Covered actions include user lifecycle, token/session revocation, role/permission
changes, record CRUD, backup trigger/verify, queue retry/delete, maintenance,
cache clears, storage operations, scheduler runs, settings updates.
