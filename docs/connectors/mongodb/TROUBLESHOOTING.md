# Troubleshooting

First diagnostic, always:

```bash
php artisan connector:test mongodb
```

It runs the SDK contract battery (manifest, capability honesty, canary
secret-leak, read-only profile) without touching a real server. Then
`php artisan connector:inspect mongodb` to review the effective definition.
Below, every error is quoted as it leaves the connector (messages never
contain the URI — if you think you need the URI to debug, you don't; see
SECURITY.md).

## "MongoDB server selection failed: <host:port> — <reason>"

The seedlist connection failed.

| Cause | Fix |
|-------|-----|
| wrong host/port in the URI or split config | verify the target (e.g. `mongosh "mongodb://host:27017" --eval "db.runCommand('ping')"` from the platform host) |
| Docker port not published | start with `docker run --rm -d -p 127.0.0.1:27017:27017 mongo:7` |
| SSRF opt-in missing for loopback/private targets | set `CONNECTOR_ALLOW_PRIVATE_NETWORKS=true` (SELF_HOSTED.md) |

## "listDatabases failed (code 18): Authentication failed"

Wrong username/password (or the user exists on a different `auth_source`).
The connection test classifies this as `INVALID_CREDENTIAL`. Check the
user's auth database — the split config defaults `auth_source` to `admin`,
and a URI's authSource defaults to the URI database.

## "… failed (code 13): command requires authentication"

The server refused an unauthenticated command: credentials never reached
the client. With a URI, embed `user:pass@`; with split config, set
`username` and the `password` secret. `ProtocolTest::test_scram_authentication_against_scripted_server`
reproduces exactly this state (code 13 without credentials, code 18 with a
wrong password).

## "Database X is not accessible with these credentials — check the database name (28T instance-substitution defense)"

The configured `database` is not in the credentials' accessible list. The
connector refuses honestly instead of producing an empty inventory. Fix the
database name (typo, wrong project, user scoped to a different db).

## Empty inventory

Since Phase 28 a wrong database name is refused (above), so a genuinely
empty inventory means: the database exists but `listCollections` returned
only system/view namespaces, or everything was filtered. Check that the
database has real (non-`system.*`) collections for these credentials.

## "Socket read failed (timeout)"

The socket read exceeded `server_selection_timeout_ms` (default 10s).
Raise it for slow links or large `batchSize` values; also confirm network
stability. The error is thrown by the client's `readExact` guard, so no
partial document ever reaches the codec.

## Rows missing in the target (INSERT OR IGNORE drops)

The target adapters insert with `INSERT OR IGNORE`, and NOT NULL claims
come from document-level inference: a field present in EVERY sampled
document is claimed NOT NULL. If a rare document omits it, its row
violates the constraint and is skipped — the `connector_counts` validator
then surfaces the count gap. Fix: raise `sample_size`, re-analyze (the
field becomes nullable once observed missing), or relax the column's
nullability in the plan. Child-table element columns are already nullable
by construction (28E.3).

## Validation reports count mismatches

Run the connector-provided artifacts (`validateSource`): `row_counts`,
`mongodb_id_coverage` and `mongodb_jsonb_equivalence` pinpoint which
collection/ids diverged. The 28V.3 fingerprint comparison tells you
whether the source changed during the run.

## Still stuck

`tests/Feature/Phase28/` runs the full flow against a scripted wire server
with no Docker required — `php artisan test tests/Feature/Phase28` is the
fastest way to prove the platform side is healthy and isolate the problem
to your specific server/deployment.
