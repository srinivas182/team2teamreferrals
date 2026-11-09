# Clickable prototype (Sprint 2)

High-fidelity, clickable prototype with sample content. Components are production-quality React + Tailwind so they move into the Laravel app (Inertia React) in Sprint 3.

```bash
npm ci
npm run dev        # local preview
npm run typecheck
npm run build      # single self-contained dist/index.html
```

- Routing is by plain `#name` hashes (e.g. `#home`, `#city`, `#welcome`); `#screens` lists every screen.
- The Tailwind theme is generated from `src/data/tokens.json` (a copy of `design/tokens.json`).
- All names, teams and figures are fictional sample data (`src/data/mock.ts`).
