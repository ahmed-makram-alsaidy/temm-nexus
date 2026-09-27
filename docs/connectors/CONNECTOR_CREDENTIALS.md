# Connector credentials

Declarative credential schemas (27E.1), the three scopes (27E.2) and secret
minimization (27E.3). Field schemas describe shape only — they never carry
values.

## ConnectorCredentialField

`App\Services\ControlPlane\Connectors\ConnectorCredentialField` is one
declarative field. The schema drives the generic connection UI, secret
minimization and the dynamic capability matrix.

| Attribute | Type | Meaning |
|-----------|------|---------|
| `key` | string | field identifier (also the vault-ref map key) |
| `label` | string | UI label |
| `type` | `text` \| `password` \| `url` \| `host` \| `port` \| `select` \| `boolean` \| `file` | input rendering (unknown types degrade to `text`) |
| `secret` | bool | value is secret material (affects storage surface + redaction); secret fields must NOT declare a default (contract test enforces) |
| `required` | bool | |
| `scope` | `account` \| `source` \| `configuration` | where the value lives (below) |
| `validation` | PCRE pattern | the value must match |
| `help` | string | operator-facing hint |
| `capability` | capability key | which 27C capability this field unlocks |
| `options` | array value => label | for `select` |
| `default` | string | non-secret fallback only |

## The three scopes

| Scope | Storage | Resolution |
|-------|---------|------------|
| `account` | encrypted `ExternalAccountConnection` record (`secret_encrypted` encrypted cast), linked to the source | e.g. a Supabase PAT shared across an account; one value per field |
| `source` | **project vault** (`project_secrets`, encrypted at rest). `MigrationSource.secret_refs` maps field key → vault secret NAME; the model never carries the plaintext | `SecretService::valuesFor($source->project, [$name])` |
| `configuration` | non-secret values in `MigrationSource.connection` JSON (field key → value) | plain configuration (hosts, ports, dataset paths) |

Connector-scoped resolution lives in
`Support\ScopedSecretResolver` and is the ONLY way a connector obtains
credential values:

- `resolveForSource($source, $definition)` — full declared schema.
- `resolveScoped($source, $definition, $fieldKeys)` — ONLY the requested
  declared fields (27E.3 secret minimization).
- `resolvableFieldKeys($source, $definition)` — presence without values;
  drives the honest capability matrix (27C.2).

Scope escape is impossible by construction: only field keys **declared in the
connector's credential schema** are honored, so a connector cannot fish for
arbitrary vault names (27T;
`ConnectorSdkTest::test_secret_resolvers_scope_cannot_escape_to_arbitrary_vault_names`).
Cross-project isolation holds too — a source resolves only from its own
project's vault (`ConnectorRegistrySecurityTest`).

## Secret minimization (27E.3)

Operations declare what they need; the host never hands more than that:

```php
// SupabaseConnector — discovery receives ONLY the PAT, never the DB password.
$accountCredentials = $credentials->only(['pat']);
```

```php
// Host-side: discoveryRequires() vs analysisRequires()
$discoveryCredentials = $resolver->resolveScoped($source, $connector->definition(), $connector->discoveryRequires());
// assertArrayNotHasKey('password', $discoveryCredentials->raw())  ← asserted in tests
```

## The value object: ConnectorCredentials

Resolved values are wrapped in `ConnectorCredentials`, server-side only:

- `has()` / `get()` / `keys()` / `raw()` — connector's own server-side use.
- `only(array $keys)` — derive a narrower set (minimization).
- `redacted()` — secrets become `***` + a 4-char md5 evidence hash; safe for
  logs/exceptions/UI echoes.
- `__debugInfo()` redacts; `__toString()` prints only field keys — secrets
  never leak through `var_dump`/echo (asserted in tests).

Secrets are never rendered in the UI or sent to the browser; resolution is
strictly server-side. Vault values are encrypted at rest (raw column content
is asserted non-plaintext in
`ConnectorSdkTest::test_secret_refs_stay_vault_backed_and_encrypted`).

## Shipped connector field lists

### Supabase (`app/Connectors/Supabase/SupabaseConnector.php`)

| Key | Type | Scope | Secret | Required | Default | Unlocks |
|-----|------|-------|--------|----------|---------|---------|
| `pat` | password | account | yes | yes | — | `project_discovery` |
| `password` | password | source | yes | yes | — | `database_metadata` |
| `host` | host | configuration | no | yes | `127.0.0.1` | `database_metadata` |
| `port` | port | configuration | no | no | `5432` | — |
| `database` | text | configuration | no | no | `postgres` | — |
| `username` | text | configuration | no | no | `postgres` | — |
| `schema` | text | configuration | no | no | `public` | — |

Field keys deliberately mirror the Phase 24/25 `MigrationSource` storage
(`connection` JSON keys + `secret_refs` names) so pre-SDK sources keep
working unchanged. The DB password is stored as a vault secret and referenced
via `secret_refs['password']`.

### Example JSON (`app/Connectors/ExampleJson/ExampleJsonConnector.php`)

| Key | Type | Scope | Secret | Required | Unlocks |
|-----|------|-------|--------|----------|---------|
| `dataset_path` | file | configuration | no | yes | `database_metadata` |

## Contract-test guarantees

`ConnectorContractTester::checkCredentialSchema` (27L) fails a connector when:
a field has no key/label; a **secret field declares a default** (would plant
credential material in the manifest/definition); a field unlocks an unknown
capability; or a `configuration` field is marked secret.
`checkSecretLeak` runs `testConnection` with a canary value in every field and
verifies the raw canary never appears in output or exception messages.
