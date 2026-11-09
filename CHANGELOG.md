# Changelog

All notable changes are recorded here. Versions follow Semantic Versioning once the application exists (from Sprint 3).

## [Unreleased]

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
