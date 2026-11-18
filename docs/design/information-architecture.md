# Information architecture

## Public site (teamtoteamreferrals.com)

```
/                                   Home
/how-it-works                       How it works
/pricing                            Pricing
/groups                             Group directory (filter: industry, country, state, city)
/groups/{group-slug}                Group page (teams by city, open cities, claim)
/{industry}                         Industry page, e.g. /real-estate
/{industry}/{state}                 State page, e.g. /real-estate/texas
/{industry}/{state}/{city}          City page, e.g. /real-estate/texas/houston
                                    (answer block, seat holders, open seats, consumer request form, partners, FAQ)
/find-a-pro                         Consumer portal entry: search by industry and city
/pros/{team-slug}                   "Refer to me" public team profile
/partners                           Partner directory
/partners/{partner-slug}            Partner listing
/blog, /blog/{post-slug}            Blog
/help, /help/{article-slug}         Help centre
/changelog                          Public changelog
/roadmap                            Feature-request board
/about, /contact, /faq              Company pages
/legal/terms, /legal/privacy, /legal/sms-terms, /legal/cookies
/login, /signup, /forgot-password
/c/{token}                          Client portal for a referred lead (unguessable link)
/sitemap.xml, /robots.txt, /llms.txt
```

## Portal navigation by role

Simplified before launch: five everyday places, then everything else grouped under "More", closed until opened
(`resources/js/layouts/nav.ts`). Desktop uses a left sidebar; phones use a bottom tab bar (Home, Inbox, Send, Pipeline,
More), where "More" opens a sheet with the same groups. Notifications are the bell at the top. Search (Ctrl K) reaches
every page for every role.

### Team leader and team members

| Sidebar | Phone | Contains |
| --- | --- | --- |
| Send referral (primary button) | centre "+" | Send flow from anywhere |
| Home | Home | Checklist, KPIs, what needs you today |
| Inbox | Inbox | **One inbox**: referrals sent to the team, plus new leads from the public profile, drafts from forwarded emails, voice notes and photos, and open requests the team can take (filters: All, Referrals, Profile leads, Drafts, Open requests). The badge counts unread referral messages, new leads and drafts. |
| Pipeline | Pipeline | CRM kanban and list for received leads |
| Sent | More → Everyday | My referrals with timelines and fee status |
| Earnings | More → Everyday | Fees expected, received and overdue; year-end export |
| More → Network | More → Network | Groups, Directory, Feed, Events, Leaderboards, Badges, Local partners, Training (each hidden when its module is off) |
| More → Grow your business | More → Grow your business | Dashboard, Performance, Share profile, Invites, Nurture, Credits |
| More → Team and account | More → Team and account | Team, Profile and licenses, Notifications; **leaders only**: Company, Billing, Integrations, Developers (API) |
| More → Help | More → Help | Help centre, support, tickets |

Open requests, Profile leads and Drafts keep their own pages (linked from the inbox and search) but are no longer
separate menu items.

### Group owner (adds a "My group" section)

Overview (group KPIs, insights) · Seat map · Applications · Members and enforcement · Group CRM (view only) · Rules and stages · Training · Announcements and events · Settings.

### Super admin and staff (separate admin area, permission-filtered)

| Section | Contains |
| --- | --- |
| Dashboard | Network KPIs, revenue, SLA trends, SEO/AEO health |
| Network | Industries (engine, templates, compliance), locations, groups, seats, members, license queue |
| Referrals | Network CRM, disputes, outcome-check flags |
| Revenue | Plans, fees by temperature, subscriptions, invoices, coupons, partner listings |
| Growth | Prospect CRM, campaigns, invites and rewards |
| Content | Website builder, pages, blog, templates (email, SMS, agreement), help centre, changelog |
| Training | Videos, quizzes, AI video scripts, completion reports |
| AI | Providers, prompts, credits, usage |
| Support | Tickets, feature requests |
| System | Staff and permissions, settings, providers, modules, API tokens, webhooks, audit log |

### Referred lead (client portal) and consumer

Single page per referral: who their new agent is, contact buttons, next steps, consent and outcome check-ins. Consumers see the public site and the request form; after submitting they receive a client-portal link.

### Partner

Listing editor, plan and billing, stats (views, clicks, enquiries).
