# Going live on DigitalOcean: step by step

For the owner. About 2–3 hours the first time, most of it waiting. You do everything in a browser except one command
in Part 2. Nobody else needs your passwords: secrets go straight into GitHub, and GitHub deploys to the server.

**Never paste passwords, keys or the `.env` contents into a chat, an email or a ticket.** Keep them in a password manager.

What you'll end up with:

| Piece | What it is | Roughly per month |
|---|---|---|
| Droplet `t2t-prod-1` | Ubuntu 24.04, 4 vCPU / 8 GB (Premium AMD or Intel). Runs the app, queues, search, virus scanning | $56 + $11 backups |
| Managed MySQL `t2t-db` | MySQL 8, 1 vCPU / 2 GB, daily backups, 7 days of point-in-time restore | $30 |
| Spaces | `t2t-files` (NYC3, private uploads) and `t2t-backups` (SFO3, backups in another region) | $5 |
| Cloudflare (free) | DNS, CDN for public pages, DDoS protection | $0 |
| Staging (optional) | A 2 vCPU / 4 GB Droplet and a second database on the same MySQL cluster | $24 |

Prices are DigitalOcean's list prices when this was written; check the pricing page when you sign up.

## Part 1: account and resources

Use **one region for everything except the backup bucket**: New York 3 (NYC3) suits US users.

1. **Account.** Sign up at digitalocean.com with a company address (ops@ or admin@), not a personal one. Verify it and
   add a payment method. Then:
   - Settings → Security: turn on **two-factor authentication**.
   - Billing → Billing alerts: alert at, say, $150.
   - Create a **Team** for the company, and a **Project** "Team to Team Referrals". Create everything below in it.
2. **Your SSH key** (on your computer). Open Terminal (Mac) or PowerShell (Windows) and run
   `ssh-keygen -t ed25519 -C "t2t-admin"`. Press Enter at each question (a passphrase is a good idea). Then in
   DigitalOcean: Settings → Security → **Add SSH Key**, and paste the contents of the `.pub` file it made
   (`~/.ssh/id_ed25519.pub`).
3. **Database.** Databases → Create Database Cluster → **MySQL 8**, NYC3, Basic, 1 vCPU / 2 GB. Name `t2t-db`. When it's ready:
   - Users & Databases: add database **`t2t`** and user **`t2t`**. Copy the user's password into your password manager.
   - Overview: **Download CA certificate** (you'll need it in Part 2).
4. **Spaces.** Spaces Object Storage → Create Bucket: **`t2t-files`** in NYC3. Then a second one, **`t2t-backups`** in
   **SFO3**. Both private (no file listing, no CDN). Then Spaces Object Storage → Access Keys → **Create Access Key**
   (full access). Copy the key and secret into your password manager.
5. **Droplet.** Droplets → Create: Ubuntu **24.04 LTS**, NYC3, Basic → Premium AMD (or Intel) **4 vCPU / 8 GB**.
   Authentication: **SSH Key** (yours). Tick **Monitoring** and **Backups** (weekly). Hostname **`t2t-prod-1`**.
6. **Reserved IP.** Networking → Reserved IPs → assign one to `t2t-prod-1`. This is the server's address from now on.
7. **Firewall.** Networking → Firewalls → Create `t2t-web`. Inbound: HTTP (80) and HTTPS (443) from all; SSH (22) from
   your own IP only (the form offers "My IP"). Apply it to `t2t-prod-1`.
8. **Database access.** Databases → `t2t-db` → Network Access (Trusted sources): add `t2t-prod-1`. Nothing else can reach it.
9. **Domain.** Add the domain to Cloudflare (free plan) and change the nameservers at your registrar to Cloudflare's.
   In Cloudflare:
   - DNS: `A @` → the reserved IP and `A www` → the reserved IP, both **Proxied** (orange cloud).
   - SSL/TLS → Overview: **Full (strict)**. SSL/TLS → Edge Certificates: **Always Use HTTPS** on.
   - SSL/TLS → Origin Server → **Create Certificate** (keep the defaults: your domain and `*.domain`, 15 years).
     Copy the **certificate** and the **private key** into your password manager; the key is shown only once.
10. **Monitoring** (once the site is live, after Part 2):
    - Monitoring → Uptime → New check: `https://<domain>/health`, every minute, alert by email.
    - Monitoring → Alert policies for `t2t-prod-1`: CPU above 80% for 10 minutes, memory above 85%, disk above 80%.

## Part 2: deploy

### A. Prepare GitHub (repository → Settings)

1. **Environments → New environment → `production`.** Optional but recommended: tick *Required reviewers* and add
   yourself, so each release waits for your click.
2. **Secrets and variables → Actions → Variables** (repository variables): `PRODUCTION_HOST` = the reserved IP,
   `PRODUCTION_URL` = `https://<domain>`. Don't add `DEPLOY_ENABLED` yet.

### B. Set up the server (one command)

1. In GitHub, open `deploy/bin/server-setup.sh` and click **Download raw file**.
2. In Terminal/PowerShell, from the folder it downloaded to:
   `ssh root@<reserved IP> 'bash -s -- production' < server-setup.sh`
   It takes about five minutes. It updates Ubuntu, installs Docker, creates the `deploy` user, locks down SSH, turns
   on the firewall, schedules the health watch, backups and restore drill, and prints what to put in GitHub.
3. From its output, add these **environment secrets** to `production` (Settings → Environments → production):
   - `SSH_PRIVATE_KEY` (the whole key, including the BEGIN and END lines)
   - `SSH_KNOWN_HOSTS`
   - Keep the four generated values (APP_KEY and so on) for step C. Put **BACKUP_PASSPHRASE** in your password
     manager too: without it the database backups can't be opened.
4. Remove the copy on the server: `ssh root@<reserved IP> rm /root/t2t-setup.txt /root/t2t-deploy-key`

### C. The rest of the secrets

Add these environment secrets to `production`:

| Secret | What to paste |
|---|---|
| `ENV_FILE` | Your filled-in copy of `deploy/env.example`: the four generated values, the domain, the database host and password (Databases → t2t-db → Connection details → **VPC network**, "Connection parameters"), the Spaces key and secret, and ALERT_EMAIL |
| `DB_CA_CERT` | The CA certificate you downloaded in Part 1 step 3 (open it in a text editor and copy all of it) |
| `ORIGIN_CERT` | The Cloudflare origin certificate |
| `ORIGIN_KEY` | Its private key |

The deploy refuses to start while `ENV_FILE` still has `<placeholders>`, and says which.

### D. First deploy

1. Add the repository variable **`DEPLOY_ENABLED` = `true`**.
2. Actions → **Deploy** → Run workflow → `production`. The first one takes 10–15 minutes (it installs the database,
   industries, US locations and the content pack).
3. Open `https://<domain>`. Then create your super-admin account:
   `ssh -t root@<reserved IP> sudo -u deploy /srv/t2t/bin/artisan.sh t2t:super-admin you@<company domain> --show-link`
   It prints a link to set your password (email isn't set up yet). Open it, set a password, sign in, and set up
   two-factor authentication when asked.
4. From now on, **every merge to `main` deploys by itself** (after your click, if you turned on required reviewers).

### E. In the app (Admin → System)

1. **Providers → File storage:** S3-compatible, endpoint `https://nyc3.digitaloceanspaces.com`, bucket `t2t-files`,
   region `us-east-1`, the Spaces key and secret. Press **Check**.
2. **Providers → Email, SMS, payments, AI, sign-in with Google/Microsoft** as each account is ready. Press **Check** on each.
3. **General:** company details, support, press and security emails.

### F. Staging (optional, recommended before the pilot)

Same as above with: a 2 vCPU / 4 GB Droplet `t2t-staging-1`; a second database `t2t_staging` (and user) on the same
cluster; environment `staging`; variables `STAGING_HOST` and `STAGING_URL`; a subdomain such as `staging.<domain>`;
`SEO_INDEXABLE=false` and an empty `BACKUP_BUCKET` in its `ENV_FILE`. When staging exists, each merge goes to staging
first and to production only if staging passed. The full load test (`loadtest/README.md`) runs against staging.

## Rolling back

Actions → Deploy → Run workflow → put the commit of the release you want back in **release**. Releases are listed on
the server in `/srv/t2t/releases.log`. A failed deploy already rolls back by itself; this is for a release that works
but that you don't want. More in [runbooks.md](runbooks.md).
