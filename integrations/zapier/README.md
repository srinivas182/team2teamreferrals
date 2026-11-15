# Team to Team Referrals: Zapier app

Built on the public API v1 and its webhooks (`/developers`).

| Kind | Key | What it does |
| --- | --- | --- |
| Trigger (instant) | `referral_received` | A referral is sent to your team |
| Trigger (instant) | `referral_accepted` | A referral is accepted (by you or by the team you sent it to) |
| Trigger (instant) | `referral_stage_changed` | A referral moves to another stage |
| Trigger (instant) | `referral_won` | A referral moves to a won stage |
| Trigger (instant) | `lead_received` | A verified lead arrives from your profile |
| Action | `send_referral` | Send a referral (the city is looked up by name and state; consent required) |
| Action | `add_note` | Post an update on a referral |
| Action | `move_stage` | Move a referral to a stage (with closing details for won, a reason for lost) |
| Action | `update_lead` | Set a lead's status |
| Search | `find_referral` | Find a referral by the client's email |

Instant triggers subscribe by creating a webhook endpoint for Zapier's URL (`POST /webhooks`) and remove it when
the Zap is turned off. Zapier's endpoints don't count toward a team's endpoint allowance (up to 50).

## Tests

`npx vitest run integrations/zapier` from the repository root (they also run in CI).

## Publishing

1. `npm install -g zapier-platform-cli`, then `zapier login` with the company's Zapier developer account.
2. In this folder: `npm install`, then `zapier register "Team to Team Referrals"` (first time only) and `zapier push`.
3. Zapier's sign-in asks for a **live** API token from Portal → Developers (API).
4. Share the app: in the Zapier developer platform, copy the invite link and paste it in Admin → Integrations → Zapier.
   It then appears on members' Developers page.
5. A public listing in Zapier's directory can be requested once the platform has launched (Zapier doesn't list beta
   or invite-only apps).

`BASE_URL` (environment variable in the Zapier app settings) points the app at another server, e.g. staging.
