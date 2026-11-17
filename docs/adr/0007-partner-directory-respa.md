# ADR 0007: Partner directory as flat-fee advertising with equal rotation

Status: accepted (Sprint 47)

## Context

Mortgage, title, inspection, insurance and other settlement-service businesses want to reach the home buyers and sellers who use our city pages.

Paying for referrals of settlement-service business is prohibited by RESPA Section 8. The CFPB's 2023 advisory opinion on digital comparison-shopping platforms treats a listing as a referral when three things come together:
- the presentation is not neutral
- the platform steers people toward some providers
- providers pay for that placement

## Decision

- Partners pay a flat monthly fee per city and category, the same for everyone. There are no per-lead, per-click or per-closing fees, and no bidding for position.
- Everyone listed in a city and category takes the top spot in turn, one day each (`Partners::rotate`). The order is the same all day, so cached pages stay consistent.
- Every list is labelled "Advertisement" with a "How this list works" note. Outbound links carry `rel="sponsored"`.
- Enquiries go to the chosen partner only and are never resold.
- Member endorsements are shown but never affect order. Members receive nothing for them.
- Partners are separate from the referral network. A mortgage lender may also be a network member; the two are unrelated.

## Consequences

- Ranking by quality, reviews or payment is off the table while this design holds.
- Any future change to placement or pricing needs a fresh review against Section 8 before it ships.
