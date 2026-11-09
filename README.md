<p align="center"><img src="brand/logo/t2t-logo-horizontal.svg" alt="Team to Team Referrals" width="320"></p>

# Team to Team Referrals

The referral network for licensed, location-bound professionals. Teams join trusted groups, hold one seat per city, and hand out-of-area leads to teams they trust — with consent, signed agreements, response deadlines, tracking and reputation built in. US real estate launches first; further industries follow after legal review.

**Domain:** teamtoteamreferrals.com

## Documentation

| Document | Where |
| --- | --- |
| Product requirements (PRD) and sprint breakdown | [Live PRD](https://claude.ai/artifact/TBJS5fD4BupCu4U3DDYZB1) (exported into `docs/` as it stabilises) |
| Brand guide | [docs/design/brand.md](docs/design/brand.md) |
| Design tokens | [design/tokens.json](design/tokens.json) |
| Component inventory | [docs/design/components.md](docs/design/components.md) |
| Information architecture | [docs/design/information-architecture.md](docs/design/information-architecture.md) |
| Core user flows | [docs/design/user-flows.md](docs/design/user-flows.md) |
| Accessibility and motion | [docs/design/accessibility-and-motion.md](docs/design/accessibility-and-motion.md) |
| Architecture decisions | [docs/adr](docs/adr) |
| Architecture decisions | [docs/adr](docs/adr) |
| How we work | [CONTRIBUTING.md](CONTRIBUTING.md) |

## Stack

PHP 8.4, Laravel 13, React 19 with Inertia 3 (SSR), TypeScript, Tailwind CSS with shadcn/ui, MySQL 8, Redis, Laravel Octane, Horizon and Reverb, Meilisearch, ClickHouse, Stripe, Docker. Mobile apps in React Native.

## Run it locally

Requires Docker.

```bash
docker compose up
```

- App: http://localhost:8000 (Vite on 5173, Mailpit on http://localhost:8025)
- First run installs dependencies, creates `.env` from `.env.docker`, generates the key and migrates. Seed a test member with `docker compose exec app php artisan db:seed` (jordan@example.com / correct horse battery staple).
- Text messages are written to `storage/logs/laravel.log` in development.
- Create the first super admin: `php artisan t2t:super-admin you@example.com --first=Srini --last=Owner` (they get a set-password email, then must turn on two-step sign-in).
- Enable the pre-commit checks once: `composer hooks`
- Run all checks: `composer check` · front end: `npm run lint && npm run types && npm test`
- Component library: `npm run storybook` (http://localhost:6006)
- Design tokens → theme: edit `design/tokens.json`, then `npm run tokens`

Without Docker: PHP 8.4, Composer, Node 22, then `composer setup` and `composer dev`.

## SEO and AEO

Every public page is server-rendered with title, description, canonical, Open Graph and JSON-LD (see `app/Domains/Seo` and ADR 0005). `/robots.txt`, `/sitemap.xml`, `/llms.txt` and the IndexNow key file are generated. Non-production environments are never indexable.

## Status

Phase A (Foundation). Sprint 4b — roles, staff management, audit log and new-device alerts. See [CHANGELOG.md](CHANGELOG.md).

## Brand assets

Logos, icons and social images live in `brand/`. Regenerate with `cd tools/brand && python3 build.py` (needs `fonttools`, `uharfbuzz`, `cairosvg`, `pillow` and the Inter Display font).

---

Proprietary. © 2026 Team to Team Referrals. All rights reserved.
