# Component inventory

Every screen is built from these components. Sprint 2 designs each one at high fidelity; Sprint 3 builds them in React (Tailwind CSS, shadcn/ui on Radix primitives) and documents them in Storybook.

**Standard states** apply to every interactive component unless noted: default, hover, focus-visible (2 px cobalt-400 ring, 2 px offset), active, disabled, loading. Every component supports light and dark themes and works at 375 px wide.

## Foundations

| # | Component | Variants | Extra states / notes |
| --- | --- | --- | --- |
| 1 | Button | primary (cobalt), accent (orange, navy text), secondary (outline), ghost, danger, link; sizes sm/md/lg; icon-only | loading with spinner keeps width; destructive actions confirm |
| 2 | Icon button | ghost, outline; sizes | tooltip required for meaning |
| 3 | Text input | text, email, phone (US format), number, currency, password with reveal | error with message, success, read-only, character count |
| 4 | Textarea | auto-grow | character count, mention support (@member) |
| 5 | Select and combobox | single, multi, searchable, async (cities, teams) | empty result, creating new option |
| 6 | Checkbox, radio, switch | with label and help text | indeterminate (checkbox) |
| 7 | Date and date-range picker | single, range, presets (7d, 30d, quarter, YTD), compare-to | time zone shown |
| 8 | File upload | dropzone, single and multi, image with crop | progress, virus-scan pending, rejected type/size |
| 9 | Form layout | section, field group, inline help, sticky save bar | unsaved-changes guard |
| 10 | Tooltip and popover | top/right/bottom/left | keyboard and touch accessible |

## Feedback

| # | Component | Variants | Notes |
| --- | --- | --- | --- |
| 11 | Toast | success, error, info, with undo action | auto-dismiss 5 s, pause on hover, announced to screen readers |
| 12 | Banner / alert | info, success, warning, danger; page-level and inline | dismissible or persistent |
| 13 | Modal and dialog | sm/md/lg, confirm, destructive confirm (type to confirm) | focus trap, Esc to close, return focus |
| 14 | Drawer / sheet | right side (desktop), bottom sheet (mobile) | used for quick-view of a referral or lead |
| 15 | Skeleton | text, card, table row, chart | matches final layout to avoid shift |
| 16 | Empty state | first-use, no results, cleared, error | illustration, one sentence, one primary action |
| 17 | Progress | bar, ring (onboarding checklist), steps | |
| 18 | Countdown | badge and inline | colour shifts at 50% and 90% of SLA time; shows "Overdue" after |

## Data display

| # | Component | Variants | Notes |
| --- | --- | --- | --- |
| 19 | Data table | sortable, filterable, column chooser, row selection, bulk actions, sticky header, pagination and infinite scroll | collapses to cards below 768 px; saved views |
| 20 | KPI card | value, change vs previous period, sparkline, target | drill-down on click; tabular figures |
| 21 | Chart | line, area, bar, stacked bar, donut, funnel | sender cobalt vs receiver orange by default; accessible table fallback |
| 22 | Badge and status pill | status (Active, Inactive, Open, Closed, Pending), temperature (Hot, Warm, Future), verified | icon + label, never colour alone |
| 23 | Avatar and team chip | image, initials, group stack | trust score ring option |
| 24 | Trust score | ring, compact number, breakdown popover | "New" state under 3 referrals |
| 25 | Referral card | compact (lists), full (detail header) | temperature, value, countdown, sender → receiver |
| 26 | Referral timeline | vertical (detail), horizontal stepper (mobile header) | relay colours; read receipts; timestamps in user time zone |
| 27 | Kanban board and card | columns per CRM stage, WIP counts | drag and drop with keyboard alternative |
| 28 | Message thread | sender and receiver bubbles, attachments, system events | real-time; typing indicator |
| 29 | Activity feed / audit list | grouped by day | filter by actor and type |
| 30 | Seat map | grid by state and city, status colours, backup and waitlist counts | list view fallback |
| 31 | Network map | US map with city markers by status | zoom to state; keyboard list alternative |
| 32 | Leaderboard | ranked list with badges | current user highlighted |
| 33 | Agreement viewer and signature pad | PDF preview, typed or drawn signature | signed state with timestamp |
| 34 | Video player (training gate) | progress, no-seek-ahead, captions | resume position; completion state |

## Navigation and layout

| # | Component | Variants | Notes |
| --- | --- | --- | --- |
| 35 | App shell | sidebar (desktop, collapsible), top bar, bottom tab bar (mobile) | role-aware menus |
| 36 | Command palette | Cmd/Ctrl+K: navigate, search records, start actions | recent items; keyboard only |
| 37 | Breadcrumbs and page header | title, subtitle, actions, tabs | |
| 38 | Tabs and segmented control | underline tabs, pill segments | scrollable on mobile |
| 39 | Filters bar | chips, saved filters, clear all | collapses into a sheet on mobile |
| 40 | Onboarding checklist | steps with progress ring | dismissible after completion |
| 41 | Notification centre | bell with count, grouped list, mark all read | real-time |
| 42 | Public site header and footer | transparent-on-hero, sticky | mega menu for industries |

## Public site sections (website builder blocks)

Hero, how it works (3 steps), stats band, network map, group cards, city list, testimonials, pricing table, FAQ, comparison table, CTA band, blog grid, logo cloud, feature grid, image + text, video, team profiles, partner listings, consumer request form, contact form, newsletter sign-up, rich text, divider, spacer, embed. Each block has light and dark variants and default copy.
