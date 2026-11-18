# How deploys work

Sprint 54a. Set-up: [digitalocean-setup.md](digitalocean-setup.md). When something goes wrong: [runbooks.md](runbooks.md).

## The shape

```
Visitor ─▶ Cloudflare (CDN, TLS, DDoS) ─▶ Droplet: Caddy :443 ─▶ web (Octane/FrankenPHP, Mercure hub) ─▶ Managed MySQL (TLS, VPC)
                                                                   ├─ ssr (Node, server-side rendering)
                                                                   ├─ redis (cache, sessions, queues; append-only)
                                                                   ├─ meilisearch (search; rebuilt from MySQL if lost)
                                                                   └─ clamav (virus scanning of uploads)
                                          horizon (queues) and scheduler: same image as web
Private uploads ─▶ Spaces t2t-files (NYC3)        Backups ─▶ Spaces t2t-backups (SFO3, another region)
```

Everything on the Droplet runs in Docker, defined by `deploy/compose.yaml`, from images built by GitHub Actions and
stored in GitHub's container registry (GHCR) under the commit they were built from. Nothing is built on the server.

## A release, start to finish

1. A pull request passes CI (including the **Deploy rehearsal** whenever the deploy kit or the production image
   changes) and is merged to `main`.
2. `.github/workflows/deploy.yml` builds the app and SSR images once and pushes them to GHCR.
3. Staging (if set up), then production (after a click, if required reviewers are on), each through
   `deploy-server.yml`: it copies `deploy/` and the secrets to `/srv/t2t`, then runs `bin/deploy.sh <commit>`.
4. `bin/deploy.sh` on the server:
   1. pulls the images
   2. backs up the database (`db/pre-deploy/`, kept 14 days); if the backup fails, it stops with nothing changed
   3. runs new migrations (the first deploy runs `t2t:install`), then syncs permissions, industries and search settings
   4. starts the new SSR, then a **second web container** on the new release beside the old one
   5. waits for the new container's health check; Caddy sends traffic to every healthy web container
   6. stops the old web container gracefully (requests in flight finish), then restarts Horizon (jobs in hand get 90
      seconds to finish) and the scheduler on the new release
   7. checks everything: `t2t:health` (database, cache, queue, storage, search, workers, SSR, migrations, disk) and a
      request through Caddy
   8. records the release in `/srv/t2t/.release` and `releases.log`, keeps the images of the last three releases,
      and posts to the alert webhook
5. If any step from 3 on fails, `deploy.sh` rolls back by itself: it undoes this deploy's migrations
   (`migrate:rollback`, one batch), starts the previous release's containers beside the failed ones, waits until they
   are healthy, then stops the failed ones. The workflow fails, so GitHub emails you, and the webhook gets the reason.

The CI rehearsal (`scripts/ci/deploy-rehearsal.sh`) runs all of this against a real stack on every change to the
deploy kit: a first install on MySQL with `sql_require_primary_key` on (as DigitalOcean's is), a deploy with a
migration under continuous requests (none may fail), a release that never becomes healthy and one that fails after
the switch (both must roll back with their migrations undone and no failed requests), backups to S3-compatible
storage, the restore drill, the health watch, and a manual rollback.

## Rules for code that will be deployed this way

- **Migrations must work with the release before them** (expand, then contract). For a short time both releases run
  against the new schema, and a rollback runs the old code against it. Add columns and tables in one release; stop
  using and drop old ones in a later release. Rename by adding the new column, copying, switching code, then dropping
  the old one later.
- **Every migration needs a working `down()`**; the automatic rollback runs it. Every table needs a primary key
  (DigitalOcean's MySQL refuses tables without one; `tests/Feature/Platform/GoLiveTest.php` checks).
- **Queued jobs must survive a release change**: a job queued by the old release may run on the new one. Keep job
  constructor arguments backwards compatible for one release.
- Long data back-fills go in a queued job, not in a migration, so the deploy doesn't wait on them.

## Rolling back by hand

Actions → Deploy → Run workflow → **release** = the commit to go back to (see `/srv/t2t/releases.log`). The images
of the last three releases are on the server, so this takes about a minute. Migrations are not rolled back by a
manual rollback (the older code works with the newer schema, see above).

## Where things are on the server

| Path | What |
|---|---|
| `/srv/t2t/.env` | The app's settings (written from the `ENV_FILE` secret on every deploy; edit the secret, not the file) |
| `/srv/t2t/secrets/` | Database CA and the Cloudflare origin certificate (from secrets) |
| `/srv/t2t/.release`, `releases.log` | The live release and the history |
| `/srv/t2t/logs/` | `deploy-*.log`, `backup-*.log`, `restore-drill-*.log` |
| `/srv/t2t/bin/` | The scripts (replaced on every deploy) |
| Docker volume `t2t_storage` | Public images (profile photos, logos), reports, temporary files |

App logs: `docker compose -p t2t logs -f web` (JSON lines; also sent to Sentry once it's set up).
