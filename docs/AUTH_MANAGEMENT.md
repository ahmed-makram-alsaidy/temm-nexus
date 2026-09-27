# Auth management (control plane)

Per project, over the project's own `users` (+ optional `status`/`role`/
`last_login_at`), `roles`, `permissions`, and Sanctum `personal_access_tokens`.

## Users

Search/sort/paginate/filter (status, role). View (no hashes, ever), create
(bcrypt server-side), edit safe fields, optional password change, enable/disable
(disable also revokes all tokens), revoke sessions. Missing optional columns
degrade gracefully (columns hidden).

## Roles / Permissions

Require project `roles` / `permissions` tables (id, name unique, description);
otherwise the page shows the setup SQL hint. Explicit permission keys
(`orders.create`) preferred over role-name logic.

## Sessions

Lists tokens with user email, device name, abilities, last use, expiry.
Revoke one or all (audited as TOKEN_REVOKED / SESSION_REVOKED).

## Project API auth (template + demo)

Register · Login (403 when disabled, last_login_at stamped) · Logout (revokes
current token) · Forgot-password (existence-safe) · Me · Sanctum tokens.
Proven live: login → me → logout → 401, disabled → 403, wrong password → 422
(`scripts/prove-demo-auth.sh`).
