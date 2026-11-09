# ADR 0003: Tailwind CSS v4 generated from design tokens

**Status:** accepted · Sprint 3a (theme generation lands in 3b)

**Decision.** `design/tokens.json` is the single source of truth. A build step turns it into the Tailwind v4 `@theme` and CSS variables for light and dark mode. Nobody edits colours or spacing in CSS directly.

**Consequences.** Design and code cannot drift; a brand change is a token change.
