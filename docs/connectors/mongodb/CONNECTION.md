# Connection

One database per import flow (28C): a source points at exactly one MongoDB
database. Connections are configured either as a single `mongodb://` /
`mongodb+srv://` URI (preferred) or as split host/port fields.

## Credential and configuration fields

Declared in `MongodbConnector::definition()` (`app/Connectors/Mongodb/MongodbConnector.php`):

| Key | Type | Scope | Secret | Default | Notes |
|-----|------|-------|--------|---------|-------|
| `uri` | password | source | yes | — | `mongodb://` or `mongodb+srv://`; stored encrypted, never echoed |
| `password` | password | source | yes | — | split-config alternative |
| `host` | host | configuration | no | — | alternative to URI |
| `port` | port | configuration | no | `27017` | split-config alternative |
| `username` | text | configuration | no | — | split-config alternative |
| `database` | text | configuration | no | required | the ONE database to import |
| `auth_source` | text | configuration | no | `admin` | authentication database |
| `tls` | boolean | configuration | no | — | `?tls=true` when assembling a URI |
| `read_preference` | select | configuration | no | `primary` | `primary` / `primaryPreferred` / `secondaryPreferred` |
| `sample_size` | port | configuration | no | `100` | documents sampled per collection for inference (28E.1) |
| `server_selection_timeout_ms` | port | configuration | no | `10000` | server-selection and socket timeout |

`analysisRequires()` / `extractionRequires()` return `['database']`.

## URI form

`MongoWireClient::parseUri()` accepts standard URIs:

```
mongodb://user:pass@host1:27017,host2:27018/shop?tls=true&authSource=admin
mongodb+srv://cluster0.abc123.mongodb.net/shop
```

Parsing rules: SRV (`mongodb+srv://`) implies TLS; `tls=true`/`ssl=true`
enables TLS otherwise; `authSource` defaults to the URI database, falling
back to `admin`; credentials are rawurl-decoded. The URI database is
appended automatically when the configured database is missing from the URI
(`MongodbSourceAdapter::effectiveUri()`).

## Split config alternative

When no URI is set, the adapter assembles one:

```
mongodb://[username:password@]host:port/database[?tls=true][&authSource=…]
```

Username/password are rawurlencoded. The split form exists for operators who
keep hostnames in configuration and credentials in the vault separately.

## Where the secret lives

The URI (and the split `password`) are **source-scope secrets**: stored in
the project vault (`project_secrets`, encrypted at rest) and referenced via
`MigrationSource.secret_refs['uri']`. The plaintext never touches the
`connection` JSON, logs, exception messages or the browser. The only safe
display form is `MongoWireClient::redactUri()`:

```
mongodb://shopuser:****@host1:27017/shop
```

## Read preference

Default `primary` — correctness first. The preference is attached as
`readPreference` to every `find`/`count`/`aggregate` command. `secondaryPreferred`
can be chosen for load reasons but is honestly labeled in the UI as
"may be stale": analysis and extraction then read possibly lagging data.

## Timeouts

`server_selection_timeout_ms` (default 10000) is used both for the initial
seedlist connection attempts and for socket read timeouts. Each seed target
is tried in order; the failure message names the last target host:port and
never the URI.

## TLS

TLS is used when the URI is `mongodb+srv://` or carries `tls=true` (or the
`tls` configuration flag). The stream context enables `verify_peer`,
`verify_peer_name` and SNI — certificates are validated, not skipped.
See ATLAS.md for the Atlas specifics.
