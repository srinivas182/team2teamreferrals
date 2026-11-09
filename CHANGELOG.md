# Changelog

All notable changes are recorded here. Versions follow Semantic Versioning once the application exists (from Sprint 3).

## [Unreleased]

### Sprint 10a — Group admin, owners and scheduled changes
- Admin → Groups → a group: details (name, description, photo, draft/live/closed, open or invite-only), pricing (seat and founding price, trial, multi-city discounts, annual months free, value guarantee) and rules (accept and first-contact hours, update days, seat challenge, company cap, waitlist ranking).
- Compliance locks: the value guarantee can't be switched on in Tier C; a group can only go live once its industry is live; founding price can't exceed the seat price; bigger seat counts get equal or bigger discounts.
- Member preview, "who this change affects" (fills in once seats exist in Sprint 11) and projected revenue beside the pricing editor.
- Scheduled changes: pricing or rules can take effect on a future date; owners are told 30 days ahead (seat holders join the notice in Sprint 11); applied automatically on the day by a daily job; cancellable. Every change is audited with before and after values.
- Group owners: add by email and remove; owners get their own group overview (health, rules, upcoming changes) and can never change pricing.
- Health card (owners, seats, referrals this month, average accept time) with placeholders until seats and referrals exist.
- 196 PHP tests in total (8 new).

### Sprint 9b — Launch groups, tier counsel packs and licensing starter data
- One launch group per industry (46), with the agreed names ("Residential Agent Alliance", "Solar Installers Network"…), description, default rules (24-hour accept and first contact, 7-day updates, seat challenge after 60 days below trust 70, 25% company cap, waitlist by trust) and sample pricing ($79 seat, $59 founding, 3-month trial, 2 months free annually, multi-city discounts; value guarantee off in Tier C). Created once and never overwritten.
- Admin → Groups and seats lists every group with pricing and rules (editing, owners and seats arrive in Sprint 10). The readiness board's Group column now shows each launch group.
- Tier counsel packs: one PDF per tier (A, B, C) with every industry's agreement, plain-English summary, state addenda and messages, for review in one sitting (Tier A, 30 industries: about 3 seconds).
- Licensing starter data for real estate, commercial real estate, land, ranch and farm, property management, mortgage (NMLS), insurance and Medicare: each state's likely licensing body and a national lookup link, marked "needs review". Starter rows never count as coverage until an admin saves them; existing rows are never touched.
- Fixed in review: the tier pack used the tier inside a closure without capturing it.
- 188 PHP tests in total (7 new).

### Sprint 9a — Launch catalogue
- All 46 launch industries (30 Tier A, 12 Tier B, 4 Tier C) with names, families, tiers, territories, icons, terminology, descriptions and licensing, from `php artisan t2t:catalogue:sync` (also run by the database seeder).
- Safe to re-run: the sync remembers what it last wrote, so values an admin changed are always kept, while catalogue improvements reach values nobody touched.
- Industry-specific details on top of the family defaults: 18 industries get extra referral questions (solar power bill and roof type, mortgage loan purpose and credit range, Medicare plan interest, and more) and 9 get their own stage names (solar "Site survey", mortgage "Pre-qualified"). Every seeded form and pipeline must pass the builder's own checks, so a bad one can never be seeded.
- Launch readiness board (Admin → Industries → Launch readiness): every industry's terms, agreement approval, licensing coverage, compliance sign-off and group, with "ready to go live" and filters. The admin dashboard shows "x of 46 industries ready".
- Admin message labels now use each industry's words ("Your introduction was accepted" in Tier B).
- 181 PHP tests in total (7 new).

### Sprint 8b part 2 — Industry messages and terminology
- Terminology completed: each industry now names a referral and a fee as well as its pros and clients. Defaults follow the tier: Tier B and C industries say "introduction" instead of "referral". Live preview in the industry form.
- Every message can use the industry's own words: `{pro}`, `{pros}`, `{client_term}`, `{clients_term}`, `{referral_term}` and `{fee_term}` are filled in automatically (`{client}` stays the client's name). Platform wording now uses them, so "New referral" becomes "New introduction" in mortgage lending.
- Industry message wording: Admin → Industries → Messages lists every notification, marks the ones this industry has customised, and edits email, text and in-app wording with preview in the industry's terms, versions and restore. "Use platform wording" returns to inheriting.
- Wording resolves industry → platform → default; industry and platform versions are kept separately.
- The counsel review pack now includes every message members and clients receive, as they read it, with its source (industry, platform or default) and the terms in use.
- 174 PHP tests in total (8 new).

### Sprint 8b part 1 — Referral agreements
- Agreement templates per industry (kind "agreement" in the industry template engine), with versions and reset to the default wording.
- Defaults by tier: Tier A referral fee agreement (parties and licenses, client, fee, protection period, fee paid business to business, governing law, signatures); Tier B reciprocal introduction agreement (no fee, consent, reciprocity credits); Tier C no agreement, only client consent.
- Editor: required clauses locked; optional clause library (protection period, confidentiality, mediation, non-solicitation, cancellation) switched on or off; custom clauses; reorder; merge-field picker; live preview with sample data in the industry's own words.
- Compliance enforced on save: Tier B and C cannot contain fee clauses, fee merge fields or wording describing a referral payment; required clauses can't be removed; unknown merge fields are rejected.
- Approval gate: every saved version waits for a super admin's approval, recorded with the reviewing attorney and a note. Members only ever sign the newest approved version; drafts never reach them.
- State addenda: extra wording per state, added automatically when the receiving team is in that state.
- Plain-English summary shown to both parties before signing; if the fee wording has been edited, the summary quotes it rather than paraphrasing.
- Compare any two versions clause by clause with word-level changes highlighted.
- PDF preview (watermarked sample) and a counsel review pack PDF: summary, wording, all state addenda, version and approval history. Every save, approval, addendum and pack download is audited.
- 166 PHP tests in total (12 new).

### Sprint 8a — Referral forms and pipelines
- Default referral form and pipeline for each of the eight industry families: a shared core (client, mobile, email, timeline, notes) plus family questions (price range for property, budget and home ownership for home services, coverage needs for insurance and financial, care type for care, and more).
- Referral form builder (Admin → Industries → Referral form): questions with eight field types, required, sensitive (never on public pages), choices, and roles (estimated value, timeline); reorder, add, remove; live preview at phone or desktop size in the industry's own words.
- Pipeline editor with a board preview: open stages plus exactly one Won and one Lost stage (they drive closing reports and the trust score).
- Inheritance: industries use their family default until customised; "Use family default" resets; every save is a version. Groups can override in Sprint 10.
- Checks before saving: errors block (missing client name or mobile, duplicate keys, too few options, bad pipelines); warnings must be confirmed (no estimated-value or timeline field, contact details not marked sensitive). Legal and care forms deliberately carry no estimated value.
- Saved forms become validation rules for sending referrals (Sprint 16). Only known field properties are stored.
- 154 PHP tests in total (15 new).

### Sprint 7b — Industry engine (part 1)
- Industries: name, family (8), terminology (what a pro and a client are called), icon, description, territory unit (city, county, metro or state) and whether a license is required.
- Compliance tiers as one rule set in code (`Tier`): A open, B restricted, C directory only, deciding member referral fees, referral routing, consumer requests, the value guarantee, disclosures and whether a platform fee can ever exist.
- Compliance gate: an industry can only go live after a super admin records approval with a sign-off note. Changing the tier removes the approval, takes the industry offline and switches off any platform fee.
- Platform referral fee per industry (and later per group): none, percentage or flat, charged at acceptance or closing. Locked platform-wide until a super admin unlocks it with a record of the licenses and fund routing in place; never available for Tier B or C; locking again switches every fee off.
- Licensing per state: required or not, issuing body, license-number format (validated), public lookup page, notes. Coverage map in the builder shows configured and missing states.
- Licensing CSV export and import for counsel's state-by-state review (one industry or all); bad rows are skipped and reported.
- Territory preview: how many seats each territory choice creates nationwide before committing.
- Admin → Industries list with tier, territory, coverage and status; every change audited.
- 139 PHP tests in total (8 new).

### Sprint 7a — Locations
- US locations loaded: 51 states and DC (Puerto Rico loaded but switched off), 3,220 counties with FIPS codes, 29,738 cities and towns with county, coordinates and population. Sources and licences in `database/data/us/SOURCES.md` (Census via us-atlas, kelvins/US-Cities-Database, GeoNames population with attribution).
- `php artisan t2t:locations:import` is safe to re-run: names, coordinates and population refresh, while admin decisions (switched off, corrected county, aliases) are kept. Runs in the database seeder.
- `php artisan t2t:locations:import-metros` loads Census metro and micro areas (CBSAs) and their counties from the official delineation file.
- Fast type-ahead (`/locations/cities`): prefix match on names and aliases, largest places first; "St. George", "Saint George", "Ft Worth" and "Fort Worth" all match. Switched-off places never appear.
- Admin → Locations: switch states and cities on or off for seats, correct a city's county, add aliases ("H-Town"), merge duplicates (the duplicate's name keeps working as an alias); every change audited.
- Reusable, accessible `CityPicker` (combobox with arrow keys).
- CI: Lighthouse now runs only when public-facing code changes, saving Actions minutes on back-end work.
- 131 PHP tests in total (10 new), including a full import of the real dataset.

### Sprint 6b — Search, CDN, load testing and performance budgets
- Search in the command palette (Ctrl/⌘ K, portal and admin) as you type: settings and admin screens by keyword, and people by name or email for staff who manage them. Every source checks permissions; members never see admin results. Meilisearch in Docker and production (typo-tolerant), database search in tests; search health on `/health` and `/status`.
- Domains register their own search sources, so teams, cities and referrals plug in with their sprints.
- CDN: public pages are cacheable at the edge for signed-out visitors (5 minutes, served stale while refreshing) with no cookies; signed-in pages are never cached. Admin → System → CDN connects Cloudflare, and changed public pages are purged automatically.
- k6 load tests (`loadtest/`): public visitors (1,000 users by default) and signed-in members (300), with pass criteria. Smoke run on a 1-core sandbox: 7,575 requests from 200 users, 0 errors, median 220 ms.
- Lighthouse budgets in CI on home, login and status: performance 90+, accessibility 95+, best practices 90+, key SEO checks, server response under 600 ms. Locally: performance 96–97, accessibility 96–100, best practices 100.
- Fixed in review: session cookies were still set on cacheable pages (added after route middleware), which would stop a CDN caching them; edge caching now runs outermost.
- 121 PHP tests in total (6 new).

### Sprint 6a — Runtime and observability
- Laravel Octane on FrankenPHP (worker mode keeps the app in memory). Production image: multi-stage, non-root, opcache tuned, health-checked; separate targets for the app and server-side rendering; `compose.prod.yaml` runs web, Horizon, scheduler, SSR, MySQL and Redis. CI builds the production image whenever Docker files change.
- Octane safety: the settings store is now request-scoped, so one request's settings can never leak into the next (verified under Octane workers: maintenance on and off took effect on the very next request).
- Redis queues with Horizon at `/horizon`, for super admins with two-step sign-in only; long queue waits email the support address.
- `/health` for uptime monitors (database, cache, queue, storage; 200 or 503) and a public `/status` page with an optional incident message.
- Request IDs on every response (`X-Request-Id`, valid incoming IDs kept), added to every log line and error report, and shown on error pages as a reference for support.
- Error tracking with Sentry (set `SENTRY_LARAVEL_DSN`): no personal data, the member identified only by their public ID.
- Structured JSON logs for production and a slow-query log (over 500 ms, configurable).
- N+1 watchdog: key pages have query budgets in the test suite.
- Fixed in review: the super-admin shortcut could open the queue dashboard without two-step sign-in.
- 115 PHP tests in total (9 new).

### Sprint 5c — Payments, storage, AI, modules and launch tools
- Admin → System → Overview: one card per provider (email, text, payments, storage, AI) with its last check and live health; **Test everything** checks all providers in one click and remembers the results.
- Payments: Stripe keys and webhook signing secret (encrypted), "Test connection" shows the account name and test/live mode; signed webhook endpoint `/webhooks/stripe` stores each event once (retries are harmless) for billing in Sprint 22.
- Storage: this server, or any S3-compatible service (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2). Private files open only through signed links that expire; the storage test writes, reads and deletes a file.
- AI: Anthropic provider with model, prices per million tokens and a monthly spend cap; usage metered per month; "Test connection" shows the reply, tokens and cost.
- Modules: switch push, consumer requests, pre-launch reservations, community, events, cross-industry introductions, partner directory and AI on or off without a deploy; switched-off modules are hidden and their routes return 404.
- Maintenance mode: members see the branded "We'll be right back" page; staff can still sign in and webhooks keep arriving.
- Settings export and import: non-secret settings only, to copy staging to production; imports ignore unknown keys and never touch secrets.
- 106 PHP tests in total (9 new).

### Sprint 5b — Notification framework
- Notification catalogue: 11 types in four groups (referrals, network, billing, account and security), each with default channels (email, text, in-app, push), urgency and whether members can turn it off. One `Notifier` sends everything.
- Member settings: channel grid per type ("Urgent" and "Always on" labelled), quiet hours in the member's own time zone (urgent items still come through), and an optional daily summary at 7:00.
- Smart bundling: non-urgent emails that arrive within 10 minutes are sent as one ("3 updates on your referrals"); held items go out when quiet hours end.
- "Why am I getting this?" link in every email, opening that notification's own setting.
- Notification centre: real bell with unread count, latest five, mark read and mark all read; full page with All/Unread.
- Browser push (standard Web Push with VAPID keys, no third-party service), service worker, expired subscriptions removed automatically.
- Texting compliance: STOP-type replies opt a number out of every text (logged as "suppressed" in the delivery log), START opts back in, HELP gets an answer; inbound webhook verified with Twilio's signature.
- Admin → Templates: edit email, text and in-app wording with merge fields and live preview; every save is a new version; restore any version or reset to default; text length limit; all audited.
- Seeded wording for trial ending and "free month credited" for billing in Sprint 22.
- Fixed in review: restoring a template version could leave no version active; now written directly to the database.
- 97 PHP tests in total (16 new).

### Sprint 2c part 3 — Admin pricing, pre-launch and brand kit (prototype)
- Admin → Groups and pricing: seat price, founding price, trial length, multi-city discounts, annual months free, value guarantee (forced off in Tier C), company cap, seat-challenge rules, waitlist ranking, locked platform-fee mode, member preview and projected revenue.
- Admin → Overflow demand: waitlisted teams and metro fill per network, "ready for a second group" flags, and "Open next group" (region, name, inherits rules, offers seats to waitlisted teams).
- Pre-launch "Reserve your city": launch countdown, two-minute reservation, founding price and badge, invite-to-move-up, share card on success, live map and state race.
- Brand kit: eight custom family emblems, all 46 industry tiles, share-card generator (fits long city names), motion principles, photography and illustration direction; written guide in docs/design/brand-kit.md.
- All screens checked at 375 px with no horizontal scroll.

### Sprint 2c part 2 — Member screens (prototype)
- Network page (Solar Installers Network): stats, search, list or map, "Send referral" on every installer, reserved and open cities, network rules (deadlines, seat challenge, company cap) and top installers.
- Team profile: verified-license and founding-member badges, client and peer ratings, response time, update rate, 6-month report card, verified-only reviews, "Refer to me" code.
- Claim flow: "My team" or "A company with several cities"; industry and cities with held/reserved/open status; per-seat payer and city-leader invite for companies; license check; confirmation with trial end dates and founding price.
- Company dashboard: seats by city with city leader, payer, trial end, referrals and "quiet month" status; state caps; private-network offer.
- Billing: seats with trial dates and free-month credits, next invoice, annual switch, invoices.
- All five screens checked at 375 px with no horizontal scroll.

### Sprint 2c part 1 — Multi-industry public design (prototype)
- Industry catalogue for the 46 launch industries: families, compliance tiers, territories and terminology (agent, installer, advisor…).
- Brand kit start: industry icon tiles with family accents and the t2t relay corner (placeholder for the custom icon family).
- National map: every metro's seat (claimed, reserved by a founding member, open) per industry, filling in on load.
- New Home: industry picker with search, live counters, map with industry switcher and most-claimed states, the 46 industries by family, a no-signup "send a referral" demo, value guarantee and "seats are earned".
- Pricing: per-seat pricing, 3-month trial, "no referrals, no charge", multi-city and annual savings, company plan and a live calculator.
- Industry hub, industry × state (Solar in Texas) and city page (Solar in Austin) with seat holder, ratings, city report card and request form.
- Checked at 375 px (no horizontal scroll) and desktop, light and dark. Published to the prototype link.

### Sprint 5a — Settings, providers and delivery log
- Platform settings store: admin-edited, cached, secrets encrypted at rest and shown masked (last four characters only); blank secret fields keep the saved value.
- Admin → System: General (company and support details), Email (log, SMTP, Amazon SES, Postmark, Mailgun; from name and address) and Text messages (log, Twilio number or Messaging Service). Changes apply on the next request, with no deploy, and are audited without revealing secrets.
- "Send test" for email and SMS showing the provider's own error when one is refused.
- Delivery log: every email and text with provider, status and error; Twilio delivery receipts update status via signed webhooks (X-Twilio-Signature verified).
- Live provider health (healthy, some failures, failing, idle) from the last hour of sends, on the System screen and the delivery log.
- Phone verification: "Text not arriving? Email me the code instead" appears after 30 seconds.
- Settings fall back to environment defaults when no database is reachable (CI install step, fresh installs).
- 82 PHP tests in total (12 new).

### Sprint 4b — Roles, staff and audit log
- Roles (super admin, staff, member) and an admin permission catalogue of 17 permissions in five areas, kept in sync on every deploy; super admins pass every check; new members get the member role.
- Admin area at `/admin` for staff and super admins only, and only with two-step sign-in on (staff without it are sent to set it up). Navigation shows only built screens each person may open.
- Staff management: invite by email with chosen permissions (set-password link, email verified on first password), edit permissions, deactivate and reactivate; nobody can change their own access, and only super admins can change super admins.
- `php artisan t2t:super-admin email` bootstraps the first super admin.
- Audit log (7-year retention): sign-ins, failed attempts, lockouts, password resets, email verification, two-step and passkey changes, account changes with before and after values (never secrets), and admin actions, each with IP and device. Filter by person, type and dates; CSV export (itself audited).
- New-device alerts by email with a “This wasn’t me” link (signed, 7 days) that ends every session, removes devices and passkeys, and sends a password reset.
- Admin dashboard with member, staff and sign-in counts.
- 70 PHP tests in total.

### Sprint 4a — Accounts and sign-in
- Sign-up with first and last name, work email, US mobile (normalised to E.164) and a 12+ character password checked against known breaches; terms and SMS consent.
- Sign-in: password with lockout after 5 failures, passkeys (Face ID, Touch ID, Windows Hello, security keys; autofill-ready), and one-tap email sign-in links (single-use, 15 minutes).
- Email verification; mobile verification by 6-digit SMS code (hashed, 10-minute expiry, 5 attempts, 3 sends per 10 minutes, change number). SMS goes through an `SmsSender` interface (log driver now, Twilio in Sprint 5).
- Two-step sign-in with an authenticator app: QR setup, confirmation, recovery codes, and “don’t ask again on this device for 30 days”.
- Security settings (behind password confirmation): passkeys, two-step sign-in, password change, signed-in devices with remove and “sign out of all other devices”.
- Protected portal at `/app` (verified email and mobile, active account) with a first-run setup checklist.
- Users keyed by ULID publicly; Identity domain (`app/Domains/Identity`); ADR 0006.
- 52 PHP tests (registration, lockout, phone codes, sign-in links, two-step and trusted devices, device isolation, passkey options, noindex) and 10 component tests.

### Sprint 3b — Design system in the app
- Tailwind v4 theme generated from `design/tokens.json` (`npm run tokens`); light, dark and match-device modes with no flash on load; CI fails if the theme drifts from the tokens.
- Components on the generated theme: Logo, Button (variants, sizes, loading, asChild), Card, Field with accessible label/hint/error wiring, Input, Select, Textarea, Badge, TempBadge, Avatar, TrustRing, Kpi, Countdown, Segmented (arrow-key radio group).
- Primitives on Radix, restyled with tokens: Dialog (bottom sheet on phones), Popover, Dropdown menu, Tooltip, Toasts (Sonner), Command palette (cmdk).
- Layouts: public site (skip link, responsive nav, footer), portal (sidebar, owner section, Ctrl/⌘ K palette, notifications, theme menu, mobile bottom tabs), admin (distinct navy sidebar).
- Branded, noindex error pages for 403, 404, 419, 500 and 503.
- Home page rebuilt on the public layout (full design arrives in Sprint 36).
- Storybook 10 with light/dark switcher and accessibility addon (`npm run storybook`).
- Component tests (Vitest, Testing Library) with axe accessibility checks on every component; run in CI quick checks.

### Sprint 3a — Application foundation
- Laravel 13.40 on PHP 8.4 with React 19, Inertia 3 server-side rendering, TypeScript (strict) and Tailwind CSS v4; self-hosted variable Inter with preload and metric-matched fallbacks.
- SEO/AEO foundation (`app/Domains/Seo`): `SeoData` rendered server-side into title, description, canonical, robots, Open Graph and JSON-LD; schema builders (Organization, WebSite, BreadcrumbList, FAQPage, Article); generated robots.txt with AI-crawler rules, sitemap index and per-domain sitemaps, llms.txt, IndexNow key file and background IndexNow notifications on URL changes; canonical-URL redirects; noindex for private areas and all non-production environments.
- Security baseline: nonce-based Content Security Policy, HSTS in production, frame, content-type, referrer and permissions headers; `/up` health check; strict Eloquent mode outside production.
- Modular monolith structure (`app/Domains`) with architecture tests; ADRs 0001–0005.
- Docker Compose development environment (PHP 8.4, MySQL 8.4, Redis, Mailpit, Vite).
- Quality: Pint, Larastan level 8, Pest (20 tests), ESLint, Prettier, TypeScript; pre-commit hook; GitHub Actions per the agreed minutes rules; weekly grouped Dependabot updates.
- Laravel Pennant (feature flags), Sentry hook (enabled by DSN), Telescope and Debugbar registered in local only.

### Sprint 3a — Application foundation
- Laravel 13.40 on PHP 8.4 with React 19, Inertia 3 server-side rendering, TypeScript (strict) and Tailwind CSS v4; self-hosted variable Inter with preload and metric-matched fallbacks.
- SEO/AEO foundation (`app/Domains/Seo`): `SeoData` rendered server-side into title, description, canonical, robots, Open Graph and JSON-LD; schema builders (Organization, WebSite, BreadcrumbList, FAQPage, Article); generated robots.txt with AI-crawler rules, sitemap index and per-domain sitemaps, llms.txt, IndexNow key file and background IndexNow notifications on URL changes; canonical-URL redirects; noindex for private areas and all non-production environments.
- Security baseline: nonce-based Content Security Policy, HSTS in production, frame, content-type, referrer and permissions headers; `/up` health check; strict Eloquent mode outside production.
- Modular monolith structure (`app/Domains`) with architecture tests; ADRs 0001–0005.
- Docker Compose development environment (PHP 8.4, MySQL 8.4, Redis, Mailpit, Vite).
- Quality: Pint, Larastan level 8, Pest (20 tests), ESLint, Prettier, TypeScript; pre-commit hook; GitHub Actions per the agreed minutes rules; weekly grouped Dependabot updates.
- Laravel Pennant (feature flags), Sentry hook (enabled by DSN), Telescope and Debugbar registered in local only.

### Sprint 1 — Brand, design system and user flows
- Brand identity: t2t lettermark (the "2" is an arrow from Relay Cobalt to Handoff Orange), horizontal, stacked, mono and on-dark logos with outlined lettering.
- Icons: app icon, PWA and maskable icons, Apple touch icon, favicon set (SVG, ICO, PNG).
- Social: link-preview image (1200 × 630) and profile avatar.
- Brand guide: naming, logo rules, colour with contrast checks, the relay rule (sender cobalt, receiver orange), lead-temperature colours, typography, imagery, voice and tone.
- Design tokens (`design/tokens.json`): colour scales, light and dark themes, type, spacing, radius, shadow, motion, breakpoints, layout.
- Component inventory (42 components plus website-builder blocks), information architecture for the public site and every role, 10 core user flows, accessibility and motion principles.
- Repo foundation: README, CONTRIBUTING, CHANGELOG, editor and ignore settings, reproducible brand build script.
