# Core user flows

Ten journeys that every later sprint must support. Diagrams render on GitHub (Mermaid).

## 1. Sign-up and onboarding

```mermaid
flowchart TD
  A[Visitor clicks Join or Claim this city] --> B[Create account: name, email, mobile, password]
  B --> C[Verify email and mobile OTP]
  C --> D[Team profile: name, logo, bio, service areas]
  D --> E[Add license per state + brokerage + broker of record]
  E --> F[Upload brokerage W-9]
  F --> G[Save card]
  G --> H{Choose group and city}
  H -->|Seat open| I[Application to group owner]
  H -->|Seat taken| J[Join waitlist or apply as backup]
  I --> K{License verified and application approved?}
  K -->|No| L[Notified with reason; can fix and resubmit]
  K -->|Yes| M[Training video gate]
  M --> N[Dashboard with onboarding checklist]
```

## 2. Claiming a city

```mermaid
flowchart TD
  A[Network map or city page: Claim this city] --> B{Signed in?}
  B -->|No| C[Sign-up flow 1]
  B -->|Yes| D{Verified license in that state?}
  D -->|No| E[Add or verify license]
  D -->|Yes| F{Seat status}
  F -->|Open| G[Application with plan shown: free during pilot or monthly fee]
  F -->|Taken| H[Join waitlist or apply as backup]
  G --> I[Group owner approves]
  I --> J{Free period?}
  J -->|Yes| K[Seat Active]
  J -->|No| L[Subscription starts] --> K
```

## 3. Sending a referral

```mermaid
flowchart TD
  A[Send referral: web, app, email forward, voice note, card scan, WhatsApp, profile page] --> B[Draft created]
  B --> C[Pick group, state, city]
  C --> D{Seat holder active?}
  D -->|Yes| E[Show seat holder: trust score, response time]
  D -->|No, backup active| F[Show backup team]
  D -->|No one| G[Will post to open request board]
  E --> H[Lead details + temperature Hot/Warm/Future + value signals]
  F --> H
  G --> H
  H --> I[Fee % prefilled, can only go up]
  I --> J{Duplicate within protection period?}
  J -->|Yes| K[Warn: earlier referral keeps priority]
  J -->|No| L[Confirm lead agreed to be referred]
  L --> M[Referral sent; consent request goes to lead]
```

## 4. Accepting and signing

```mermaid
flowchart TD
  A[Receiver notified: email, SMS, push] --> B[Summary only: city, type, temperature, estimated value, sender]
  B --> C{Decision before deadline}
  C -->|Accept| D{Card charge succeeds?}
  D -->|Yes| E[Agreement generated]
  D -->|No| F[24 h to fix card, else routed to backup]
  E --> G[Both team leaders e-sign]
  G --> H[Full lead details unlock; lead added to receiver CRM]
  C -->|Reject with reason| I[Routed to backup or back to sender]
  C -->|No answer| J[SLA breach recorded; routed to backup]
```

## 5. Deadline breach to deactivation

```mermaid
flowchart TD
  A[SLA timer running] --> B[Reminders at 50% and 90%]
  B --> C{Deadline met?}
  C -->|Yes| D[Timer closed]
  C -->|No| E[Breach recorded]
  E --> F{Breaches in 90-day window}
  F -->|1| G[Warning to team; group owner notified]
  F -->|2| H[Team auto-deactivated in the group]
  H --> I[New referrals go to backup]
  I --> J{Owner or super admin review}
  J -->|Reactivate with notes| K[Breach count reset]
  J -->|Remove with notes| L[Seat offered to backup, then waitlist]
  L --> M[In-flight referrals move to new holder; agreements still protect senders]
```

## 6. Closing report and fee confirmation

```mermaid
flowchart TD
  A[Receiver marks Closed] --> B[Closing report: price, date, GCI, fee %, amount due]
  B --> C[Sender notified]
  C --> D{Sender reviews}
  D -->|Confirms| E[Awaiting payment]
  D -->|Disputes| F[Dispute flagged to group owner and super admin]
  E --> G{Fee received within 45 days?}
  G -->|Yes| H[Sender marks Fee received; earnings updated]
  G -->|No| I[Overdue; receiver trust score affected]
  A -.-> J[Outcome check to lead at 90 and 180 days]
  J --> K{Matches CRM?}
  K -->|No| F
```

## 7. Consumer request

```mermaid
flowchart TD
  A[Consumer on city page: Find a trusted pro] --> B[Request form: need, timeline, contact, consent]
  B --> C[Phone verification and spam check]
  C --> D{Seat holder active?}
  D -->|Yes| E[Routed like a referral with temperature]
  D -->|No| F[Backup, then open request board]
  E --> G[Accepted: fee charged, consumer gets client portal link]
  F --> G
```

## 8. Nurture lead lifecycle

```mermaid
flowchart TD
  A[Future lead accepted] --> B[Monthly touchpoint scheduled]
  B --> C[Receiver prompted to update status]
  C --> D{Lead status}
  D -->|Still nurturing| B
  D -->|Ready to act| E[Upgrade to Warm or Hot; sender sees change]
  D -->|Lost| F[Closed as lost with reason]
  E --> G[Normal pipeline and SLA rules]
  B -.-> H[Protection period extended while active]
```

## 9. Group owner approvals

```mermaid
flowchart TD
  A[Application received] --> B[Review profile, licenses, KPIs from other groups]
  B --> C{Schedule a call?}
  C -->|Yes| D[Call booked]
  C -->|No| E{Decision}
  D --> E
  E -->|Approve to seat| F[Team notified; training gate]
  E -->|Approve as backup| G[Backup slot]
  E -->|Waitlist| H[Added in order]
  E -->|Decline with note| I[Team notified]
```

## 10. Super admin creates an industry and a group

```mermaid
flowchart TD
  A[Create industry: Draft] --> B[Form fields, licensing rule, fee models, territory unit]
  B --> C[Templates: CRM stages, agreement, notifications, terminology, training]
  C --> D[Record legal review]
  D --> E[Switch industry to Live]
  E --> F[Create group: country, owner, rules, free period]
  F --> G[Enable cities]
  G --> H[Group visible in directory; cities open for claims]
```
