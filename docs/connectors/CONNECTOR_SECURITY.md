# Connector security

The Phase 27K/27T security model for connector packages: trust gating, the
permission vocabulary, SSRF and path guards, secret handling — and an honest
statement of what this is NOT.

## Trust model (27K.1)

| Trust level | Enabled by default | Meaning |
|-------------|--------------------|---------|
| `first_party` | **yes** | ships with the platform, reviewed in-repo (`supabase`, `example-json`) |
| `trusted` | no | operator-promoted package (future third-party tier) |
| `unverified` | no | anything else — including `connector:make` scaffolds |

- Only `first_party` is enabled by default. The allow-list is
  `config('connectors.enabled_trust_levels')` via env
  `CONNECTOR_ENABLED_TRUST_LEVELS` (`all` enables all three tiers — an
  explicit operator config change, never a UI action).
- A disabled connector is **uninvokable**, not hidden: `ConnectorRegistry::connector()`
  throws `ConnectorDisabled` (`ConnectorRegistrySecurityTest::test_disabled_connector_cannot_be_invoked`).
- Third-party/remote installation is intentionally NOT implemented in Phase 27
  (27J.4): no remote download, no marketplace. Discovery reads only
  locally-shipped packages under `config('connectors.paths')`. When third-party
  installs arrive they will be a privileged admin action.

### Honest limitation: this is not a sandbox

Connector packages are in-process PHP classes resolved through the container.
The SDK scopes credentials, files, artifacts and network egress, but a
malicious connector with full access to the Laravel container could bypass
any helper. **Treat connector installation like code review and deployment —
in-process connectors are trusted code, not sandboxed plugins.** The
trust/permission system is a review-and-gate workflow and defense-in-depth,
not an isolation boundary (27K, stated honestly).

## Permission vocabulary (27K.2)

Manifests declare intentions from a fixed vocabulary; anything else fails
manifest validation:

| Permission | Grants |
|------------|--------|
| `network.outbound` | outbound HTTPS to public endpoints |
| `network.local_source` | loopback/private network targets — requires the operator to ALSO set `CONNECTOR_ALLOW_PRIVATE_NETWORKS=true` |
| `source.db.read` | read-only source database access |
| `source.storage.read` | read-only source storage metadata/content |
| `client.repo.read` | client repository scanning |
| `filesystem.dataset.read` | guarded local dataset reads (`ProjectScopedFileReader`) |

## SSRF guard (27K.4)

`Support\ConnectorNetworkGuard::assertSafeUrl($url, $connectorMayUseLocalSource)`
applies to custom connector URLs. Check order: scheme → hostname blocklist →
IP literal checks → best-effort DNS resolution check.

| Rule | Detail |
|------|--------|
| Scheme | HTTP(S) only — `file://` and others refused |
| Cloud metadata | **never allowed**, not even with local sources enabled: `169.254.169.254`, `169.254.169.25`, `100.100.100.200`, `metadata.google.internal`, `metadata.goog`, `metadata.oraclecloud.com` |
| Loopback/private | `localhost`, `*.localhost`, `*.internal`, `*.local`, single-label docker names, private/reserved IP ranges — refused unless the operator sets `CONNECTOR_ALLOW_PRIVATE_NETWORKS=true` AND the connector declared `network.local_source` |
| Plain HTTP | reserved for explicitly allowed local sources only |
| Blocked service ports | 22, 23, 25, 135, 139, 445, 3389 |
| DNS pinning | best-effort resolution check; failing DNS degrades to name-level checks only (documented limitation) |

The Supabase management API base (`SupabaseAccountService::managementApi()`)
carries its own production guard: a `SUPABASE_MANAGEMENT_API_URL` override
must use HTTPS for public hostnames in production (plain HTTP only for
loopback/private/docker-internal hosts).

## Path traversal guards (27K.2/27K.3)

- `Support\ProjectScopedFileReader` — connectors never receive raw filesystem
  access. Explicit `..` segments are rejected before `realpath`; the resolved
  path must stay inside the operator-configured root; extension allowlist and
  byte-size caps apply; `datasetFiles()` caps count (default 50 files).
- `Support\ConnectorArtifactWriter` — artifact filenames must match
  `^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$` and kinds are stripped to
  `[a-z0-9_-]`; artifacts land in PRIVATE local storage under
  `control-plane/migration-artifacts/<project_id>/`, so connector-provided
  names can never escape the artifact root.

## Secret handling

| Surface | Protection |
|---------|------------|
| Vault (`project_secrets`) | encrypted at rest with the app key; sources store only the secret NAME (`secret_refs`) |
| Account tokens | `ExternalAccountConnection.secret_encrypted` encrypted cast — never stored or returned in plaintext |
| In-memory values | `ConnectorCredentials` — redacted `__debugInfo`/`__toString`/`redacted()` (4-char md5 evidence hash) |
| Capability matrix | presence probing (`resolvableFieldKeys`) reports configuration state without exposing values |
| Client scanner | repository evidence stored HASHED only; raw secret material never enters the database, logs or AI context |
| Logs | `Support\ConnectorLogger` redacts known vault values via the project vault, masks context keys matching `/pass|secret|token|pat\b|key|credential/i`, strips control characters (log-injection defense), truncates error details to 300 chars |

## The Phase 27T security battery

All enforced in `tests/Feature/Phase27/ConnectorRegistrySecurityTest.php`:

| Threat | Test |
|--------|------|
| Duplicate connector key shadowing a first-party connector | `test_duplicate_connector_key_is_rejected` (`ConnectorKeyConflict`) |
| Unknown connector resolution | `test_unknown_connector_fails_gracefully` |
| Disabled connector invoked | `test_disabled_connector_cannot_be_invoked` + `test_enable_disable_roundtrip` |
| Malformed/injection manifests (broken JSON, bad key, wrong schema_version, missing entrypoint class, `<script>` in key, newline in name) | `test_malformed_manifest_package_is_rejected_safely` — nothing registers, registry untouched |
| Oversized metadata (200 KB description) | `test_oversized_manifest_is_rejected` (64 KB cap) |
| Unverified package auto-enabled | `test_unverified_trust_registers_disabled` |
| Impostor entrypoint (manifest key ≠ entrypoint key) | `test_entrypoint_key_mismatch_is_refused` |
| SSRF to metadata/loopback/private/file URLs | `test_ssrf_guard_blocks_metadata_and_private_targets` |
| Metadata endpoint via the local-source switch | `test_ssrf_guard_allows_public_https_and_explicit_local_sources` — metadata stays blocked |
| Path traversal + extension escape | `test_file_reader_blocks_traversal` |
| Artifact filename escape (`../../etc/passwd`) | `test_artifact_writer_rejects_path_escape_filenames` |
| Log injection | `test_connector_logger_sanitizes_log_injection` |
| Cross-project secret fishing | `test_connector_scoped_services_are_isolated_per_project` |
| Vault fishing via undeclared field keys | `ConnectorSdkTest::test_secret_resolvers_scope_cannot_escape_to_arbitrary_vault_names` |
| Plaintext secrets at rest | `ConnectorSdkTest::test_secret_refs_stay_vault_backed_and_encrypted` |
| Secret leak through connector output | `ConnectorContractTester::checkSecretLeak` (canary) |

Scale guards complement the battery (27U,
`config/connectors.php`): `max_definitions` 100 registered connectors,
`max_analysis_items` 10 000 per analysis.
