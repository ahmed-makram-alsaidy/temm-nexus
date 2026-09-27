# Database browser (control plane)

## Tables view

All `public` tables + views with estimated row counts (pg_class). Each links to
Records and Schema. No DROP/TRUNCATE/ALTER anywhere in the GUI.

## Schema view

Columns (type, nullable, default, PK), foreign keys rendered as
`orders.merchant_id → merchants.id`, indexes, exact row count.

## Record browser

Server-side pagination/sort/search (search across text-ish columns), filters,
row create/edit/delete with confirmation. Single-row writes only; protected
tables (`migrations`, `jobs`, `failed_jobs`, `sessions`, `cache*`) are read-only;
views are read-only; secret columns (`password`, tokens, keys) are never selected
or rendered. Every write is audit logged (RECORD_CREATED/UPDATED/DELETED).

Proven: 5000-row table paginates server-side; CRUD round-trip; FK metadata;
hashes never in HTML (`tests/Feature/RecordBrowserTest.php`).
