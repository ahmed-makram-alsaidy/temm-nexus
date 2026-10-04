// Managed OpenCode healthcheck (Phase 43).
//
// GET /global/health requires the server's basic-auth credentials, so the
// check authenticates with the same OPENCODE_SERVER_* env the service runs
// with. Exit 0 only when the runtime answers healthy:true.
import http from 'node:http';

const user = process.env.OPENCODE_SERVER_USERNAME || 'opencode';
const pass = process.env.OPENCODE_SERVER_PASSWORD || '';

const req = http.get(
    {
        host: '127.0.0.1',
        port: 4096,
        path: '/global/health',
        headers: { Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') },
        timeout: 3000,
    },
    (res) => {
        let body = '';
        res.on('data', (c) => (body += c));
        res.on('end', () => {
            try {
                process.exit(JSON.parse(body).healthy ? 0 : 1);
            } catch {
                process.exit(1);
            }
        });
    },
);

req.on('timeout', () => {
    req.destroy();
    process.exit(1);
});
req.on('error', () => process.exit(1));
