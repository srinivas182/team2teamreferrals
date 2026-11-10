# Changelog

All notable changes are recorded here. Versions follow Semantic Versioning once the application exists (from Sprint 3).

## [Unreleased]

### Sprint 17b — Pipeline board and owners' pipeline editor
- Pipeline board (Portal → Pipeline): the team's accepted referrals in stage columns, one network at a time, with counts and "needs attention" flags (call due, first contact overdue, update due, over time in stage) and a "Needs attention only" filter. Drag and drop between columns on desktop; on a phone, swipe between stages (one column fills the screen). Every card also has a "Move to…" menu; Won and Lost open the closing prompt (date and value, or reason).
- Group owners edit their network's pipeline (group page → Pipeline stages): rename, add, reorder and remove stages, set what the client sees and an optional time limit per stage, or start again from the industry's stages. The editor keeps the rules (first stage open, one Won, one Lost).
- Safe for work in progress: a stage that still holds referrals can only be removed once the owner chooses where those referrals go; they are moved with a timeline entry. Every save is a new version, audited, and seat holders are told.
- Admins can lock a network's pipeline (owners then can't change it; admins still can).
- 306 PHP tests in total (4 new).

### Sprint 17a — Working the referral: stages, calls, deadlines and nudges
- Accepted referrals move through the group's (or industry's) pipeline. A Progress panel shows the stage path; the receiving team moves it on, posts progress notes, and the sender is told each time ("Hurricane Sun: Tom Brennan is now at “Site survey”"). Stages can carry a client-friendly label and an optional time limit (editor in 17b).
- "Log a call": one tap records first contact, stops the first-contact clock, and moves a brand-new referral to the first working stage.
- Stage prompts: Won asks for the closing date and value (kept for closing and fees in Sprint 20); Lost asks for a reason the sender sees.
- Deadlines (every 15 minutes, each reminder once): a reminder 2 hours before first contact is due and a recorded miss after it; a gentle "No update for 6 days" a day before the update deadline and a recorded miss after it; a nudge when a referral sits in a stage past its time limit. Misses are on the timeline for the trust score.
- Sender's "Nudge for an update" button, at most every 3 days, shows when the next nudge is possible.
- The client status page shows the current stage in plain words.
- Fixed in review: timeline entries showed raw codes ("call_logged", and the consent and signing events from 16b); now "Logged a call", "Moved to Contacted", "Client confirmed", "Signed the agreement", with notes shown.
- 302 PHP tests in total (7 new).

### Sprint 16b part 2b — Client consent by text and group message wording
- Consent by text: senders can tick "Also text my client to confirm". The client gets one text naming the referring team, the kind of pro and the city, with a confirm link and STOP wording; they confirm by tapping (a confirm button, so link previews can't confirm for them) or by replying YES. The referral is then marked "Client confirmed" (consent method changes from sender-attested), the client is texted their status-page link, and the sender is told. Senders can also ask later from the referral page.
- Safeguards: client texting is off per industry until an admin switches it on with a note recording who approved it (audited); one consent text per referral; opted-out numbers are never texted; YES replies are matched only to requests from the last 7 days and only through the signed Twilio webhook.
- Group message wording: group owners reword the referral messages for their network (new referral, accepted, declined, messages, agreements) for email, in-app and push, plus a separate text version, falling back to the industry's wording. Placeholders the message can't fill are refused with a list of the ones it can.
- Fixed in review: a group owner's wording reached email but not in-app or push.
- 295 PHP tests in total (5 new).

### Sprint 16b part 2a — Messages, one-tap accept and the client status page
- Messages on every referral, built into the platform (no third-party chat): only the two teams can read or post; new messages appear every 10 seconds while the referral is open; the other team is notified by email, push and in-app with an excerpt, respecting their preferences and quiet hours. Messages are part of the referral's record.
- Privacy: phone numbers and emails in messages are masked for the receiver until they accept ("[phone shown after accepting]"); notifications carry the masked excerpt and the client's short name. Dates, budgets, reference numbers and ZIP codes are left alone.
- One-tap accept: the "new referral" email or text links to a signed confirm screen (valid until the deadline, only for the current receiver). It never accepts on open, so email link scanners can't accept by accident; it can't be used twice, and an old link stops working once the referral moves to the backup.
- Client status page: a private link (random token, never indexed) the sender can copy and send, showing who introduced the client, who will contact them with their verified-license and "Network trained" badges, and the expected first-contact time. Sent automatically once the client confirms consent by text (part 2b).
- 290 PHP tests in total (4 new).

### Sprint 16b part 1 — Agreements signed in the app
- When a referral is accepted (Tier A and B), the counsel-approved agreement for the group or industry is generated with both teams, their leaders, verified license numbers, the client, the city and the receiver's state addendum. Its text is fingerprinted (SHA-256) so any later change is refused at completion.
- Built-in e-signature: each team's leader reviews a plain-English summary and the full text, agrees to sign electronically and types their full name; time, IP address and device are recorded. When both have signed, a PDF with a signature certificate is stored privately and both sides are told; only the two teams can download it.
- If no agreement version has been approved by counsel yet, the referral goes ahead and the agreement waits, with a clear note on the referral.
- A reminder to the side that hasn't signed after 24 hours (once). New notification type "Referral agreements".
- Inbox lists waiting referrals first, soonest deadline on top, with under-2-hours highlighted.
- Fixed in review: directory (Tier C) networks could route referrals between members (they now never do, and don't appear on Send); two notifications would have shown unfilled placeholders such as "{team}" (now covered by a test over the whole referral flow); license text was joined with SQL "||", which means OR on MySQL.
- 286 PHP tests in total (7 new).

### Sprint 16a — Sending and accepting referrals
- Send a referral (Portal → Send referral): pick the network and the city where the client needs help; a routing preview shows who will receive it (holder or backup), their average time to accept, and the backup. The industry's (or group's) referral form is shown field by field; required answers are checked; the client's consent is recorded with its exact wording and time.
- Only teams approved into the group can send, and the training gate applies to senders. Sending to your own territory is refused. A duplicate-client guard warns when the same client (phone or email) was referred in the network in the last 90 days.
- Routing: the seat holder receives it, or the backup when the holder is inactive or hasn't finished required training.
- Accept or decline within the group's deadline (24 hours by default), with a reason for declines. A decline or a missed deadline passes it to the backup automatically (checked every 15 minutes); if no backup is available, it closes and the sender is told why.
- Privacy: until they accept, the receiver sees the client as "Tom B." (title, inbox, notifications) and contact details stay hidden; outsiders can't see a referral at all.
- Inbox and Sent with live countdowns; referral detail with the answers, notes, consent record and an activity timeline (sent, declined, passed to the backup, accepted, deadline missed). Notifications use each industry's words.
- First-time senders are offered the one-minute refresher.
- Found in review: the routing preview used a SQLite-only date function that would have failed on MySQL in production; the client's full name showed in the receiver's title and inbox before acceptance.
- 279 PHP tests in total (8 new).

### Sprint 15b part 2 — Answer-engine optimisation (AEO) pass
- One source of truth for answers (`Aeo`): six key facts and seven questions (how it works, price, value guarantee, taken cities, referral fees by industry, verification, client privacy), used for visible FAQ sections, FAQPage structured data and llms.txt so they always agree.
- llms.txt rewritten for the 46-industry platform (the old summary still described a broker-only product) with Key facts and Answers sections and only public URLs.
- Home and reserve pages show the FAQ and carry FAQPage, Organization, WebSite and BreadcrumbList structured data; share pages get breadcrumbs, canonical URLs and the personal card image.
- Sitemap: the reserve page and founding members' share pages, only for members who agreed to be shown.
- Automated AEO check in the test suite: every public page needs a real description, a canonical URL and structured data; the visible FAQ must match the FAQ structured data; llms.txt must list only public pages; private pages must be noindex.
- Found in review: breadcrumbs were passed in the wrong shape (they would have produced broken structured data), and the llms.txt summary showed a stray backslash before "$10".
- 271 PHP tests in total (6 new).

### Sprint 15b part 1 — Training editor, team view, badge, certificate, refresher and reminders
- Admin → Industries → Training: edit lessons (text, minutes, video link, transcript) and quiz questions (options, right answer, explanation), pass mark, and "material change: ask everyone to re-train". Checks: every question needs an explanation, the right answer must be an option, and every video needs a transcript. Each save is a new version; completion per group (seat holders passed) shown on the same page.
- Team page: each member's training status, a one-tap "Remind" for leaders, and a "Network trained" badge once everyone has passed. Profile strength gains "Everyone on the team passes the network training" (+10; logo and teammate rebalanced so the total stays 100).
- Completion certificate (PDF, landscape) for members who passed, to show their brokerage or carrier.
- One-minute refresher (the industry's consent, deadline, update and fee rules in its own words), recorded when seen; it opens before a member's first referral in Sprint 16.
- Smart reminders 3 and 7 days after a member's first application if training is unfinished (once each, non-urgent so they respect quiet hours, stopped once passed).
- 265 PHP tests in total (4 new).

### Sprint 15a — Network training and the training gate
- Training per industry, written in its own words: five bite-size lessons (how the network works, consent, deadlines, agreements and money, keeping your seat) and a five-question quiz. Tier B lessons and questions cover the no-fee rule; video and transcript slots are ready.
- Members (Portal → Training): progress saves per lesson; the quiz needs 80%; a wrong answer explains why; retakes ask only the missed questions. Answers never reach the browser.
- The training gate: when a group requires training, members who haven't passed can't send or receive new referrals once the grace period (default 14 days) ends. Work in progress always continues, and new referrals go to the backup team meanwhile.
- Group owners switch it on or off from their group overview; switching on starts a new grace period and tells every seat holder. The platform locks it on for Tier B industries (owners can't switch it off there).
- Material changes: raising a training's minimum version asks members to re-train.
- "Complete your network training" joins the onboarding checklist; Training added to the portal menu.
- Fixed in review: on a fresh install, Tier B industries were created after the migration that locks their training, so they weren't locked; the catalogue now locks them on creation.
- 261 PHP tests in total (6 new).

### Sprint 14c — Branded share images on the server
- "We claimed Houston" cards are now rendered on the server with librsvg and the Inter brand font, in link-preview (1200×630), square and story sizes, from one design, so social previews, downloads and the share page always match. The headline auto-fits long place names.
- Rendered once in the background when a reservation is confirmed and stored on the public disk (CDN-ready, cached for a year); rendered on demand if missing; the brand image is the fallback, so a shared link never looks broken.
- Link previews on LinkedIn, Facebook, X, WhatsApp and iMessage show the member's own card (Open Graph image with dimensions, large Twitter card).
- Thank-you email after confirming: the share link, a ready-to-post caption and the card attached for forwarding to clients.
- "Post on LinkedIn" copies a pre-written caption (LinkedIn doesn't accept pre-filled text) and opens LinkedIn's share dialog.
- Production image and CI include librsvg and the Inter font; CI renders real cards in the tests.
- Found in review: ImageMagick's built-in SVG renderer dropped gradients, transparency and the arc, so rendering uses librsvg directly; the preview image URL was doubled (http://…http://…), which networks would have rejected.
- 255 PHP tests in total (4 new).

### Sprint 14b — Live map, share cards and invite-to-move-up
- Live reservations map on `/reserve` (switch by industry) and a state race leaderboard of the busiest states.
- Share cards: "We claimed Houston" in link-preview (1200×630), square (1080×1080) and story (1080×1920) sizes, drawn in the browser and downloaded as PNG; LinkedIn, Facebook and X share links and a copyable link on the confirmation page. (Social networks don't accept SVG link previews, so shared links use a custom title and description with the brand image.)
- Each founding member's share page (`/reserve/c/…`) previews their reservation and doubles as their invite link: reservations made through it are credited to them.
- Move up by inviting: every invited pro who claims a reservation and gets a license verified adds to the inviter's waitlist ranking (up to +20); rankings refresh automatically when the license is verified.
- Fixed in review: switched-off states or cities (e.g. Puerto Rico at launch) could still take reservations and seats; every path (apply, invite, reserve) now refuses them. Also guarded the confirmation page for server-side rendering.
- 251 PHP tests in total (5 new).

### Sprint 14a — Reserve your city
- Public "Reserve your city" page (`/reserve`): launch countdown (Settings `prelaunch.launch_at`), live counters (reservations, cities, states), founding member wall, and a two-minute reservation with no card: industry, city (with instant availability), name, business and email. A honeypot stops bots.
- Double opt-in: the reservation counts once the email link is clicked; then the pro creates an account or signs in (brought straight back) and claims it, which files a founding application in the industry's launch group and renames their team to the business name. The license is collected and verified before launch.
- A confirmed reservation holds its territory; "Reserve anyway" lets later pros back it up or join the waitlist. The wall shows business names or first names only, and only for people who agreed.
- Everything sits behind the pre-launch module switch; while it's on, "Claim your city" in the site header goes to the reservation page.
- Also: a test for refusing seat offers that would take a company over its cap (owed from Sprint 13); visual check of the reserve page and company dashboard.
- Fixed in review: a confirmed reservation didn't make the city show as taken (the seat only exists once claimed).
- 246 PHP tests in total (5 new).

### Sprint 13 — Companies
- Companies (Portal → Company): create one, and your team becomes its first branch. The company admin sees every branch and city leader, every seat (group, branch, trial end, status), branches waiting for seats, and the company's private network.
- Who pays: per seat, the company centrally or the city team, switchable by the company admin only (billing uses it in Sprint 22). Payment problems on company-paid seats also notify the company admin.
- Add branches in bulk: invite city leaders by email (14 days), optionally offering each a seat in a group and city at the same time. They join from the invitation page with the invited email only.
- Company cap enforced: approving, accepting an invitation or claiming an offer can't take a company over its share of a state's seats in a group (default 25%); the team becomes backup or waitlisted instead. A company can always hold at least one seat per state.
- Cap warning in the applications queue ("would hold 3 of 9 seats in this state, 33%, limit 25%"), company badge on branch profiles ("Part of SunPath Solar, 7 cities"), and private networks linked to the owning company.
- Fixed in review: in small states (few counties or cities) the cap blocked a company's very first seat.
- 241 PHP tests in total (6 new).

### Sprint 12c — Guided onboarding, profile strength and nudges
- Portal home walks new members from sign-up to first seat in 7 linked steps (email, mobile, two-step sign-in, team profile, license, apply, first seat) with "Next:" pointing at the next one.
- Profile strength out of 100 (team name, logo, about, areas served, website or phone, social link, verified license, a teammate), shown with what's missing and the points each adds. It now feeds waitlist ranking with licenses until the trust score (Sprint 34).
- Find your network (Portal → Groups): every public network with price, trial, founding-seat and invite-only badges, search, and "Apply" in the right words (city, county, metro area or state). Private networks are never listed.
- Profile preview: the team's public profile as others will see it (logo, about, verified licenses, founding member, seats), marked as a preview until public profiles go live (Sprint 26).
- Onboarding nudges: a reminder 2 and 7 days after sign-up to members who haven't applied for a seat (once each; never staff).
- 235 PHP tests in total (5 new).

### Sprint 12b — Licenses and verification
- Licenses (Portal → Profile and licenses): leaders and assistants add a license per industry and state with number, expiry, name on the license and an optional copy (PDF or photo, kept on private storage). The number is checked against the state's format when one is configured.
- License queue (Admin → License queue, `licenses.verify`): pending, verified, rejected and expired; "Check on the state's site" using the licensing lookup links; the document opens through a link that expires in 15 minutes; a warning when another team uses the same number in the same state. Rejecting needs a note the team sees; every decision is audited and the team is told by email and in-app.
- Expiry: reminders 30 and 7 days before (once each), then the license is marked expired and the team told (daily job).
- Waitlist ranking now uses licenses: verified licenses rank highest, pending ones next; scores update when a license is verified, rejected or expires.
- 230 PHP tests in total (5 new).

### Sprint 12a — Team profiles and members
- Team profile (Portal → Team): name, logo, about, areas served, website, phone, founding year and social links (LinkedIn, Facebook, Instagram, YouTube, X).
- Members with roles: leader, assistant (edits the profile) and member. Every new pro starts as the leader of a team of one, created the first time they need it.
- Invitations by email (14 days): the invited person sees the team before signing in, comes back after signing in or creating an account, and can only accept with the invited email. Leaders can cancel invitations and remove members.
- Fixed in review: a new pro inviting someone before ever opening the Team page was refused; their team is now created on first use from any screen.
- 225 PHP tests in total (4 new).

### Sprint 11b — Seat map, invitations and real demand numbers
- Seat map for owners and staff (Admin → Groups → a group → Seats → Seat map, or the owner overview): every territory as held, backup only, waitlisted or open, with counts, hover details, labels and a list view.
- Invitations (bulk seat seeding): owners and staff invite pros to specific territories, one or many at once, by email; invitations last 14 days and are how invite-only groups get members. The public invitation page shows the offer before signing in, and returns the pro there after signing in or creating an account. Accepting runs through the normal seat rules (seat, backup or waitlist) and must be from the invited email. Every invitation is in the seat's history.
- Overflow demand now shows real waitlisted teams and the share of the 100 largest metro areas with a seat holder.
- Scheduled pricing and rule changes now notify every seat holder in the group 30 days ahead, not just owners.
- Visual check of the applications queue, Seats tab with history, seat map and apply page; the map was made clearer (bigger dots, grid, labels) and an out-of-date hint removed.
- 221 PHP tests in total (5 new).

### Sprint 11a part 2 — Seats, applications and the automated seat lifecycle
- Seats: one per territory per group (city, county, metro or state, found from the team's city), with a holder, one backup and a waitlist. Teams (minimal until onboarding in Sprint 12) apply from the portal; the license number is required where the industry needs one and checked against the state's format.
- Applications queue for group owners and staff: approve (the team gets the seat on its free trial, becomes backup, or joins the waitlist, shown before approving) or decline with a reason the team sees. Open groups can auto-approve.
- Automated non-payment lifecycle (agreed policy): failed payment → 7 days' grace → inactive (new referrals go to the backup; not charged) → released on day 30 (founding price lost). Paying before release reactivates instantly. Grace, release and claim-window lengths are group rules.
- Seat offers: a released seat goes to the backup first, then the waitlist in rank order, each with 72 hours to claim; unclaimed or declined offers pass on automatically (checked hourly). When the backup takes the seat, the top waitlisted team becomes the new backup.
- Notifications at every stage, by email and in-app: the team (decisions, payment problems, inactive, released, offers), the group's owners and the platform owners (every application, claim, grace, inactive, release and offer).
- Seat history: every application, claim, backup, waitlist, payment, inactive, release and offer event, shown per seat in Admin → Groups → Seats.
- Group admin now shows real seat counts in health and "who this change affects". Billing (Sprint 22) calls the payment hooks.
- 216 PHP tests in total (9 new).

### Sprint 11a part 1 — $10 pricing and bulk pricing
- Agreed launch pricing as the default: $10 per city per group per month; founding members $10, locked while they hold the seat; multi-city discounts off by default.
- The 46 launch groups move from the $79 sample price to $10 (only groups still on the untouched sample price; any price an admin set is kept).
- Bulk pricing: select groups in Admin → Groups and set pricing for all of them at once, now or on a date (members told 30 days ahead). Tier C groups always keep the value guarantee off; an invalid change leaves every group untouched.
- PRD and sprint breakdown updated with the pricing decisions, the non-payment policy (7 days' grace, inactive and not charged, released on day 30, founding price lost on release) and who is notified at each stage.
- 207 PHP tests in total (5 new).

### Sprint 10b — Group overrides, private networks and overflow demand
- Per-group overrides: a group can replace its industry's referral form, pipeline or agreement (Admin → Groups → a group → Overrides), using the same editors as industries, with versions and "Use the industry's version". Resolution: group → industry → family default.
- Group agreements need their own super-admin approval; until then the group's members keep signing the industry's approved agreement, never a draft. The agreement screen says which version members sign.
- Private networks: a company's own network for passing clients between its branches (invite only, an owner from the start, never listed in the directory or public pages).
- Overflow demand (Admin → Groups → Overflow demand): every industry's groups, and "Open next group" by US Census region (Northeast, Midwest, South, West) or as a second national network. The new group copies rules, pricing, form and pipeline from the launch group; a customised agreement comes as an unapproved draft. One group per region per industry. Waitlist and metro-fill numbers appear once seats exist (Sprint 11).
- Group-level message wording moves to Sprint 16, where messages are sent for a specific group's referrals.
- 202 PHP tests in total (6 new).

### Sprint 10a — Group admin, owners and scheduled changes
- Admin → Groups → a group: details (name, description, photo, draft/live/closed, open or invite-only), pricing (seat and founding price, trial, multi-city discounts, annual months free, value guarantee) and rules (accept and first-contact hours, update days, seat challenge, company cap, waitlist ranking).
- Compliance locks: the value guarantee can't be switched on in Tier C; a group can only go live once its industry is live; founding price can't exceed the seat price; bigger seat counts get equal or bigger discounts.
- Member preview, "who this change affects" (fills in once seats exist in Sprint 11) and projected revenue beside the pricing editor.
- Scheduled changes: pricing or rules can take effect on a future date; owners are told 30 days ahead (seat holders join the notice in Sprint 11); applied automatically on the day by a daily job; cancellable. Every change is audited with before and after values.
- Group owners: add by email and remove; owners get their own group overview (health, rules, upcoming changes) and can never change pricing.
- Health card (owners, seats, referrals this month, average accept time) with placeholders until seats and referrals exist.
- 196 PHP tests in total (8 new).

### Sprint 9b — Launch groups, tier counsel packs and licensing starter data
- One launch group per industry (46), with the agreed names ("Residential Agent Alliance", "Solar Installers Network"…), description, default rules (24-hour accept and first contact, 7-day updates, seat challenge after 60 days below trust 70, 25% company cap, waitlist by trust) and sample pricing ($79 seat, $59 founding, 3-month trial, 2 months free annually, multi-city discounts; value guarantee off in Tier C). Created once and never overwritten.
- Admin → Groups and seats lists every group with pricing and rules (editing, owners and seats arrive in Sprint 10). The readiness board's Group column now shows each launch group.
- Tier counsel packs: one PDF per tier (A, B, C) with every industry's agreement, plain-English summary, state addenda and messages, for review in one sitting (Tier A, 30 industries: about 3 seconds).
- Licensing starter data for real estate, commercial real estate, land, ranch and farm, property management, mortgage (NMLS), insurance and Medicare: each state's likely licensing body and a national lookup link, marked "needs review". Starter rows never count as coverage until an admin saves them; existing rows are never touched.
- Fixed in review: the tier pack used the tier inside a closure without capturing it.
- 188 PHP tests in total (7 new).

### Sprint 9a — Launch catalogue
- All 46 launch industries (30 Tier A, 12 Tier B, 4 Tier C) with names, families, tiers, territories, icons, terminology, descriptions and licensing, from `php artisan t2t:catalogue:sync` (also run by the database seeder).
- Safe to re-run: the sync remembers what it last wrote, so values an admin changed are always kept, while catalogue improvements reach values nobody touched.
- Industry-specific details on top of the family defaults: 18 industries get extra referral questions (solar power bill and roof type, mortgage loan purpose and credit range, Medicare plan interest, and more) and 9 get their own stage names (solar "Site survey", mortgage "Pre-qualified"). Every seeded form and pipeline must pass the builder's own checks, so a bad one can never be seeded.
- Launch readiness board (Admin → Industries → Launch readiness): every industry's terms, agreement approval, licensing coverage, compliance sign-off and group, with "ready to go live" and filters. The admin dashboard shows "x of 46 industries ready".
- Admin message labels now use each industry's words ("Your introduction was accepted" in Tier B).
- 181 PHP tests in total (7 new).

### Sprint 8b part 2 — Industry messages and terminology
- Terminology completed: each industry now names a referral and a fee as well as its pros and clients. Defaults follow the tier: Tier B and C industries say "introduction" instead of "referral". Live preview in the industry form.
- Every message can use the industry's own words: `{pro}`, `{pros}`, `{client_term}`, `{clients_term}`, `{referral_term}` and `{fee_term}` are filled in automatically (`{client}` stays the client's name). Platform wording now uses them, so "New referral" becomes "New introduction" in mortgage lending.
- Industry message wording: Admin → Industries → Messages lists every notification, marks the ones this industry has customised, and edits email, text and in-app wording with preview in the industry's terms, versions and restore. "Use platform wording" returns to inheriting.
- Wording resolves industry → platform → default; industry and platform versions are kept separately.
- The counsel review pack now includes every message members and clients receive, as they read it, with its source (industry, platform or default) and the terms in use.
- 174 PHP tests in total (8 new).

### Sprint 8b part 1 — Referral agreements
- Agreement templates per industry (kind "agreement" in the industry template engine), with versions and reset to the default wording.
- Defaults by tier: Tier A referral fee agreement (parties and licenses, client, fee, protection period, fee paid business to business, governing law, signatures); Tier B reciprocal introduction agreement (no fee, consent, reciprocity credits); Tier C no agreement, only client consent.
- Editor: required clauses locked; optional clause library (protection period, confidentiality, mediation, non-solicitation, cancellation) switched on or off; custom clauses; reorder; merge-field picker; live preview with sample data in the industry's own words.
- Compliance enforced on save: Tier B and C cannot contain fee clauses, fee merge fields or wording describing a referral payment; required clauses can't be removed; unknown merge fields are rejected.
- Approval gate: every saved version waits for a super admin's approval, recorded with the reviewing attorney and a note. Members only ever sign the newest approved version; drafts never reach them.
- State addenda: extra wording per state, added automatically when the receiving team is in that state.
- Plain-English summary shown to both parties before signing; if the fee wording has been edited, the summary quotes it rather than paraphrasing.
- Compare any two versions clause by clause with word-level changes highlighted.
- PDF preview (watermarked sample) and a counsel review pack PDF: summary, wording, all state addenda, version and approval history. Every save, approval, addendum and pack download is audited.
- 166 PHP tests in total (12 new).

### Sprint 8a — Referral forms and pipelines
- Default referral form and pipeline for each of the eight industry families: a shared core (client, mobile, email, timeline, notes) plus family questions (price range for property, budget and home ownership for home services, coverage needs for insurance and financial, care type for care, and more).
- Referral form builder (Admin → Industries → Referral form): questions with eight field types, required, sensitive (never on public pages), choices, and roles (estimated value, timeline); reorder, add, remove; live preview at phone or desktop size in the industry's own words.
- Pipeline editor with a board preview: open stages plus exactly one Won and one Lost stage (they drive closing reports and the trust score).
- Inheritance: industries use their family default until customised; "Use family default" resets; every save is a version. Groups can override in Sprint 10.
- Checks before saving: errors block (missing client name or mobile, duplicate keys, too few options, bad pipelines); warnings must be confirmed (no estimated-value or timeline field, contact details not marked sensitive). Legal and care forms deliberately carry no estimated value.
- Saved forms become validation rules for sending referrals (Sprint 16). Only known field properties are stored.
- 154 PHP tests in total (15 new).

### Sprint 7b — Industry engine (part 1)
- Industries: name, family (8), terminology (what a pro and a client are called), icon, description, territory unit (city, county, metro or state) and whether a license is required.
- Compliance tiers as one rule set in code (`Tier`): A open, B restricted, C directory only, deciding member referral fees, referral routing, consumer requests, the value guarantee, disclosures and whether a platform fee can ever exist.
- Compliance gate: an industry can only go live after a super admin records approval with a sign-off note. Changing the tier removes the approval, takes the industry offline and switches off any platform fee.
- Platform referral fee per industry (and later per group): none, percentage or flat, charged at acceptance or closing. Locked platform-wide until a super admin unlocks it with a record of the licenses and fund routing in place; never available for Tier B or C; locking again switches every fee off.
- Licensing per state: required or not, issuing body, license-number format (validated), public lookup page, notes. Coverage map in the builder shows configured and missing states.
- Licensing CSV export and import for counsel's state-by-state review (one industry or all); bad rows are skipped and reported.
- Territory preview: how many seats each territory choice creates nationwide before committing.
- Admin → Industries list with tier, territory, coverage and status; every change audited.
- 139 PHP tests in total (8 new).

### Sprint 7a — Locations
- US locations loaded: 51 states and DC (Puerto Rico loaded but switched off), 3,220 counties with FIPS codes, 29,738 cities and towns with county, coordinates and population. Sources and licences in `database/data/us/SOURCES.md` (Census via us-atlas, kelvins/US-Cities-Database, GeoNames population with attribution).
- `php artisan t2t:locations:import` is safe to re-run: names, coordinates and population refresh, while admin decisions (switched off, corrected county, aliases) are kept. Runs in the database seeder.
- `php artisan t2t:locations:import-metros` loads Census metro and micro areas (CBSAs) and their counties from the official delineation file.
- Fast type-ahead (`/locations/cities`): prefix match on names and aliases, largest places first; "St. George", "Saint George", "Ft Worth" and "Fort Worth" all match. Switched-off places never appear.
- Admin → Locations: switch states and cities on or off for seats, correct a city's county, add aliases ("H-Town"), merge duplicates (the duplicate's name keeps working as an alias); every change audited.
- Reusable, accessible `CityPicker` (combobox with arrow keys).
- CI: Lighthouse now runs only when public-facing code changes, saving Actions minutes on back-end work.
- 131 PHP tests in total (10 new), including a full import of the real dataset.

### Sprint 6b — Search, CDN, load testing and performance budgets
- Search in the command palette (Ctrl/⌘ K, portal and admin) as you type: settings and admin screens by keyword, and people by name or email for staff who manage them. Every source checks permissions; members never see admin results. Meilisearch in Docker and production (typo-tolerant), database search in tests; search health on `/health` and `/status`.
- Domains register their own search sources, so teams, cities and referrals plug in with their sprints.
- CDN: public pages are cacheable at the edge for signed-out visitors (5 minutes, served stale while refreshing) with no cookies; signed-in pages are never cached. Admin → System → CDN connects Cloudflare, and changed public pages are purged automatically.
- k6 load tests (`loadtest/`): public visitors (1,000 users by default) and signed-in members (300), with pass criteria. Smoke run on a 1-core sandbox: 7,575 requests from 200 users, 0 errors, median 220 ms.
- Lighthouse budgets in CI on home, login and status: performance 90+, accessibility 95+, best practices 90+, key SEO checks, server response under 600 ms. Locally: performance 96–97, accessibility 96–100, best practices 100.
- Fixed in review: session cookies were still set on cacheable pages (added after route middleware), which would stop a CDN caching them; edge caching now runs outermost.
- 121 PHP tests in total (6 new).

### Sprint 6a — Runtime and observability
- Laravel Octane on FrankenPHP (worker mode keeps the app in memory). Production image: multi-stage, non-root, opcache tuned, health-checked; separate targets for the app and server-side rendering; `compose.prod.yaml` runs web, Horizon, scheduler, SSR, MySQL and Redis. CI builds the production image whenever Docker files change.
- Octane safety: the settings store is now request-scoped, so one request's settings can never leak into the next (verified under Octane workers: maintenance on and off took effect on the very next request).
- Redis queues with Horizon at `/horizon`, for super admins with two-step sign-in only; long queue waits email the support address.
- `/health` for uptime monitors (database, cache, queue, storage; 200 or 503) and a public `/status` page with an optional incident message.
- Request IDs on every response (`X-Request-Id`, valid incoming IDs kept), added to every log line and error report, and shown on error pages as a reference for support.
- Error tracking with Sentry (set `SENTRY_LARAVEL_DSN`): no personal data, the member identified only by their public ID.
- Structured JSON logs for production and a slow-query log (over 500 ms, configurable).
- N+1 watchdog: key pages have query budgets in the test suite.
- Fixed in review: the super-admin shortcut could open the queue dashboard without two-step sign-in.
- 115 PHP tests in total (9 new).

### Sprint 5c — Payments, storage, AI, modules and launch tools
- Admin → System → Overview: one card per provider (email, text, payments, storage, AI) with its last check and live health; **Test everything** checks all providers in one click and remembers the results.
- Payments: Stripe keys and webhook signing secret (encrypted), "Test connection" shows the account name and test/live mode; signed webhook endpoint `/webhooks/stripe` stores each event once (retries are harmless) for billing in Sprint 22.
- Storage: this server, or any S3-compatible service (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2). Private files open only through signed links that expire; the storage test writes, reads and deletes a file.
- AI: Anthropic provider with model, prices per million tokens and a monthly spend cap; usage metered per month; "Test connection" shows the reply, tokens and cost.
- Modules: switch push, consumer requests, pre-launch reservations, community, events, cross-industry introductions, partner directory and AI on or off without a deploy; switched-off modules are hidden and their routes return 404.
- Maintenance mode: members see the branded "We'll be right back" page; staff can still sign in and webhooks keep arriving.
- Settings export and import: non-secret settings only, to copy staging to production; imports ignore unknown keys and never touch secrets.
- 106 PHP tests in total (9 new).

### Sprint 5b — Notification framework
- Notification catalogue: 11 types in four groups (referrals, network, billing, account and security), each with default channels (email, text, in-app, push), urgency and whether members can turn it off. One `Notifier` sends everything.
- Member settings: channel grid per type ("Urgent" and "Always on" labelled), quiet hours in the member's own time zone (urgent items still come through), and an optional daily summary at 7:00.
- Smart bundling: non-urgent emails that arrive within 10 minutes are sent as one ("3 updates on your referrals"); held items go out when quiet hours end.
- "Why am I getting this?" link in every email, opening that notification's own setting.
- Notification centre: real bell with unread count, latest five, mark read and mark all read; full page with All/Unread.
- Browser push (standard Web Push with VAPID keys, no third-party service), service worker, expired subscriptions removed automatically.
- Texting compliance: STOP-type replies opt a number out of every text (logged as "suppressed" in the delivery log), START opts back in, HELP gets an answer; inbound webhook verified with Twilio's signature.
- Admin → Templates: edit email, text and in-app wording with merge fields and live preview; every save is a new version; restore any version or reset to default; text length limit; all audited.
- Seeded wording for trial ending and "free month credited" for billing in Sprint 22.
- Fixed in review: restoring a template version could leave no version active; now written directly to the database.
- 97 PHP tests in total (16 new).

### Sprint 2c part 3 — Admin pricing, pre-launch and brand kit (prototype)
- Admin → Groups and pricing: seat price, founding price, trial length, multi-city discounts, annual months free, value guarantee (forced off in Tier C), company cap, seat-challenge rules, waitlist ranking, locked platform-fee mode, member preview and projected revenue.
- Admin → Overflow demand: waitlisted teams and metro fill per network, "ready for a second group" flags, and "Open next group" (region, name, inherits rules, offers seats to waitlisted teams).
- Pre-launch "Reserve your city": launch countdown, two-minute reservation, founding price and badge, invite-to-move-up, share card on success, live map and state race.
- Brand kit: eight custom family emblems, all 46 industry tiles, share-card generator (fits long city names), motion principles, photography and illustration direction; written guide in docs/design/brand-kit.md.
- All screens checked at 375 px with no horizontal scroll.

### Sprint 2c part 2 — Member screens (prototype)
- Network page (Solar Installers Network): stats, search, list or map, "Send referral" on every installer, reserved and open cities, network rules (deadlines, seat challenge, company cap) and top installers.
- Team profile: verified-license and founding-member badges, client and peer ratings, response time, update rate, 6-month report card, verified-only reviews, "Refer to me" code.
- Claim flow: "My team" or "A company with several cities"; industry and cities with held/reserved/open status; per-seat payer and city-leader invite for companies; license check; confirmation with trial end dates and founding price.
- Company dashboard: seats by city with city leader, payer, trial end, referrals and "quiet month" status; state caps; private-network offer.
- Billing: seats with trial dates and free-month credits, next invoice, annual switch, invoices.
- All five screens checked at 375 px with no horizontal scroll.

### Sprint 2c part 1 — Multi-industry public design (prototype)
- Industry catalogue for the 46 launch industries: families, compliance tiers, territories and terminology (agent, installer, advisor…).
- Brand kit start: industry icon tiles with family accents and the t2t relay corner (placeholder for the custom icon family).
- National map: every metro's seat (claimed, reserved by a founding member, open) per industry, filling in on load.
- New Home: industry picker with search, live counters, map with industry switcher and most-claimed states, the 46 industries by family, a no-signup "send a referral" demo, value guarantee and "seats are earned".
- Pricing: per-seat pricing, 3-month trial, "no referrals, no charge", multi-city and annual savings, company plan and a live calculator.
- Industry hub, industry × state (Solar in Texas) and city page (Solar in Austin) with seat holder, ratings, city report card and request form.
- Checked at 375 px (no horizontal scroll) and desktop, light and dark. Published to the prototype link.

### Sprint 5a — Settings, providers and delivery log
- Platform settings store: admin-edited, cached, secrets encrypted at rest and shown masked (last four characters only); blank secret fields keep the saved value.
- Admin → System: General (company and support details), Email (log, SMTP, Amazon SES, Postmark, Mailgun; from name and address) and Text messages (log, Twilio number or Messaging Service). Changes apply on the next request, with no deploy, and are audited without revealing secrets.
- "Send test" for email and SMS showing the provider's own error when one is refused.
- Delivery log: every email and text with provider, status and error; Twilio delivery receipts update status via signed webhooks (X-Twilio-Signature verified).
- Live provider health (healthy, some failures, failing, idle) from the last hour of sends, on the System screen and the delivery log.
- Phone verification: "Text not arriving? Email me the code instead" appears after 30 seconds.
- Settings fall back to environment defaults when no database is reachable (CI install step, fresh installs).
- 82 PHP tests in total (12 new).

### Sprint 4b — Roles, staff and audit log
- Roles (super admin, staff, member) and an admin permission catalogue of 17 permissions in five areas, kept in sync on every deploy; super admins pass every check; new members get the member role.
- Admin area at `/admin` for staff and super admins only, and only with two-step sign-in on (staff without it are sent to set it up). Navigation shows only built screens each person may open.
- Staff management: invite by email with chosen permissions (set-password link, email verified on first password), edit permissions, deactivate and reactivate; nobody can change their own access, and only super admins can change super admins.
- `php artisan t2t:super-admin email` bootstraps the first super admin.
- Audit log (7-year retention): sign-ins, failed attempts, lockouts, password resets, email verification, two-step and passkey changes, account changes with before and after values (never secrets), and admin actions, each with IP and device. Filter by person, type and dates; CSV export (itself audited).
- New-device alerts by email with a “This wasn’t me” link (signed, 7 days) that ends every session, removes devices and passkeys, and sends a password reset.
- Admin dashboard with member, staff and sign-in counts.
- 70 PHP tests in total.

### Sprint 4a — Accounts and sign-in
- Sign-up with first and last name, work email, US mobile (normalised to E.164) and a 12+ character password checked against known breaches; terms and SMS consent.
- Sign-in: password with lockout after 5 failures, passkeys (Face ID, Touch ID, Windows Hello, security keys; autofill-ready), and one-tap email sign-in links (single-use, 15 minutes).
- Email verification; mobile verification by 6-digit SMS code (hashed, 10-minute expiry, 5 attempts, 3 sends per 10 minutes, change number). SMS goes through an `SmsSender` interface (log driver now, Twilio in Sprint 5).
- Two-step sign-in with an authenticator app: QR setup, confirmation, recovery codes, and “don’t ask again on this device for 30 days”.
- Security settings (behind password confirmation): passkeys, two-step sign-in, password change, signed-in devices with remove and “sign out of all other devices”.
- Protected portal at `/app` (verified email and mobile, active account) with a first-run setup checklist.
- Users keyed by ULID publicly; Identity domain (`app/Domains/Identity`); ADR 0006.
- 52 PHP tests (registration, lockout, phone codes, sign-in links, two-step and trusted devices, device isolation, passkey options, noindex) and 10 component tests.

### Sprint 3b — Design system in the app
- Tailwind v4 theme generated from `design/tokens.json` (`npm run tokens`); light, dark and match-device modes with no flash on load; CI fails if the theme drifts from the tokens.
- Components on the generated theme: Logo, Button (variants, sizes, loading, asChild), Card, Field with accessible label/hint/error wiring, Input, Select, Textarea, Badge, TempBadge, Avatar, TrustRing, Kpi, Countdown, Segmented (arrow-key radio group).
- Primitives on Radix, restyled with tokens: Dialog (bottom sheet on phones), Popover, Dropdown menu, Tooltip, Toasts (Sonner), Command palette (cmdk).
- Layouts: public site (skip link, responsive nav, footer), portal (sidebar, owner section, Ctrl/⌘ K palette, notifications, theme menu, mobile bottom tabs), admin (distinct navy sidebar).
- Branded, noindex error pages for 403, 404, 419, 500 and 503.
- Home page rebuilt on the public layout (full design arrives in Sprint 36).
- Storybook 10 with light/dark switcher and accessibility addon (`npm run storybook`).
- Component tests (Vitest, Testing Library) with axe accessibility checks on every component; run in CI quick checks.

### Sprint 3a — Application foundation
- Laravel 13.40 on PHP 8.4 with React 19, Inertia 3 server-side rendering, TypeScript (strict) and Tailwind CSS v4; self-hosted variable Inter with preload and metric-matched fallbacks.
- SEO/AEO foundation (`app/Domains/Seo`): `SeoData` rendered server-side into title, description, canonical, robots, Open Graph and JSON-LD; schema builders (Organization, WebSite, BreadcrumbList, FAQPage, Article); generated robots.txt with AI-crawler rules, sitemap index and per-domain sitemaps, llms.txt, IndexNow key file and background IndexNow notifications on URL changes; canonical-URL redirects; noindex for private areas and all non-production environments.
- Security baseline: nonce-based Content Security Policy, HSTS in production, frame, content-type, referrer and permissions headers; `/up` health check; strict Eloquent mode outside production.
- Modular monolith structure (`app/Domains`) with architecture tests; ADRs 0001–0005.
- Docker Compose development environment (PHP 8.4, MySQL 8.4, Redis, Mailpit, Vite).
- Quality: Pint, Larastan level 8, Pest (20 tests), ESLint, Prettier, TypeScript; pre-commit hook; GitHub Actions per the agreed minutes rules; weekly grouped Dependabot updates.
- Laravel Pennant (feature flags), Sentry hook (enabled by DSN), Telescope and Debugbar registered in local only.

### Sprint 3a — Application foundation
- Laravel 13.40 on PHP 8.4 with React 19, Inertia 3 server-side rendering, TypeScript (strict) and Tailwind CSS v4; self-hosted variable Inter with preload and metric-matched fallbacks.
- SEO/AEO foundation (`app/Domains/Seo`): `SeoData` rendered server-side into title, description, canonical, robots, Open Graph and JSON-LD; schema builders (Organization, WebSite, BreadcrumbList, FAQPage, Article); generated robots.txt with AI-crawler rules, sitemap index and per-domain sitemaps, llms.txt, IndexNow key file and background IndexNow notifications on URL changes; canonical-URL redirects; noindex for private areas and all non-production environments.
- Security baseline: nonce-based Content Security Policy, HSTS in production, frame, content-type, referrer and permissions headers; `/up` health check; strict Eloquent mode outside production.
- Modular monolith structure (`app/Domains`) with architecture tests; ADRs 0001–0005.
- Docker Compose development environment (PHP 8.4, MySQL 8.4, Redis, Mailpit, Vite).
- Quality: Pint, Larastan level 8, Pest (20 tests), ESLint, Prettier, TypeScript; pre-commit hook; GitHub Actions per the agreed minutes rules; weekly grouped Dependabot updates.
- Laravel Pennant (feature flags), Sentry hook (enabled by DSN), Telescope and Debugbar registered in local only.

### Sprint 1 — Brand, design system and user flows
- Brand identity: t2t lettermark (the "2" is an arrow from Relay Cobalt to Handoff Orange), horizontal, stacked, mono and on-dark logos with outlined lettering.
- Icons: app icon, PWA and maskable icons, Apple touch icon, favicon set (SVG, ICO, PNG).
- Social: link-preview image (1200 × 630) and profile avatar.
- Brand guide: naming, logo rules, colour with contrast checks, the relay rule (sender cobalt, receiver orange), lead-temperature colours, typography, imagery, voice and tone.
- Design tokens (`design/tokens.json`): colour scales, light and dark themes, type, spacing, radius, shadow, motion, breakpoints, layout.
- Component inventory (42 components plus website-builder blocks), information architecture for the public site and every role, 10 core user flows, accessibility and motion principles.
- Repo foundation: README, CONTRIBUTING, CHANGELOG, editor and ignore settings, reproducible brand build script.
