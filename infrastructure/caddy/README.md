# Caddy vs Nginx decision: Caddy chosen for automatic HTTPS + compact Caddyfile.
# An Nginx equivalent (kept for migration path) would be:
#   server { listen 80; server_name api.project-a.com; return 301 https://$host$request_uri; }
#   server { listen 443 ssl; ...; location / { proxy_pass http://app:80; proxy_set_header ...; }
#            location /app { proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; ... } }
# Full production Caddyfile with ACME + Cloudflare DNS challenge lives in
# deploy/production/Caddyfile.prod (Phase 8/12).
