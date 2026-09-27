# Self-hosted connections

A local or private-network MongoDB works exactly like Atlas minus SRV: plain
`mongodb://` URIs (or the split host/port configuration), TLS optional.

## Local MongoDB in one line

```bash
docker run --rm -d -p 127.0.0.1:27017:27017 mongo:7
```

Then configure the source with the URI form:

```
mongodb://migrator:<password>@127.0.0.1:27017/shop
```

or the split form (`host` = `127.0.0.1`, `port` = `27017`, credentials as
secrets, `database` = `shop`). TLS off for loopback; `auth_source` defaults
to `admin`.

## Create the read-only user

```bash
docker exec -it <container> mongosh -u root -p <rootpass> --eval '
  db.createUser({ user: "migrator", pwd: "<password>",
                  roles: [ { role: "read", db: "shop" } ] })'
```

The built-in `read` role is sufficient for the entire flow
(READ_ONLY_PERMISSIONS.md).

## Private-network opt-in (28B.3 — required)

Loopback and private-range targets are refused by default by the SSRF guard.
Both of the following must hold:

1. **Operator allowance** — the environment sets
   `CONNECTOR_ALLOW_PRIVATE_NETWORKS=true` (read as
   `config('connectors.allow_private_networks')`).
2. **Declared permission** — the connector manifest carries
   `network.local_source` (it does).

With the opt-in missing, `connect()` fails with an SSRF HttpException —
asserted by
`MongodbConnectorTest::test_local_connection_requires_operator_opt_in`.

## What the opt-in does NOT enable

Cloud metadata endpoints are never allowed, even with
`CONNECTOR_ALLOW_PRIVATE_NETWORKS=true`. `ConnectorNetworkGuard` hard-refuses
`169.254.169.254`, `169.254.169.25`, `100.100.100.200`,
`metadata.google.internal`, `metadata.goog`, `metadata.oraclecloud.com` and
friends — asserted with the opt-in ON in
`MongodbSecurityTest::test_ssrf_guard_blocks_private_and_metadata_targets`.

## Wire-level verification without Docker

The Phase 28 test suite does not need a real server at all: a scripted
wire-protocol server (`tests/Feature/Phase28/Concerns/fake-mongo-server.php`)
speaks OP_MSG on loopback and serves the synthetic `shop` dataset, including
SCRAM authentication. Real-server behavior (replica sets, live Atlas,
deployment-specific authorization) is the Docker dogfood path — see
TEST_MATRIX.md for the honest coverage split.
