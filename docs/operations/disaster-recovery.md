# Disaster recovery

Sprint 54a. Day-to-day problems: [runbooks.md](runbooks.md).

## Targets

| | Target | How |
|---|---|---|
| Data loss (RPO), database | Minutes | DigitalOcean point-in-time restore (7 days); our encrypted dump nightly and before every deploy |
| Data loss (RPO), files | 24 hours (server files); none for private uploads (Spaces keeps them) | Nightly mirror to the backup bucket, changed/deleted files kept 90 days |
| Time to recover (RTO) | Under 2 hours to a new server; minutes for a bad deploy | Scripts below; the restore drill measures the database part monthly |

## What is kept, and where

| Data | Where it lives | Backed up to | Kept |
|---|---|---|---|
| Database | Managed MySQL `t2t-db` (NYC3) | DigitalOcean daily backups + point-in-time; our dumps in `t2t-backups` (SFO3), AES-256 encrypted | PITR 7 days; daily 35 days; monthly 13 months; pre-deploy 14 days |
| Private uploads (licences, W-9s, agreements, attachments) | Spaces `t2t-files` (NYC3) | `t2t-backups/files/private` | Mirror + 90 days of changed/deleted files |
| Public images, reports, temporary files | Docker volume `t2t_storage` on the Droplet | `t2t-backups/files/storage` | Mirror + 90 days |
| Search index | Meilisearch volume | Not backed up: rebuilt from the database | — |
| Cache, sessions, queues | Redis volume (append-only) | Not backed up: queued jobs can be lost in a full server loss; sessions sign people out | — |
| The server itself | Droplet | DigitalOcean weekly Droplet backups; but a new Droplet from `server-setup.sh` is faster and cleaner | 4 weeks |
| Code and images | GitHub, GHCR | — | — |
| Settings and secrets | GitHub environment secrets; your password manager | — | — |

**Keep BACKUP_PASSPHRASE, the Spaces key and the DigitalOcean and GitHub sign-ins in a password manager that isn't
on the server.** Without the passphrase our dumps can't be opened (DigitalOcean's own backups still can).

## Drills

- **Monthly, automatic:** `bin/restore-drill.sh` downloads the newest dump, decrypts it, loads it into a throwaway
  MySQL, compares it with the live database and the file backups, and records the result and the time it took.
- **Every change to the deploy kit:** the CI rehearsal backs up and restores on a real stack.
- **Twice a year, by hand:** rebuild staging from production's backups (scenario 1, against staging), and time it.

## Scenarios

### 1. The Droplet is lost or broken beyond repair

1. Create a new Droplet as in digitalocean-setup.md Part 1 step 5 (same region, SSH key, monitoring).
2. Move the **reserved IP** to it (Networking → Reserved IPs → Reassign). DNS doesn't change.
3. Add it to the database's trusted sources and the firewall.
4. Run `server-setup.sh` on it, and update `SSH_PRIVATE_KEY` and `SSH_KNOWN_HOSTS` from its output.
5. Put the server files back (as the deploy user, once `/srv/t2t/.env` exists, i.e. after step 6 fails or before
   the first deploy finishes): `docker volume create t2t_storage`, then with rclone from the backup bucket:
   `docker run --rm -v t2t_storage:/data -e RCLONE_CONFIG_BK_TYPE=s3 -e RCLONE_CONFIG_BK_PROVIDER=DigitalOcean -e RCLONE_CONFIG_BK_ENDPOINT=https://sfo3.digitaloceanspaces.com -e RCLONE_CONFIG_BK_ACCESS_KEY_ID=… -e RCLONE_CONFIG_BK_SECRET_ACCESS_KEY=… rclone/rclone:1.69 copy bk:t2t-backups/files/storage /data`
   then `docker run --rm -v t2t_storage:/data alpine chown -R 10001:10001 /data`.
6. Actions → Deploy → Run workflow → production. The database is untouched (it's managed), so this is an ordinary
   deploy. Note: the new server has no `.release`, so the deploy treats it as a first install; `t2t:install` is safe to
   run on an existing database (it only adds what's missing).
7. Rebuild search: runbooks.md, "search".

### 2. Bad data in the database (a bug, a mistaken bulk change)

Prefer DigitalOcean's **point-in-time restore**: Databases → `t2t-db` → Backups → **Restore from backup** → pick the
minute just before the problem. This creates a **new cluster**; it doesn't touch the live one. Then either copy the
affected rows back (safest), or switch the app to the new cluster: update `DB_HOST` (and the CA if it differs) in
`ENV_FILE`, add the Droplet to the new cluster's trusted sources, and re-deploy.

### 3. Restore the database from our own dump

(When DigitalOcean's backups can't help, e.g. older than 7 days, or the account is gone.)
1. Find the dump: `rclone lsf bk:t2t-backups/db/daily` (or `monthly`, `pre-deploy`).
2. Check it restores: `sudo -u deploy bin/restore-drill.sh db/daily/<file>`.
3. Download, decrypt and load into an **empty** database (a new cluster, or a new database on the cluster):
   `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass env:BACKUP_PASSPHRASE -in <file> | gunzip | mysql --host=… --port=25060 --user=… --ssl-ca=… <database>`
4. Point `DB_HOST`/`DB_DATABASE` at it in `ENV_FILE` and re-deploy.

### 4. The NYC3 region is down

The app can't run there; the backups are safe in SFO3. Follow scenario 1 in **SFO3** with a new database cluster,
restore the newest dump into it (scenario 3), point DNS (Cloudflare) at the new reserved IP. Expect up to a day of
data loss for anything after the last nightly dump (pre-deploy dumps may be newer).

### 5. Account compromise

1. Change the DigitalOcean, GitHub and Cloudflare passwords; check 2FA; remove unknown team members and API tokens.
2. Rotate: the Spaces key, the database user password, the GitHub deploy key (runbooks.md, "Lost SSH access"),
   provider keys in Admin → System → Providers. Update the secrets and re-deploy.
3. Check Admin → System → Activity log and the server's `/var/log/auth.log`.
4. If data may have been taken, follow the incident steps in `docs/security/review-2026-11.md` and get legal advice
   about notifying people.
