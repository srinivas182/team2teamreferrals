# Domains

The application is a modular monolith. Each folder is one business area with its own models, services, events, HTTP layer and tests. Domains talk to each other through services and events, never by reaching into another domain's internals.

| Domain | Owns | Arrives in |
| --- | --- | --- |
| `Seo` | Page metadata, structured data, sitemaps, robots.txt, llms.txt, IndexNow, canonical URLs | Sprint 3a |
| `Identity` | Users, authentication, 2FA, roles, permissions, audit log | Sprint 4 |
| `Network` | Industries, locations, groups, seats, waitlists, memberships, licensing | Sprints 7–11 |
| `Referrals` | Referrals, agreements, consent, CRM, SLA engine, closings | Sprints 13–20 |
| `Billing` | Cards, accepted-referral fees, subscriptions, invoices | Sprint 19 |
| `Content` | Website builder, blog, help centre | Sprints 34–35 |

Every domain that owns public pages registers a `SitemapProvider` and returns `SeoData` from its controllers, so new pages are indexed automatically.
