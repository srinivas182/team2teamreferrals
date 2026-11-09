# ADR 0005: SEO and AEO built into the foundation

**Status:** accepted · Sprint 3a

**Decision.** Every public page is server-rendered and returns `SeoData` (title, description, canonical, robots, Open Graph, JSON-LD). Sitemaps are generated from providers that each domain registers; robots.txt, llms.txt and the IndexNow key file are generated, never hand-edited. When public URLs change, a queued listener refreshes sitemaps and notifies IndexNow. Non-production sites are never indexable. Private areas send `X-Robots-Tag: noindex`. Canonical redirects remove duplicate URLs.

**Consequences.** New pages are indexed correctly by default; no sprint has to remember SEO separately. Google Search Console submission and AI-drafted FAQs are added in Sprint 39.
