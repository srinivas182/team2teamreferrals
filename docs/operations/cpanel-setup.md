# Running on a cPanel/WHM server: step by step

For a demo or early pilot on an existing cPanel VPS, before moving to DigitalOcean
([digitalocean-setup.md](digitalocean-setup.md)). Sprint 54a. About an hour, most of it waiting.

The app runs in Docker beside the other websites, exactly as it will on DigitalOcean:
- **Other sites aren't affected.** Docker publishes nothing to the internet; the app listens on `127.0.0.1:8088`
  only. It has its own MySQL inside Docker (cPanel's MySQL isn't touched). Apache forwards **only this domain** to
  the app, with cPanel's AutoSSL certificate in front. SSH, the firewall's open ports and cPanel updates aren't changed.
- **Leaves out, for now:** the virus scanner (it needs ~1.5 GB of memory; uploads aren't held for scanning) and
  backups to another region (they need a Spaces bucket: add one later, see "Backups"). The app uses about 2 GB of memory.
- Needs: root (WHM), AlmaLinux/Rocky/CloudLinux 8 or 9, 4 CPU cores, about 3 GB of free memory, 20 GB of disk.

**Never paste passwords, keys or the `.env` contents into a chat, an email or a ticket.**

## 1. GitHub (repository → Settings)

1. **Environments → New environment → `production`.**
2. **Secrets and variables → Actions → Variables:** `PRODUCTION_HOST` = the server's IP, `PRODUCTION_URL` =
   `https://teamtoteamreferrals.com`, `PRODUCTION_SSH_USER` = `t2tdeploy`.

## 2. Set up the server (one command, as root)

1. Download `deploy/bin/server-setup-cpanel.sh` (GitHub → the file → **Download raw file**) and upload it with
   **cPanel → File Manager** into the account's home folder (e.g. `/home/teamtoteamreferr/`).
2. **WHM → Terminal** (this is root), run:
   ```
   bash /home/teamtoteamreferr/server-setup-cpanel.sh install teamtoteamreferr teamtoteamreferrals.com
   ```
   About 5 minutes. It installs Docker, creates the `t2tdeploy` user GitHub signs in as, schedules the health watch
   (and backups, once configured), and prints what to put in GitHub. It stops without changing anything if it finds
   Podman containers or cPanel's ea-podman in use, or if port 8088 is taken.
3. From its output, add **environment secrets** to `production`: `SSH_PRIVATE_KEY` and `SSH_KNOWN_HOSTS`. If it
   shows `PRODUCTION_SSH_PORT`, add that **variable** too. Keep the generated values for step 3; put
   `BACKUP_PASSPHRASE` in your password manager.
4. Remove the copies: `rm /root/t2t-setup.txt /root/t2t-deploy-key`

## 3. The settings file

Copy `deploy/env.cpanel.example`, put in the generated values (APP_KEY, DB_PASSWORD, T2T_MYSQL_ROOT_PASSWORD,
MERCURE_JWT_SECRET, MEILISEARCH_KEY, BACKUP_PASSPHRASE) and ALERT_EMAIL, and paste the whole thing into the
environment secret **`ENV_FILE`**. (No database certificate or Cloudflare certificate is needed here.)

## 4. First deploy

1. Add the repository variable **`DEPLOY_ENABLED` = `true`**.
2. **Actions → Deploy → Run workflow → production.** 15–20 minutes the first time (it builds the images, then installs
   the database, industries, US locations and the content pack).
3. Nothing is public yet: the domain still shows its cPanel website.

## 5. Point the domain at the app

In **WHM → Terminal**:
```
bash /home/teamtoteamreferr/server-setup-cpanel.sh switch-domain teamtoteamreferr teamtoteamreferrals.com
```
It checks the app answers, adds an Apache include for this domain only, checks Apache's configuration (and removes
the include again if Apache rejects it), restarts Apache gracefully and asks AutoSSL for a certificate. Open
https://teamtoteamreferrals.com. To put the old website back: the same command with `undo-domain`.

## 6. Your admin login, and the demo

In **WHM → Terminal**:
```
sudo -u t2tdeploy /srv/t2t/bin/artisan.sh t2t:super-admin you@yourcompany.com --show-link
```
Open the link it prints, set a password, sign in, and set up two-factor authentication when asked.

The demo workspace for prospects (sample teams, referrals and earnings that keep themselves fresh; it never sends
email or texts):
```
sudo -u t2tdeploy /srv/t2t/bin/artisan.sh t2t:demo create --force
```
It prints the demo logins. `t2t:demo reset` removes it.

## 7. Monitoring

- The health watch runs every 5 minutes and emails ALERT_EMAIL once an email provider is set (Admin → System →
  Providers; until then alerts are only in `/srv/t2t/logs`). A Slack/Teams webhook in `NOTIFY_WEBHOOK_URL` works at once.
- Add a free external uptime check (UptimeRobot or Better Stack) on `https://teamtoteamreferrals.com/health`: it
  catches the whole server being down, which nothing on the server can report.
- Errors with their cause: set `SENTRY_LARAVEL_DSN` in `ENV_FILE` (see runbooks.md) and re-deploy.

## Day to day

Every merge to `main` deploys by itself, with no downtime and an automatic rollback if anything fails
([deploy.md](deploy.md)). Logs: `sudo -u t2tdeploy docker compose -p t2t logs --tail 100 web` in WHM → Terminal.
What to do when something breaks: [runbooks.md](runbooks.md).

## Backups

Until a Spaces bucket is set up, backups are **off**: WHM/cPanel backups don't include the app's Docker data. For a
demo that's acceptable; before real members join, either move to DigitalOcean or create a Spaces bucket (Part 1
step 4 of digitalocean-setup.md) and fill in the `BACKUP_*` settings in `ENV_FILE`. Backups then run nightly with
the monthly restore drill, as on DigitalOcean.

## Moving to DigitalOcean later

1. Set up DigitalOcean (digitalocean-setup.md) and deploy there once, on a temporary address.
2. Copy the database: on this server `sudo -u t2tdeploy docker compose -p t2t exec -T mysql mysqldump -ut2t -p --single-transaction --set-gtid-purged=OFF t2t > t2t.sql`,
   then load it into the managed database. Copy the files: `docker run --rm -v t2t_storage:/data -v $PWD:/out alpine tar czf /out/storage.tgz -C /data .`
   and unpack into the new server's `t2t_storage` volume.
3. Point the domain's DNS at the new server, then `undo-domain` here.
