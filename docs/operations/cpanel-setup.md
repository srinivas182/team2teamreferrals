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

In **WHM → Terminal** (use the address of whoever will run the demos):
```
sudo -u t2tdeploy /srv/t2t/bin/artisan.sh t2t:super-admin chris@teamtoteamreferrals.com --show-link
```
Open the link it prints and set a password. With `STAFF_TWO_FACTOR=false` (the demo default) two-step sign-in
isn't required; turn it on in **Admin → System → General → Admin sign-in** before going live.

The demo for prospects: with `DEMO_INSTALL=true` (the demo default), anyone can open
https://teamtoteamreferrals.com/demo and enter with one click, as a team or as a network owner. Sample teams,
referrals and earnings; nothing is ever sent; it resets every hour. Nothing to set up. (`t2t:demo create --force`
creates it straight away instead of on the first visit.)

## 7. Monitoring

- The health watch runs every 5 minutes and emails ALERT_EMAIL once an email provider is set (Admin → System →
  Providers; until then alerts are only in `/srv/t2t/logs`). A Slack/Teams webhook in `NOTIFY_WEBHOOK_URL` works at once.
- Add a free external uptime check (UptimeRobot or Better Stack) on `https://teamtoteamreferrals.com/health`: it
  catches the whole server being down, which nothing on the server can report.
- Errors with their cause: set `SENTRY_LARAVEL_DSN` in `ENV_FILE` (see runbooks.md) and re-deploy.

## 8. Going live (after the demo)

Do these in order. Each one is safe to do on its own.

1. **Email (Brevo).** In Brevo, verify the sender address you'll send from (**Senders, domains → Senders**), and
   preferably the whole domain (**Domains**: add the DNS records Brevo shows in **cPanel → Zone Editor**, and add
   `include:spf.brevo.com` to the existing SPF record), so email doesn't land in spam. Then **SMTP & API → API keys →
   Generate a new API key**. In the app: **Admin → System → Email → Brevo (API)**, paste the key, set the From address
   to the verified sender, save, and send the test email. (Brevo (SMTP) also works where the server allows port 587.)
2. **Text messages.** Leave them **off** (Admin → System → Text messages) until Twilio is set up. While off, nobody is
   asked for a phone code.
3. **Turn the demo off and two-step sign-in on.** In GitHub, edit the `ENV_FILE` secret: delete the `DEMO_INSTALL`
   line and set `STAFF_TWO_FACTOR=true`. Then **Actions → Deploy → Run workflow**. Sign in and set up two-step sign-in
   when asked.
4. **Clear the demo data.** In **WHM → Terminal**:
   ```
   sudo -u t2tdeploy /srv/t2t/bin/reset-for-launch.sh          # shows what it would clear; changes nothing
   sudo -u t2tdeploy /srv/t2t/bin/reset-for-launch.sh --go     # backs up the database, then asks you to type the domain
   ```
   The backup is kept in `/srv/t2t/backups/` (`pre-launch-reset-….sql.gz`). Staff accounts, industries, groups,
   places, pages and settings stay; every member, team, referral and the demo go.
5. **Open an industry.** Admin → Industries → the industry → record the compliance sign-off (who reviewed it and
   when) and press **Go live**. Then set its groups Live.
6. **Invite teams.** Admin → Groups → the group → **Seat map → Invite teams → Or upload a list**: upload a CSV with columns
   `email, city, state` (and `name` if you have it). Check the preview, then **Send**. Each invite is valid for 14 days;
   the team signs up with that email and gets the seat with a 3-month free trial.
7. **Backups.** Before real members join, set up the bucket and `BACKUP_*` settings (see "Backups" below).

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
