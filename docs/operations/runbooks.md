# Runbooks

What to do when something goes wrong. Sprint 54a. How it all fits: [deploy.md](deploy.md). Losing a server, the
database or a region: [disaster-recovery.md](disaster-recovery.md).

Sign in to the server with `ssh root@<reserved IP>` (from an IP the DigitalOcean firewall allows; if you can't, use
Droplet → **Console** in DigitalOcean). Then:

```
cd /srv/t2t
sudo -u deploy bin/artisan.sh t2t:health        # what's working and what isn't
docker compose -p t2t ps                         # the containers and their health
docker compose -p t2t logs --tail 200 web        # recent app log (also: horizon, scheduler, ssr, caddy)
```

## Alerts

Alerts come from three places:
- the **health watch** (`bin/watch.sh`, every 5 minutes) by email to ALERT_EMAIL and to the webhook, after two failed
  checks in a row, with a "recovered" message when it passes again
- **DigitalOcean Uptime** (the site can't be reached at all)
- **DigitalOcean Monitoring** (CPU, memory, disk)

The alert names the failing checks. Find them below.

## The site is down

1. Is it everyone? Check https://www.cloudflarestatus.com and https://status.digitalocean.com.
2. On the server: `docker compose -p t2t ps`. A `web` container that isn't `healthy`:
   `docker compose -p t2t logs --tail 200 web`. The watch restarts an unhealthy web container by itself.
3. Did a deploy just happen? Roll back (below).
4. Database unreachable (`t2t:health` says database NO): Databases → `t2t-db` in DigitalOcean. Is it up? Is
   `t2t-prod-1` still in its trusted sources?
5. Still down: turn on Cloudflare's **Always Online**, and post on your status channels.

## A deploy failed

It has already rolled back. The GitHub run and `/srv/t2t/logs/deploy-*.log` say at which step and why. Common causes:
- **pull**: GHCR unreachable, or the images weren't built. Re-run the workflow.
- **backup**: see "Backup failed". Nothing was changed.
- **migrations**: the migration has a bug. Fix it in a pull request.
- **web** (never became healthy) or **verify** (`t2t:health` failed): the log shows which check. Try the release on
  staging, fix, merge again.

If the log says the **migration rollback failed**, the database has a half-applied change: the old release is
running; check the site. If it misbehaves, restore the pre-deploy backup (disaster-recovery.md, "Restore the database").

## Roll back a release by hand

Actions → Deploy → Run workflow → release = an earlier commit from `/srv/t2t/releases.log`. Or on the server:
`sudo -u deploy T2T_IMAGE=ghcr.io/<owner>/<repo> bin/deploy.sh <commit>` (only the last three releases' images are
on the server; older ones need the workflow, which pulls them).

## workers: Horizon is not running / paused

Queued work (emails, notifications, webhooks, imports) is waiting. `docker compose -p t2t restart horizon`, then
`docker compose -p t2t logs --tail 100 horizon`. Paused: `sudo -u deploy bin/artisan.sh horizon:continue`. Failed
jobs: Admin → System → Queues.

## scheduler: no heartbeat

Nightly and every-minute tasks (reminders, digests, expiries) aren't running.
`docker compose -p t2t restart scheduler` and check its log.

## scanner: virus scanning down

Uploads wait until they can be scanned (nothing unscanned is ever opened). ClamAV takes 2–3 minutes to start and
needs about 1.5 GB of memory. `docker compose -p t2t restart clamav`. If memory is short, see "Out of memory".

## search: Meilisearch unavailable

`docker compose -p t2t restart meilisearch`. If its data is lost, rebuild the index:
`sudo -u deploy bin/artisan.sh scout:sync-index-settings` then
`sudo -u deploy bin/artisan.sh scout:import "App\Domains\Identity\Models\User"`.

## ssr: not responding

Pages still work (rendered in the browser instead), but slower first loads and less for search engines.
`docker compose -p t2t restart ssr`.

## disk: less than 10% free

`df -h /` and `docker system df`. Usually old images or logs: `docker image prune -af` (the running release's images
stay), `find /srv/t2t/logs -mtime +30 -delete`. If it's the `t2t_storage` volume, resize the Droplet's disk or add a
Volume (DigitalOcean → Volumes) and move it.

## Out of memory, or CPU high for a long time

Monitoring → the Droplet's graphs. Short term: `docker stats` to see which container. Resize the Droplet
(Droplet → Resize → CPU and RAM only, so it can be resized back; about a minute of downtime: do it out of hours).
The load test numbers for sizing are in `docs/performance/load-test-2026-11.md`.

## backup: failed or older than a day

`/srv/t2t/logs/backup-<date>.log` says why. Usually the Spaces key (revoked or changed: update `ENV_FILE` and
re-deploy) or the database password. Run one by hand: `sudo -u deploy bin/backup.sh`. DigitalOcean's own daily
database backups and 7-day point-in-time restore keep running regardless.

## Restore drill failed

`/srv/t2t/logs/restore-drill-<date>.log`. "Could not decrypt" means BACKUP_PASSPHRASE in `ENV_FILE` no longer matches
the one the backups were made with: put the original back (from your password manager). Run it by hand:
`sudo -u deploy bin/restore-drill.sh`.

## Secrets: change one

Change the GitHub secret (or `ENV_FILE`), then re-run the latest deploy (Actions → Deploy → Run workflow). Never
change `APP_KEY` on a live site without planning: it signs sessions and encrypts stored secrets (provider keys,
two-factor secrets). Never change `BACKUP_PASSPHRASE` without keeping the old one for as long as old backups are kept.

## Lost SSH access

DigitalOcean → Droplet → **Access → Launch Droplet Console** works without SSH. Add your new key to
`/root/.ssh/authorized_keys`. If the GitHub deploy key leaked: re-run `server-setup.sh` after deleting
`/home/deploy/.ssh/authorized_keys` (it makes a new key), and update the `SSH_PRIVATE_KEY` secret.

## Certificates

The Cloudflare origin certificate lasts 15 years. Cloudflare renews the certificate visitors see. The database CA
changes only if DigitalOcean says so (then update `DB_CA_CERT`).

## Scripts and commands referred to

`bin/deploy.sh`, `bin/backup.sh`, `bin/restore-drill.sh`, `bin/watch.sh`, `bin/artisan.sh`, `bin/server-setup.sh`
(in `deploy/` in the repository, `/srv/t2t/bin/` on the server); `t2t:health`, `t2t:ops:record`, `t2t:ops:alert`,
`t2t:super-admin`.
