# ADR 0006: Authentication with Fortify and first-party passkeys

**Status:** accepted · Sprint 4a

**Decision.** Laravel Fortify provides the sign-in back end (password, registration, reset, email verification, TOTP two-step sign-in, password confirmation) and Laravel's first-party passkeys (`laravel/passkeys`, wired through Fortify, with the `@laravel/passkeys` React hooks). All screens are our own React pages. We add: mobile verification by SMS code, one-tap email sign-in links, a device registry with "sign out other devices", and "remember this device for 30 days" for two-step sign-in.

**Alternatives.** Laragear WebAuthn (mature, but a second integration path beside Fortify); Laravel Breeze/starter kits (opinionated UI we would replace anyway).

**Risks.** `laravel/passkeys` is pre-1.0 (v0.2). It is used only behind Fortify's routes and our Login and Security pages, so an API change touches two components. Pinned by Composer and covered by tests.

**Security notes.** Passwords: 12+ characters, breached-password check (k-anonymity). Rate limits on login, two-step codes, passkeys, SMS codes (3 per 10 minutes) and sign-in links (3 per 10 minutes). SMS codes are stored hashed, expire in 10 minutes and burn after 5 wrong tries. Sign-in links are signed, single-use and expire in 15 minutes; members with two-step sign-in still pass that step. Responses never reveal whether an email has an account. All auth pages are noindex.
