# Changelog

All notable changes are recorded here. Versions follow Semantic Versioning once the application exists (from Sprint 3).

## [Unreleased]

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
