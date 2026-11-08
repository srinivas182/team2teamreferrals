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

Desktop uses a left sidebar; mobile uses a bottom tab bar with the four most-used items and "More". The command palette reaches everything for every role.

### Team leader (and team members, filtered by permission)

| Sidebar | Mobile tab | Contains |
| --- | --- | --- |
| Home | Home | KPIs, action bar (awaiting decision with countdowns, overdue updates, closing reports to confirm), earnings snapshot |
| Inbox | Inbox | Referrals awaiting accept/reject, open request board |
| Pipeline | Pipeline | CRM kanban and list for received leads, nurture view |
| Sent | Sent | My referrals with timelines and fee status |
| Send referral (primary button) | centre "+" button | Send flow from anywhere |
| Earnings | More | Fees expected/received/overdue, credits, year-end export |
| Groups | More | My groups, seats, backup and waitlist positions, browse and apply, network map |
| Community | More | Group feed, directory, leaderboards, events |
| Team | More | Members, permissions |
| Profile and licenses | More | Public profile, QR code, widgets, licenses, W-9 |
| Billing | More | Card, subscriptions, invoices |
| Integrations | More | CRM connectors, Zapier, calendar, intake email address |
| Help | More | Help centre, support chat, tickets |

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
