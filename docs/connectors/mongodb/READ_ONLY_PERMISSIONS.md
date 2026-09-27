# Read-only permissions and the command allowlist

The built-in `read` role is sufficient for the ENTIRE flow: discovery,
analysis, inference and extraction are pure reads. There is no step that
needs `readWrite`, `dbAdmin` or cluster roles.

## Create the user (least privilege)

```javascript
use admin
db.createUser({
  user: "migrator",
  pwd: "<strong password>",
  roles: [ { role: "read", db: "shop" } ]
})
```

Only the import database is scoped. The authentication commands
(`saslStart`/`saslContinue`) run against the auth source (`admin` by
default) and only authenticate the connection — they mutate nothing.

## The hard read-only allowlist

`MongoWireClient::READ_COMMANDS`
(`app/Connectors/Mongodb/Protocol/MongoWireClient.php`) is a fixed constant.
`run()` checks the command name BEFORE building or sending anything:

| Command | Used for |
|---------|----------|
| `hello` | connection handshake / server version (`testConnection`) |
| `ping` | liveness |
| `buildInfo`, `connectionStatus` | server introspection |
| `listDatabases` | database discovery + the 28T accessibility check |
| `listCollections` | collection/view enumeration (system namespaces excluded) |
| `listIndexes` | index inventory (28G) |
| `find` | sampling (28E) and extraction (28H) |
| `getMore` | cursor continuation (batched streaming) |
| `count` | exact collection / validation counts (28Q) |
| `aggregate` | `$collStats` storage stats (best-effort, honest empty on refusal) |
| `saslStart`, `saslContinue` | SCRAM authentication handshake only |

Anything else — `insert`, `update`, `delete`, `drop`, `dropDatabase`,
`dropCollection`, `findAndModify`, `createIndexes`, `collMod`, … — throws a
`LogicException` ("not on the read-only allowlist") before serialization:
write commands are structurally impossible to send, not merely discouraged.

## Layers of the guarantee

1. **Client allowlist** — the wire client cannot emit a write command.
2. **Credential scope** — the documented setup uses the built-in `read`
   role, so even a hypothetical bypass would be refused server-side.
3. **Source record** — `createSourceProfile()` always creates the
   `MigrationSource` with `read_only: true`
   (`MongodbSecurityTest::test_sources_stay_read_only_through_the_connector`).

## Verification

- `ProtocolTest::test_write_commands_are_structurally_impossible` and
  `MongodbSecurityTest::test_source_write_operations_are_structurally_impossible`
  try nine write commands each and assert the `LogicException`.
- The 28V.3 immutability check re-computes the source fingerprint after a
  full migration run and fails on any drift.
- Honest caveat (consistent with ATLAS.md): CI verifies against the scripted
  wire server, which does not model MongoDB's server-side authorization;
  the `read` role recommendation follows MongoDB's least-privilege guidance.
  On deployments that additionally restrict `listDatabases`, the discovery
  accessibility check is the first command to confirm with the new user.
