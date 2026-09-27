# Security (28T)

The MongoDB connector inherits the Connector SDK's security model (27T) and
adds wire-protocol-specific defenses. All of them are pinned by
`MongodbSecurityTest` (12 tests) plus supporting tests in `ProtocolTest` /
`MongodbConnectorTest`.

## The URI is a secret

- Stored in the **project vault** (`project_secrets`, encrypted at rest)
  behind `secret_refs['uri']`; the `connection` JSON never carries it.
- Redaction everywhere: `MongoWireClient::redactUri()` renders
  `mongodb://user:****@host/db`; connection-test details truncate to 200
  chars and are asserted URI-free; errors from the wire client name
  host:port, never the URI.
- Logs go through `ConnectorLogger::sanitize` /
  `SecretService::redact` — vault values (including a planted canary) are
  removed before any line is written.
- AI context: `AiContextBuilder::analysisPack` (focus `mapping`) maps
  attributes to STRUCTURE only — field paths/types/counts, strategies,
  FK shapes, row estimates — NEVER values (28J.1).

## SSRF guard (28B.3)

`ConnectorNetworkGuard::assertSafeHost` runs at the HOST-RESOLUTION
boundary (`resolveHosts()` and connect), not merely on the final connect:
every seed target is checked. Loopback/private targets require BOTH the
operator opt-in (`CONNECTOR_ALLOW_PRIVATE_NETWORKS=true`) and the declared
`network.local_source` permission. Cloud metadata endpoints
(`169.254.169.254`, `metadata.google.internal`, …) are NEVER allowed, even
with the opt-in.

## The full 28T battery → tests

| Defense | Test (`MongodbSecurityTest` unless noted) |
|---------|--------------------------------------------|
| URI/credential leakage (test detail, redactUri, logs) | `test_uri_never_leaks_into_logs_exceptions_or_ui_surfaces` |
| Analysis artifacts carry structure only | `test_analysis_artifacts_never_carry_document_values` |
| SSRF: private ranges blocked; metadata blocked even with opt-in | `test_ssrf_guard_blocks_private_and_metadata_targets` |
| Cross-project vault isolation | `test_cross_project_secret_isolation` |
| Write commands structurally impossible | `test_source_write_operations_are_structurally_impossible` (also `ProtocolTest`) |
| Source profiles always `read_only` | `test_sources_stay_read_only_through_the_connector` |
| Malicious field names sanitize deterministically | `test_malicious_field_names_sanitize_deterministically` |
| Deep nesting bounded (60 levels) | `test_deep_nesting_is_bounded_not_explosive` |
| >16 MiB BSON refused by the codec | `test_oversized_bson_is_refused` |
| Resume-token tampering rejected | `test_resume_tokens_reject_tampered_shapes` |
| Connector-instance substitution refused | `test_connector_instance_substitution_is_refused` |
| No caller-facing query-injection surface | `test_connector_queries_are_programmatic_not_submitted` (28T.1) |

## The defenses in one line each

- **Read-only allowlist** — `insert`/`update`/`delete`/`drop`/… throw
  `LogicException` before serialization.
- **Malicious field names** — `.`, `$`, unicode, spaces, 200-char names
  become deterministic valid identifiers; the source path survives in
  `sanitized_field_map`.
- **Deep-nesting cap** — inference stops at depth 8 (`MAX_DEPTH`); the
  codec itself survives decoding 60-level documents without stack death.
- **Oversized documents** — `BsonCodec::MAX_DOCUMENT_BYTES` (16 MiB, the
  BSON hard limit) refuses oversized encodes/decodes with
  `InvalidArgumentException`.
- **Resume-token validation** — arrays/documents/malformed scalars are
  rejected as tampered before reaching the server.
- **Instance-substitution defense** — before building an inventory, the
  adapter verifies the configured database is genuinely accessible via
  `listDatabases`; a bogus name produces an honest
  "Database X is not accessible with these credentials" refusal instead of
  a silent empty inventory.
- **No query injection** — all filters/sorts are built internally; the
  adapter exposes no public `find`/`aggregate`/raw-query entry point.
- **Cross-project isolation** — a source resolves secrets ONLY from its own
  project's vault (`ScopedSecretResolver`; project B's `MONGODB_URI` never
  resolves for project A's source).
- **SCRAM integrity** — server signature verified with `hash_equals`;
  wrong passwords fail with server code 18 and leak nothing.

## Honest scope

These defenses are verified against the scripted wire server and the SDK
contract battery; server-side MongoDB authorization (what the `read` role
permits) is the server's own layer and is documented, not re-implemented.
