# Atlas connections

MongoDB Atlas is the primary hosted target. Everything needed — SRV
discovery, TLS, SCRAM-SHA-256 — is implemented in the pure-PHP wire client
(`app/Connectors/Mongodb/Protocol/`), so no driver installation is involved.

## SRV resolution

`mongodb+srv://cluster0.abc123.mongodb.net/shop` is resolved by
`MongoWireClient::seedlist()`: a DNS SRV lookup on `_mongodb._tcp.<host>`
(`dns_get_record(..., DNS_SRV)`) returns the actual seed targets with their
ports. SRV URIs must not include a port (rejected explicitly). SRV implies
TLS — `parseUri()` sets `tls: true` for any `+srv` scheme
(asserted in `ProtocolTest::test_uri_parsing_standard_srv_tls_and_redaction`).

## TLS always on

The client connects with the `ssl://` transport and a stream context that
enables `verify_peer`, `verify_peer_name` and SNI — certificate validation
is never disabled. Plain `mongodb://` stays unencrypted TCP, so Atlas
deployments should always use the `mongodb+srv://` form (or `tls=true`).

## Authentication

`ScramAuth` implements the RFC 5802 SCRAM exchange against `saslStart` /
`saslContinue`:

| Mechanism | Salted-password derivation |
|-----------|----------------------------|
| SCRAM-SHA-256 (preferred) | SASLprep'd literal password |
| SCRAM-SHA-1 (fallback) | MongoDB's `md5(username:mongo:password)` mapping |

SHA-256 is tried first; only the MongoDB "mechanism unavailable" class of
server codes (59, 66, 72, 313) triggers the SHA-1 fallback. The server
signature is verified with `hash_equals` (mitM detection), sharded
topologies get the extra empty `saslContinue` round trip, and password
material exists in memory only — never logged, echoed or persisted (28T).

## Least-privilege user

The built-in `read` role on the import database is sufficient for the entire
flow (see READ_ONLY_PERMISSIONS.md). In `mongosh`:

```javascript
use admin
db.createUser({
  user: "migrator",
  pwd: "<strong password>",
  roles: [ { role: "read", db: "shop" } ]
})
```

Then connect with:

```
mongodb+srv://migrator:<password>@cluster0.abc123.mongodb.net/shop
```

and select `shop` as the import database (the UI field is required — single
database per import, 28C).

## Verification status (honest)

SRV parsing, the TLS-implied-by-SRV rule, SCRAM-SHA-256 (success, wrong
password → server code 18, unauthenticated → code 13) and the full
read/infer/extract flow are verified against the scripted wire server in
`tests/Feature/Phase28/` (`ProtocolTest`, `MongodbConnectorTest`). The SRV
DNS lookup and the `ssl://` handshake path are implemented per the wire
specifications and exercised architecturally, but a live Atlas verification
requires real cluster credentials and has not been performed in CI — the
Docker dogfood path (SELF_HOSTED.md, TEST_MATRIX.md) covers the real-server
wire behavior that CI can reach.
