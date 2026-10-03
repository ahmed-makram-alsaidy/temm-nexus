# AgentRouter / Nexus AI — Transport Acceptance Runbook (0.4.0-rc.7)

This runbook closes the incident where the SAME VPS could call
`https://agentrouter.org/v1/chat/completions` successfully from codex CLI
(User-Agent `codex_cli_rs/0.149.1`, HTTP 200) while
**Settings → Nexus AI → Test Connection** reported
*"Connection failed — The provider returned an error."*

## What the investigation found

Empirical loopback-capture evidence (reproducible in the test suite):

1. **Laravel/Guzzle do NOT override a configured custom User-Agent.** With
   `User-Agent: codex_cli_rs/0.149.1` in `ai_provider_configs.custom_headers`,
   the raw wire request carries exactly that header (verified at the socket
   level in `tests/Feature/Phase42/TransportTelemetryTest.php`). Guzzle's
   `GuzzleHttp/7` default is applied ONLY when no custom UA is configured —
   which is the shape AgentRouter refuses.
2. **Header merge order is now an explicit contract** in
   `OpenAiCompatibleDriver::client()`: framework defaults → validated custom
   provider headers LAST → the vault credential (Authorization) re-asserted
   after the merge. Test Connection and real chat build the client through
   that ONE method, so they can never have different transport configs.
3. **The reported Test Connection failure had a second cause.** The probe
   sent `max_tokens: 1`; reasoning-style models (DeepSeek V4 family) spend
   the whole budget on reasoning and answer HTTP 200 with EMPTY `content`.
   The old classifier called that PROVIDER_ERROR — exactly the observed
   message — while the same key/endpoint/model worked from codex (real
   token budget). The probe now sends a small sane budget (≤ 32 tokens) and
   classifies a 200 ending in `finish_reason: "length"` as CONNECTED.
4. Every wire call now sends `"stream": false` explicitly, and every call is
   observed by `TransportTelemetry` (safe: URL, method, status,
   Content-Type, effective User-Agent, JSON field names, stream value; the
   Authorization value and the API key are structurally excluded and the key
   is redacted from any error-body preview).

## Running the acceptance on the VPS

From `apps/owner-console` (the configured key never leaves the server and is
never printed):

```bash
# 0 — deploy this build (or sync app/, config/, scripts/, tests/) and clear caches
php artisan config:clear

# 1 — the five gates against the REAL AgentRouter row
php scripts/agentrouter-acceptance.php
# optional: pin the row explicitly
php scripts/agentrouter-acceptance.php --provider-id=<id>

# exit code 0 = all gates PASS
```

Expected output: the transport comparison block (final URL, method, status,
Content-Type, `user_agent=codex_cli_rs/0.149.1`, `json_fields=[model,
messages,max_tokens,stream]`, `stream=false`) followed by:

```
PASS  hello_visible
PASS  test_connection
PASS  normal_reply
PASS  no_blank_bubble
PASS  secret_leakage_zero
```

The same wire facts are continuously available in
`storage/logs/nexus-ai.log` (`ai_transport.request` / `ai_transport.response`
/ `ai_transport.connection_failure`).

If the master switch (Settings → Nexus AI) is OFF, the script says so —
enable it and re-run.

## Offline self-validation (no network, no key)

`--mock` boots `scripts/mock-agentrouter.php` (which refuses non-codex
User-Agents exactly like the real gateway), creates a throwaway provider row
+ acceptance user in the LOCAL app database, runs the identical gates, and
removes the throwaway provider afterwards:

```bash
php scripts/agentrouter-acceptance.php --mock
```

`--mock` sets the loopback allowance in-process, so no `.env` change is
needed — `AI_ALLOW_LOOPBACK_ENDPOINTS` must stay `false` in production.

## Related configuration

```dotenv
NEXUS_AI_TELEMETRY=true                  # safe wire telemetry on/off
NEXUS_AI_TELEMETRY_CHANNEL=nexus-ai      # log channel
NEXUS_AI_TELEMETRY_LEVEL=debug
AI_ALLOW_LOOPBACK_ENDPOINTS=false        # local dev only — never in prod
```

## Regression coverage

`tests/Feature/Phase42/TransportTelemetryTest.php` (release gate:
`vendor/bin/phpunit -c phpunit-release.xml`):

- effective outgoing User-Agent is `codex_cli_rs/0.149.1` on the RAW wire for
  both `test()` and `complete()` (loopback capture, not `Http::fake()`);
- without a configured UA, Guzzle stamps `GuzzleHttp/7` (documented failure
  mode);
- custom headers apply last; Authorization is re-asserted after the merge
  (`['Accept', 'User-Agent', 'Authorization']` order contract);
- telemetry records URL/method/status/Content-Type/User-Agent/JSON field
  names/stream and is structurally incapable of containing the key or the
  Authorization value (including a 403 body that echoes the key);
- `finish_reason: "length"` probe → CONNECTED; probe budget ≤ 32;
  `stream: false` on both wire kinds;
- SSRF guard still refuses loopback unless the explicit dev flag is set.
