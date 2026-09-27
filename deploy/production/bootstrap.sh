#!/usr/bin/env bash
# Fresh Ubuntu 24.04 VPS bootstrap: hardening + Docker + repo + secrets + up.
# Run ONCE as root on the new VPS. NEVER run locally.
# Usage: bootstrap.sh <git_repo_url> [deploy_user]
set -euo pipefail
REPO="${1:?usage: $0 <git_repo_url> [deploy_user]}"
USER="${2:-deploy}"

apt-get update && apt-get upgrade -y
apt-get install -y ca-certificates curl gnupg ufw fail2ban unattended-upgrades rclone cron

# --- hardening: ssh ---
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
systemctl reload sshd

# --- firewall: 22/80/443 only ---
ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# --- docker (official repo, pinned major) ---
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
apt-get update && apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

# --- deploy user + repo ---
id "$USER" &>/dev/null || useradd -m -s /bin/bash "$USER"
usermod -aG docker "$USER"
su - "$USER" -c "git clone $REPO /home/$USER/backend-infrastructure"
echo "NEXT: su - $USER; cd backend-infrastructure; cp .env.example .env; <fill secrets>; docker compose up -d"
echo "THEN: sh infrastructure/monitoring/healthcheck.sh"
