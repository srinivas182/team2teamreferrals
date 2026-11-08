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
| How we work | [CONTRIBUTING.md](CONTRIBUTING.md) |

## Stack

PHP 8.4+, Laravel, React with Inertia.js (SSR), Tailwind CSS with shadcn/ui, MySQL 8, Redis, Laravel Octane, Horizon and Reverb, Meilisearch, ClickHouse, Stripe, Docker. Mobile apps in React Native.

## Status

Phase 0 (Design). Sprint 1 — brand, design system and user flows. See [CHANGELOG.md](CHANGELOG.md).

## Brand assets

Logos, icons and social images live in `brand/`. Regenerate with `cd tools/brand && python3 build.py` (needs `fonttools`, `uharfbuzz`, `cairosvg`, `pillow` and the Inter Display font).

---

Proprietary. © 2026 Team to Team Referrals. All rights reserved.
