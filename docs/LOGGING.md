# Logs & errors (control plane)

Per project (plus owner-console itself): newest daily log parsed server-side,
severity filter, text search, 50/page pagination, severity summary. Test
exceptions are generated inside the project via the allowlisted
`demo:throw-test-exception` command.

## Sanitization

Every line passes through `LogSanitizer` before display: key=value secrets,
Bearer/Basic credentials, and long token/hash blobs become `[REDACTED]`.
Proven: TEST exception visible WITH diagnostic context; passwords, hashes,
and tokens absent from rendered HTML (`RecordBrowserTest`,
`ControlPlaneTest::test_log_sanitizer_redacts_secrets`).
