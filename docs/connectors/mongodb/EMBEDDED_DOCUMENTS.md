# Embedded documents (28L/28T.2)

MongoDB documents nest. The connector flattens nested objects into dotted
paths, sanitizes them into safe PostgreSQL identifiers, and — past the safe
depth — falls back to JSONB instead of pretending deeper structure is
relational.

## Flattening

`SchemaInferer` walks nested documents to `MAX_DEPTH = 8` levels, recording
every leaf as a dot-path (`address.city`, `address.geo.lat`). The strategy
projection then turns each non-array path into a column whose sanitized
name joins the path segments:

```
address.city  →  address__city     (users table, asserted in tests)
address.geo.lat → address__geo__lat
```

## Depth overflow → JSONB (28L.2)

Documents nested deeper than 8 levels stop being walked. The parent path is
flagged `overflow: true` and any collection with an overflow field takes the
`JSONB_DOCUMENT` strategy — the reason string says so verbatim
("nesting depth exceeds safe inference depth (28L.2)"). The synthetic source
deliberately contains an 11-level document (`mixed_documents`, `l1`…`l10`)
to exercise this; the whole collection becomes `[_id, document]`.

## Field-name sanitization (28T.2)

MongoDB field names may contain `.`, `$`, spaces, unicode and arbitrary
length. `FieldNameSanitizer::baseName()` produces deterministic, collision-
safe identifiers (`[a-z_][a-z0-9_]*`):

| Source path | Sanitized column |
|-------------|------------------|
| `user.name` | `user__name` (dot → `__`) |
| `a$b` | `a_u24b` (`$` → `_u` + hex codepoint) |
| `weird field` | `weird_field` (space → `_`) |
| `مفتاح` | `_u645_u641_u62a_u627_u62d` (unicode → `_uXXXX`) |
| `9lives` | `_9lives` (leading digit guarded) |

Rules: uppercase folds to lowercase; every "other" character becomes
`_u<hex>`; names truncate at **57 characters** (leaving room for collision
suffixes under PostgreSQL's 63-byte identifier limit); when two paths
sanitize to the same name, `columnFor()` appends `_2`, `_3`, …
deterministically, so long truncated names stay distinct (asserted in
`MongodbSecurityTest::test_malicious_field_names_sanitize_deterministically`).

## Nothing is lost by the mapping

Every table carries `sanitized_field_map`: column name → ORIGINAL source
dot-path. The projection and extraction layers read the map, so values land
in the right columns no matter how aggressive the sanitization was, and the
original document shape remains recoverable (fully so for JSONB columns,
which store the canonical document).

## Metadata names

Index names and other non-column identifiers pass through
`safeMetadataName()` (control characters and slashes → `_`, max 255) —
metadata only, never used as filesystem or SQL paths.
