# Prototype screens (Sprint 2)

Live prototype: https://claude.ai/artifact/3EQAgJ7x6N2yxWVN7xTdxe (private). Open `#screens` for the full list. Every screen supports light and dark mode (system setting or the toggle) and works from 375 px wide. All names and figures are fictional sample data.

## Part 2a — public site and onboarding (approved)

| Route | Screen | Notes |
| --- | --- | --- |
| `#home` | Home | Hero shows a real referral moving St. George → Houston (signature relay animation) |
| `#how-it-works` | How it works | Six-step timeline; lead-temperature explainer |
| `#pricing` | Pricing | Member plan, additional cities, premium; accepted-referral fee by temperature (sample amounts); FAQ |
| `#groups` | Group directory | Search by city or group, filter by access, empty state |
| `#group` | Group page | Teams by state with trust scores, open cities, group rules |
| `#city` | City page (Houston) | Answer-first summary (SEO/AEO), seats, partners, FAQ, consumer request with consent |
| `#pro` | “Refer to me” profile | Verified badges, stats, reviews, QR/share |
| `#signup`, `#login` | Sign up / Log in | |
| `#blog` | Blog post | “In short” answer block, reading width, newsletter |
| `#onboarding` | Setup wizard | Profile, licenses, W-9, card, group and city, review |
| `#training` | Training video gate | No seeking ahead, captions; unlocks the dashboard |
| `#welcome` | First-run dashboard | Setup checklist with progress ring, guided empty states |

## Part 2b — team leader and group owner (approved)

| Route | Screen | Notes |
| --- | --- | --- |
| `#dashboard` | Home dashboard | KPIs with sparklines and period change; **Today view** (everything due today, soonest first, with SLA countdowns); sent vs received chart in relay colours; trust score breakdown with improvement tip |
| `#inbox` | Inbox | Summary-only referral cards: temperature, financing, timeline, fee, sender trust, **estimated referral value**, countdown; reject dialog with reasons; open request board tab |
| `#accept` | Accept and e-sign | Agreement preview, typed signature, fee disclosure; on signing the **relay animation** plays and client details unlock |
| `#send` | Send referral | **Smart city search** (seat holder, trust, response time per group as you type); temperature picker; **live referral value** as price and fee change; review with consent |
| `#pipeline` | Pipeline | Kanban by stage with drag and drop plus keyboard “move to stage”; board/list; **density toggle** |
| `#referral` | Referral detail | Stage stepper; timeline in relay colours; message thread; **shareable client status link** |
| `#sent` | Sent referrals | Filterable table; cards on mobile |
| `#earnings` | Earnings | Received, expected, due, reciprocity credits; by month and partner; confirm payment |
| `#my-groups` | Groups and network map | Map zoomed to the group's region, status filters, city panel with claim/waitlist, list alternative |
| `#billing` | Billing | Card, free-period seats, charges and invoices |
| `#owner` | Group overview | Group KPIs, activity, weekly insights, leaderboards |
| `#seat-map` | Seat map | Map and table views with seat holder, backup and waitlist |
| `#applications` | Applications | Approve to seat, as backup, waitlist, schedule call, decline |
| `#enforcement` | Members and enforcement | Breach meter per team; reactivate or remove decision with notes and seat reassignment |

## Part 2c — admin, client and system (ready for review)

| Route | Screen | Notes |
| --- | --- | --- |
| `#admin` | Network dashboard | Separate admin area (navy sidebar). KPIs, revenue by source, “Needs attention” queue, SEO/AEO health, groups table |
| `#industry-builder` | Industry builder | Industries list with Live/Draft; tabs for referral form (field builder with live preview), licensing, fees, territory unit, templates, and compliance gate that blocks going Live until a legal review is recorded |
| `#site-builder` | Website builder | Section palette, live canvas with desktop/mobile preview, properties panel (edit the hero headline and see it change), versions, draft/publish |
| `#network-crm` | Network CRM | Every referral across the network with sender (cobalt) → receiver (orange), filters, flags for SLA breaches, fee disputes and outcome mismatches |
| `#license-queue` | License verification | Queue with wait times; detail with state lookup link, checklist, expired-license warning, verify or reject |
| `#portal` | Client portal | Mobile-first page for the referred client, branded from the referring agent: agent card, contact buttons, progress, next appointment, quality check-in |
| `#request-done` | Consumer confirmation | Phone code verification, then confirmation with next steps |
| `#palette` | Command palette | Works anywhere in the portal with Ctrl/⌘ K or the search bar: actions, pages, referrals, teams; full keyboard control |
| `#notifications` | Notification centre | Bell dropdown on every portal screen plus a full page with unread filter and per-channel settings with quiet hours |
| `#gallery` | Component gallery | Buttons, inputs, choice, badges, temperature, countdowns, people and trust, KPI, feedback, loading and empty states, in light and dark |
