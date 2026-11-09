# ADR 0002: React with Inertia and server-side rendering

**Status:** accepted · Sprint 3a

**Context.** We want a React front end with app-like navigation, but every public page must be fully indexable by search engines and AI answer engines.

**Decision.** Laravel 13 + Inertia (v3) + React 19 + TypeScript, with Inertia SSR. Pages render on the server (title, meta, JSON-LD and content in the first HTML response) and hydrate in the browser. No separate API layer is needed for the web app; a public API is added in Sprint 40.

**Alternatives.** A separate Next.js front end (two deployments, duplicated auth, more moving parts); Blade-only (weaker app experience).

**Consequences.** One codebase, one deployment, SEO-safe React. An SSR process runs alongside the app in production.
