#!/usr/bin/env bash
# One-time set-up of a new DigitalOcean Droplet (Ubuntu 24.04) for Team to Team Referrals (Sprint 54a).
# Run it once, as root, from your own computer:
#
#   ssh root@<server IP> 'bash -s -- production' < server-setup.sh      (or: staging)
#
# It is safe to run again. It:
#   1. updates Ubuntu and turns on automatic security updates (with a reboot at 09:30 UTC when one needs it)
#   2. installs Docker, with log rotation
#   3. adds 4 GB of swap
#   4. creates the "deploy" user that GitHub Actions signs in as, with a new SSH key just for that
#   5. turns off password sign-in for SSH, and adds a firewall (ufw) and fail2ban
#   6. installs timers: health watch every 5 minutes, nightly backup, monthly restore drill
#   7. prints what to put in GitHub (the deploy key, the server's fingerprint, and new random secrets for .env)
#
# Nothing secret is sent anywhere: the output appears only in your terminal and in /root/t2t-setup.txt (delete
# that file once you have copied the values into GitHub).
set -euo pipefail

ROLE=${1:-production}
[ "$(id -u)" -eq 0 ] || { echo "Run as root."; exit 1; }
grep -q 'VERSION_ID="24.04"' /etc/os-release || echo "Warning: written for Ubuntu 24.04."
export DEBIAN_FRONTEND=noninteractive
step() { printf '\n==> %s\n' "$*"; }

step "Updating Ubuntu"
apt-get update -q
apt-get -y -q -o Dpkg::Options::=--force-confold upgrade
apt-get -y -q install ca-certificates curl gnupg ufw fail2ban unattended-upgrades python3 rsync jq openssl

step "Automatic security updates"
cat >/etc/apt/apt.conf.d/52t2t-unattended <<'EOF'
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "09:30";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
EOF
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
timedatectl set-timezone UTC

step "Docker"
if ! command -v docker >/dev/null; then
	install -m 0755 -d /etc/apt/keyrings
	curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
	chmod a+r /etc/apt/keyrings/docker.asc
	# shellcheck disable=SC1091
	echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" >/etc/apt/sources.list.d/docker.list
	apt-get update -q
	apt-get -y -q install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
mkdir -p /etc/docker
cat >/etc/docker/daemon.json <<'EOF'
{ "log-driver": "json-file", "log-opts": { "max-size": "20m", "max-file": "5" }, "live-restore": true }
EOF
systemctl enable --now docker
systemctl reload docker || systemctl restart docker

step "Swap"
if ! swapon --show | grep -q /swapfile; then
	fallocate -l 4G /swapfile
	chmod 600 /swapfile
	mkswap /swapfile >/dev/null
	swapon /swapfile
	grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
fi
sysctl -qw vm.swappiness=10 vm.overcommit_memory=1
printf 'vm.swappiness=10\nvm.overcommit_memory=1\n' >/etc/sysctl.d/90-t2t.conf

step "Deploy user"
id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/bash deploy
usermod -aG docker deploy
install -d -o deploy -g deploy -m 750 /srv/t2t /srv/t2t/bin /srv/t2t/logs
install -d -o deploy -g deploy -m 700 /srv/t2t/secrets /srv/t2t/secrets/certs /srv/t2t/secrets/db
install -d -o deploy -g deploy -m 700 /home/deploy/.ssh
KEY=/root/t2t-deploy-key
if [ ! -f "$KEY" ]; then
	ssh-keygen -q -t ed25519 -N '' -C "github-actions-deploy@$(hostname)" -f "$KEY"
fi
grep -qF "$(cut -d' ' -f2 "$KEY.pub")" /home/deploy/.ssh/authorized_keys 2>/dev/null || cat "$KEY.pub" >>/home/deploy/.ssh/authorized_keys
chown deploy:deploy /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys

step "SSH, firewall and fail2ban"
cat >/etc/ssh/sshd_config.d/99-t2t.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
MaxAuthTries 4
EOF
sshd -t && systemctl reload ssh
ufw --force default deny incoming >/dev/null
ufw --force default allow outgoing >/dev/null
ufw allow OpenSSH >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw allow 443/udp >/dev/null
ufw --force enable >/dev/null
systemctl enable --now fail2ban >/dev/null

step "Timers: health watch, backups, restore drill"
unit() { # name, description, script, schedule
	cat >"/etc/systemd/system/$1.service" <<EOF
[Unit]
Description=$2
ConditionPathExists=/srv/t2t/.release
After=docker.service
[Service]
Type=oneshot
User=deploy
WorkingDirectory=/srv/t2t
ExecStart=/srv/t2t/bin/$3
EOF
	cat >"/etc/systemd/system/$1.timer" <<EOF
[Unit]
Description=$2
[Timer]
OnCalendar=$4
Persistent=true
RandomizedDelaySec=60
[Install]
WantedBy=timers.target
EOF
}
unit t2t-watch "Team to Team: health watch" watch.sh '*:0/5'
unit t2t-backup "Team to Team: nightly backup" backup.sh '*-*-* 08:10:00'
unit t2t-restore-drill "Team to Team: monthly restore drill" restore-drill.sh '*-*-02 09:40:00'
systemctl daemon-reload
systemctl enable --now t2t-watch.timer t2t-backup.timer t2t-restore-drill.timer >/dev/null

step "Done. Collecting what to put in GitHub"
md() { curl -fsS -m 2 "http://169.254.169.254/metadata/v1/$1" 2>/dev/null || true; }
IP=$(md reserved_ip/ipv4/ip_address)
[ -n "$IP" ] || IP=$(md interfaces/public/0/ipv4/address)
[ -n "$IP" ] || IP=$(curl -fsS -m 5 https://api.ipify.org || hostname -I | awk '{print $1}')
PUBLIC=$(md interfaces/public/0/ipv4/address)
HOSTKEY=$(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)
KNOWN="$IP $HOSTKEY"
[ -n "$PUBLIC" ] && [ "$PUBLIC" != "$IP" ] && KNOWN="$KNOWN
$PUBLIC $HOSTKEY"
rand() { openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-"$1"; }

OUT=/root/t2t-setup.txt
umask 077
{
	echo "=================================================================="
	echo " Team to Team Referrals: $ROLE server is ready ($IP)"
	echo "=================================================================="
	echo
	echo "In GitHub: Settings → Environments → $ROLE → Environment secrets."
	echo
	echo "1) Secret SSH_PRIVATE_KEY: everything between the lines, including BEGIN and END:"
	echo "------------------------------------------------------------------"
	cat "$KEY"
	echo "------------------------------------------------------------------"
	echo
	echo "2) Secret SSH_KNOWN_HOSTS (lets GitHub check it's really this server):"
	echo "------------------------------------------------------------------"
	echo "$KNOWN"
	echo "------------------------------------------------------------------"
	echo
	echo "3) For the ENV_FILE secret: new random values, already filled in."
	echo "   Paste these into your copy of deploy/env.example:"
	echo "------------------------------------------------------------------"
	echo "APP_KEY=base64:$(openssl rand -base64 32)"
	echo "MERCURE_JWT_SECRET=$(rand 48)"
	echo "MEILISEARCH_KEY=$(rand 40)"
	echo "BACKUP_PASSPHRASE=$(rand 40)"
	echo "------------------------------------------------------------------"
	echo "   Also keep BACKUP_PASSPHRASE in your password manager: without it the"
	echo "   database backups can't be opened."
	echo
	echo "4) In GitHub: Settings → Secrets and variables → Actions → Variables:"
	echo "   $(echo "$ROLE" | tr '[:lower:]' '[:upper:]')_HOST = $IP"
	echo
	echo "This was also saved to $OUT. Delete it once copied: rm $OUT $KEY"
} | tee "$OUT"
