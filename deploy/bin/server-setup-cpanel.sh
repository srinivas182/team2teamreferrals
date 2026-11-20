#!/usr/bin/env bash
# Set up a cPanel/WHM server (AlmaLinux, Rocky or CloudLinux 8/9) to run Team to Team Referrals beside the other
# websites on it (Sprint 54a: a demo before DigitalOcean). Run as root, in WHM → Terminal or over SSH as root.
#
#   bash server-setup-cpanel.sh install <cPanel user> <domain>        once: Docker, the deploy user, timers
#   bash server-setup-cpanel.sh switch-domain <cPanel user> <domain>  after the first deploy: Apache forwards the
#                                                                     domain to the app (with AutoSSL in front)
#   bash server-setup-cpanel.sh undo-domain <cPanel user> <domain>    puts the domain back to its cPanel website
#
# What it changes, and what it leaves alone:
#   - installs Docker CE. On EL8 this replaces Podman and cPanel's ea-podman (they share a package Docker replaces):
#     the script stops and asks first if they are installed (ALLOW_PODMAN_REMOVAL=1 to go ahead)
#   - Docker publishes nothing to the internet: it binds to 127.0.0.1 by default, and the app listens on
#     127.0.0.1:8088 only. Its own MySQL runs inside Docker; cPanel's MySQL isn't touched.
#   - with CSF: Docker stays out of the firewall ("iptables": false); a CSF hook lets Docker's private networks
#     reach the internet after every CSF start (nothing is opened to the outside)
#   - creates the "t2tdeploy" user that GitHub Actions signs in as, with an SSH key just for that
#   - Apache: adds an include for this one domain only (switch-domain); other sites' configuration is untouched
#   - does NOT change SSH settings, the firewall's open ports, cPanel updates or any other account
set -euo pipefail

ACTION=${1:-}
CPUSER=${2:-}
DOMAIN=${3:-}
DEPLOY_USER=t2tdeploy
PORT=8088
[ "$(id -u)" -eq 0 ] || { echo "Run as root (WHM → Terminal)."; exit 1; }
[ -d /usr/local/cpanel ] || { echo "This is for cPanel servers. On a plain Ubuntu server use server-setup.sh."; exit 1; }
case $ACTION in install | switch-domain | undo-domain) ;; *) sed -n '2,20p' "$0"; exit 2 ;; esac
if [ -z "$CPUSER" ] || [ -z "$DOMAIN" ]; then echo "Usage: $0 $ACTION <cPanel user> <domain>"; exit 2; fi
grep -qE "^${DOMAIN//./\\.}: ${CPUSER}==" /etc/userdatadomains || { echo "$DOMAIN isn't a domain of the cPanel user $CPUSER (see WHM → List Accounts)."; exit 1; }
step() { printf '\n==> %s\n' "$*"; }
# Never stop silently: say which command failed.
trap 'echo "Stopped: the command on line $LINENO failed: $BASH_COMMAND" >&2' ERR

apache_paths() {
	SSL_DIR=/etc/apache2/conf.d/userdata/ssl/2_4/$CPUSER/$DOMAIN
	STD_DIR=/etc/apache2/conf.d/userdata/std/2_4/$CPUSER/$DOMAIN
}
apache_apply() {
	/usr/local/cpanel/scripts/rebuildhttpdconf >/dev/null
	if ! apachectl -t >/dev/null 2>&1; then
		apachectl -t || true
		return 1
	fi
	/usr/local/cpanel/scripts/restartsrv_httpd --graceful >/dev/null 2>&1 || /usr/local/cpanel/scripts/restartsrv_httpd >/dev/null
}

if [ "$ACTION" = undo-domain ]; then
	apache_paths
	rm -f "$SSL_DIR/t2t-proxy.conf" "$STD_DIR/t2t-proxy.conf"
	apache_apply
	echo "$DOMAIN is back to its cPanel website. The app keeps running on 127.0.0.1:$PORT."
	exit 0
fi

if [ "$ACTION" = switch-domain ]; then
	step "Checking the app answers on 127.0.0.1:$PORT"
	curl -fsS -m 10 -H "Host: $DOMAIN" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORT/up" >/dev/null ||
		{ echo "The app isn't answering on 127.0.0.1:$PORT yet. Run the first deploy (GitHub → Actions → Deploy) first."; exit 1; }
	step "Apache modules"
	if ! httpd -M 2>/dev/null | grep -q proxy_http_module; then
		dnf -y -q install ea-apache24-mod_proxy ea-apache24-mod_proxy_http
	fi
	httpd -M 2>/dev/null | grep -q headers_module || dnf -y -q install ea-apache24-mod_headers
	step "Forwarding $DOMAIN (and www.$DOMAIN) to the app"
	apache_paths
	mkdir -p "$SSL_DIR" "$STD_DIR"
	cat >"$SSL_DIR/t2t-proxy.conf" <<EOF
# Team to Team Referrals (Sprint 54a): this domain is served by the app in Docker on 127.0.0.1:$PORT.
# Remove with: server-setup-cpanel.sh undo-domain $CPUSER $DOMAIN
ProxyRequests Off
ProxyPreserveHost On
RequestHeader set X-Forwarded-Proto "https"
RequestHeader set X-Forwarded-Port "443"
# Certificate checks (AutoSSL) stay with cPanel.
ProxyPass /.well-known/acme-challenge/ !
ProxyPass /.well-known/pki-validation/ !
ProxyPass /.well-known/cpanel-dcv/ !
# Real-time updates: kept open and never buffered.
ProxyPass /.well-known/mercure http://127.0.0.1:$PORT/.well-known/mercure flushpackets=on timeout=3600 retry=0
ProxyPassReverse /.well-known/mercure http://127.0.0.1:$PORT/.well-known/mercure
ProxyPass / http://127.0.0.1:$PORT/ timeout=120 retry=0
ProxyPassReverse / http://127.0.0.1:$PORT/
EOF
	cat >"$STD_DIR/t2t-proxy.conf" <<'EOF'
# Team to Team Referrals (Sprint 54a): plain HTTP goes to HTTPS, except cPanel's certificate checks.
RewriteEngine On
RewriteCond %{REQUEST_URI} !^/\.well-known/(acme-challenge|pki-validation|cpanel-dcv)/
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
EOF
	if ! apache_apply; then
		rm -f "$SSL_DIR/t2t-proxy.conf" "$STD_DIR/t2t-proxy.conf"
		apache_apply || true
		echo "Apache rejected the configuration; it was removed again and nothing changed."
		exit 1
	fi
	step "Certificate (AutoSSL)"
	/usr/local/cpanel/bin/autossl_check --user="$CPUSER" >/dev/null 2>&1 || true
	echo "Done. https://$DOMAIN now shows the app. If the browser warns about the certificate, AutoSSL is still"
	echo "issuing it: WHM → Manage AutoSSL → Manage Users → $CPUSER → Check (usually a few minutes)."
	exit 0
fi

# ---- install ----
step "Checking the server"
. /etc/os-release
case "${VERSION_ID%%.*}" in 8 | 9) ;; *) echo "Written for AlmaLinux/Rocky/CloudLinux 8 or 9 (this is $PRETTY_NAME)."; exit 1 ;; esac
mem=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
[ "$mem" -ge 2000 ] || echo "Warning: only ${mem} MB of memory available; the app needs about 2 GB."
if ss -ltn | grep -qE "127\.0\.0\.1:$PORT |\*:$PORT |0\.0\.0\.0:$PORT "; then
	if ! { command -v docker >/dev/null && docker ps --format '{{.Names}}' | grep -q '^t2t-caddy'; }; then
		echo "Port $PORT is already in use on this server."
		exit 1
	fi
fi

step "Tools the deploy uses"
dnf -y -q install rsync python3 openssl curl util-linux >/dev/null

step "Docker"
# Docker itself, not Podman's look-alike "docker" command (which answers "docker version" with Podman's version).
if ! rpm -q docker-ce >/dev/null 2>&1; then
	if [ -n "$(podman ps -aq 2>/dev/null)" ] || compgen -G '/home/*/ea-podman.d/*' >/dev/null; then
		echo "Podman containers exist on this server; installing Docker would remove Podman. Stopping; ask for help."
		exit 1
	fi
	dnf -y -q install dnf-plugins-core crun
	dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
	dnf -y -q remove podman-docker || true
	# Docker's containerd.io replaces the runc package that Podman (and cPanel's ea-podman) need, so dnf removes them.
	# Show what would change and stop if they would go, unless ALLOW_PODMAN_REMOVAL=1 (nothing on the server uses them).
	if rpm -q podman >/dev/null 2>&1 && [ "${ALLOW_PODMAN_REMOVAL:-}" != 1 ]; then
		if dnf install --assumeno --allowerasing docker-ce docker-ce-cli containerd.io 2>&1 | grep -qE '^ *(podman|ea-podman) '; then
			echo "Installing Docker would remove Podman and cPanel's ea-podman. If no account uses ea-podman containers"
			echo "(ls -d /home/*/ea-podman.d shows nothing), run again with ALLOW_PODMAN_REMOVAL=1 in front."
			exit 1
		fi
	fi
	dnf -y install --allowerasing docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
mkdir -p /etc/docker
# With CSF, Docker stays out of the firewall (CSF kept wiping Docker's rules on the first real server; the CSF hook
# below adds what Docker needs). Without CSF, Docker manages its own rules as usual.
if [ -f /etc/csf/csf.conf ]; then FW='"iptables": false, "ip6tables": false,'; else FW=''; fi
cat >/etc/docker/daemon.json <<EOF
{
  "ip": "127.0.0.1", $FW
  "log-driver": "json-file",
  "log-opts": { "max-size": "20m", "max-file": "5" },
  "live-restore": true
}
EOF
printf 'net.ipv4.ip_forward = 1\n' >/etc/sysctl.d/90-t2t-docker.conf
sysctl -q -p /etc/sysctl.d/90-t2t-docker.conf
systemctl enable docker
systemctl restart docker
docker version --format 'Docker {{.Server.Version}} is running.'

if [ -f /etc/csf/csf.conf ]; then
	step "CSF firewall: Docker support"
	# Docker doesn't manage the firewall here ("iptables": false above): CSF kept wiping Docker's rules (found on the
	# first real server). CSF's hook adds what Docker needs after every start: its private networks (172.16.0.0/12)
	# may reach the internet; nothing is opened to the outside. Both backends: CSF is often on iptables-legacy.
	if [ -f /etc/csf/csfpost.sh ] && grep -q 't2t-docker' /etc/csf/csfpost.sh; then
		# Replace an earlier version of this hook (everything from its marker to the end of the file).
		sed -i '/t2t-docker/,$d' /etc/csf/csfpost.sh
	fi
	[ -s /etc/csf/csfpost.sh ] || echo '#!/bin/bash' >/etc/csf/csfpost.sh
	chmod 700 /etc/csf/csfpost.sh
	cat >>/etc/csf/csfpost.sh <<'EOF'
# t2t-docker (Team to Team Referrals): Docker doesn't manage the firewall here ("iptables": false), so after CSF
# starts: let Docker's private networks (172.16.0.0/12) reach the internet. Nothing is opened to the outside.
for IPT in iptables-legacy iptables; do
	command -v $IPT >/dev/null || continue
	$IPT -I FORWARD -s 172.16.0.0/12 -j ACCEPT
	$IPT -I FORWARD -d 172.16.0.0/12 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
	$IPT -t nat -C POSTROUTING -s 172.16.0.0/12 ! -d 172.16.0.0/12 -j MASQUERADE 2>/dev/null ||
		$IPT -t nat -A POSTROUTING -s 172.16.0.0/12 ! -d 172.16.0.0/12 -j MASQUERADE
done
EOF
	csf -r >/dev/null 2>&1 || true
fi
# Tested on a private network like the app's (not only Docker's default one).
docker network create t2t-nettest >/dev/null 2>&1 || true
if docker run --rm --network t2t-nettest alpine:3.20 wget -q -O /dev/null https://www.google.com; then
	echo "Containers can reach the internet."
else
	echo "Warning: containers can't reach the internet (the firewall?). The app needs this to send email and use its providers."
fi
docker network rm t2t-nettest >/dev/null 2>&1 || true

step "Deploy user and folders"
id "$DEPLOY_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$DEPLOY_USER"
usermod -aG docker "$DEPLOY_USER"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 750 /srv/t2t /srv/t2t/bin /srv/t2t/logs /srv/t2t/caddy
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 700 /srv/t2t/secrets /srv/t2t/secrets/certs /srv/t2t/secrets/db "/home/$DEPLOY_USER/.ssh"
KEY=/root/t2t-deploy-key
[ -f "$KEY" ] || ssh-keygen -q -t ed25519 -N '' -C "github-actions-deploy@$(hostname)" -f "$KEY"
grep -qF "$(cut -d' ' -f2 "$KEY.pub")" "/home/$DEPLOY_USER/.ssh/authorized_keys" 2>/dev/null || cat "$KEY.pub" >>"/home/$DEPLOY_USER/.ssh/authorized_keys"
chown "$DEPLOY_USER:$DEPLOY_USER" "/home/$DEPLOY_USER/.ssh/authorized_keys"
chmod 600 "/home/$DEPLOY_USER/.ssh/authorized_keys"
if sshd -T 2>/dev/null | grep -qiE '^(allowusers|allowgroups) '; then
	echo "Note: SSH only lets in some users (AllowUsers/AllowGroups). Add $DEPLOY_USER there, or GitHub can't deploy."
fi

step "Timers: health watch, backups, restore drill"
unit() {
	cat >"/etc/systemd/system/$1.service" <<EOF
[Unit]
Description=$2
ConditionPathExists=/srv/t2t/.release
After=docker.service
[Service]
Type=oneshot
User=$DEPLOY_USER
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
IP=$(tr -d '[:space:]' </var/cpanel/mainip 2>/dev/null || true)
[ -n "$IP" ] || IP=$(hostname -I | awk '{print $1}')
# awk reads everything (no early exit): stopping sshd's output early fails the pipeline.
SSHPORT=$(sshd -T 2>/dev/null | awk '/^port / && !p {print $2; p=1}' || true)
SSHPORT=${SSHPORT:-22}
HOSTKEY=$(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)
if [ "$SSHPORT" = 22 ]; then KNOWN="$IP $HOSTKEY"; else KNOWN="[$IP]:$SSHPORT $HOSTKEY"; fi
rand() { openssl rand -base64 48 | tr -d '/+=\n' | cut -c1-"$1"; }
OUT=/root/t2t-setup.txt
umask 077
{
	echo "=================================================================="
	echo " Team to Team Referrals: this server is ready ($IP, SSH port $SSHPORT)"
	echo "=================================================================="
	echo
	echo "In GitHub: Settings → Environments → production → Environment secrets."
	echo
	echo "1) Secret SSH_PRIVATE_KEY (everything between the lines, including BEGIN and END):"
	echo "------------------------------------------------------------------"
	cat "$KEY"
	echo "------------------------------------------------------------------"
	echo
	echo "2) Secret SSH_KNOWN_HOSTS:"
	echo "------------------------------------------------------------------"
	echo "$KNOWN"
	echo "------------------------------------------------------------------"
	echo
	echo "3) For the ENV_FILE secret (new random values; paste into your copy of deploy/env.cpanel.example):"
	echo "------------------------------------------------------------------"
	echo "APP_KEY=base64:$(openssl rand -base64 32)"
	echo "DB_PASSWORD=$(rand 32)"
	echo "T2T_MYSQL_ROOT_PASSWORD=$(rand 32)"
	echo "MERCURE_JWT_SECRET=$(rand 48)"
	echo "MEILISEARCH_KEY=$(rand 40)"
	echo "BACKUP_PASSPHRASE=$(rand 40)"
	echo "------------------------------------------------------------------"
	echo
	echo "4) Settings → Secrets and variables → Actions → Variables:"
	echo "   PRODUCTION_HOST = $IP"
	[ "$SSHPORT" = 22 ] || echo "   PRODUCTION_SSH_PORT = $SSHPORT"
	echo "   PRODUCTION_SSH_USER = $DEPLOY_USER"
	echo
	echo "Saved to $OUT too. Delete it once copied: rm $OUT $KEY"
} | tee "$OUT"
