# ADR 0001: Modular monolith with domain folders

**Status:** accepted · Sprint 3a

**Context.** The platform has many tightly related areas (groups, seats, referrals, billing) that share one database and change together. Separate services would add network calls, deployments and failure modes without a scaling need yet.

**Decision.** One Laravel application, organised by business domain under `app/Domains/`. Domains communicate through services and events. Architecture tests enforce boundaries.

**Consequences.** Simple deployment and transactions; clear seams if a domain (for example analytics or AI) later needs to become a separate service.
