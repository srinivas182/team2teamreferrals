# ADR 0004: One shared database with row-level isolation

**Status:** accepted · Sprint 3a

**Context.** The product is a network: its value comes from teams in different groups and cities referring to each other. Isolated per-customer databases (our usual model) would break that.

**Decision.** One MySQL database. Every referral and CRM query is scoped by policy to the sender team, receiver team, the group owner (read-only) and super admin. Lead contact fields are encrypted at rest and hidden until acceptance. Automated tests assert that no team can read another team's data.

**Consequences.** Network features are simple and fast; isolation is enforced in code and tested on every pull request.
