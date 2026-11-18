# ADR 0008: One DigitalOcean server per deployment, deployed by GitHub Actions with automatic rollback

Status: accepted (Sprint 54a)

## Context

The pilot launches on DigitalOcean (the owner's choice). Members expect the site to stay up during releases, and data
must never be lost. The owner isn't a server administrator, and nobody else should hold the production passwords.
The load test (docs/performance/load-test-2026-11.md) puts pilot traffic well within one 4 vCPU server; the
public pages are cached at Cloudflare.

Options considered:
1. **DigitalOcean App Platform** (managed containers). Least to look after, but no ClamAV or Meilisearch sidecars
   without extra paid components, no control over graceful switching of Octane and Horizon, and costs more per vCPU.
2. **Kubernetes (DOKS).** Built for many servers; far more to run than a pilot needs.
3. **Laravel Forge** on a Droplet. Good, but a paid third party with SSH access to production, and its deploys run
   on the server (builds there, no image rollback).
4. **One Droplet running the production Docker images, deployed from GitHub Actions** (chosen).

## Decision

- One Droplet per environment runs `deploy/compose.yaml`: Caddy, Octane web, Horizon, scheduler, SSR, Redis,
  Meilisearch, ClamAV. The database is DigitalOcean **Managed MySQL** (backups, point-in-time restore, patching);
  private uploads are in **Spaces**; Cloudflare is in front.
- GitHub Actions builds images once per commit (GHCR) and deploys over SSH as a non-root `deploy` user. Secrets live
  only in GitHub environment secrets and on the server; nobody pastes them anywhere else.
- `deploy/bin/deploy.sh` switches with no downtime (a second web container, health-checked, then the old one stops)
  and rolls back by itself, migrations included (exactly this deploy's batch).
- Backups: our own encrypted dumps and file mirrors in a bucket in **another region**, besides DigitalOcean's.
  A monthly automatic restore drill proves they restore.
- The whole flow is rehearsed in CI on every change to the deploy kit.

## Consequences

- Migrations must be backwards compatible for one release (expand, then contract); see docs/operations/deploy.md.
- One server is a single point of failure for the app (not the data). Recovery is a new server from the set-up
  script plus a deploy, under two hours (docs/operations/disaster-recovery.md). Before scaling past one server:
  move the `public` disk to Spaces, run Mercure as its own service (the hub is inside each web container), and put
  a DigitalOcean Load Balancer in front; the images and scripts don't otherwise change.
