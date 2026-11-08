import { useState } from "react";
import { MapPin, Clock, CheckCircle2, ChevronDown, QrCode, Share2, ShieldCheck, Star, Mail, Lock } from "lucide-react";
import { Container, Button, Card, Badge, Input, Select, Field, Textarea, Avatar, TrustRing, TempBadge, Logo, cn, type Temp } from "../ui/kit";
import { groups, sunBeltSeats, houston } from "../data/mock";

/* ---------------- Group page ---------------- */
export function GroupPage() {
  const g = groups[0];
  const [open, setOpen] = useState<string | null>("Texas");
  const states = Array.from(new Set(sunBeltSeats.map(s => s.state)));
  return (
    <>
      <section className="border-b border-line bg-surface">
        <Container className="py-12">
          <a href="#groups" className="text-sm font-medium text-primary">← All groups</a>
          <div className="mt-4 flex flex-col gap-6 lg:flex-row lg:items-end">
            <div className="flex items-start gap-5">
              <span className="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-orange-500 font-display text-2xl font-bold text-navy-900">ST</span>
              <div>
                <h1 className="display font-display text-3xl font-extrabold sm:text-4xl">{g.name}</h1>
                <p className="mt-2 max-w-2xl text-muted">{g.tagline}</p>
                <p className="mt-2 text-sm text-muted">Real estate · United States · Run by {g.owner}, {g.ownerCity}</p>
              </div>
            </div>
            <Button className="lg:ml-auto" href="#signup">Apply to join</Button>
          </div>
          <dl className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
            {[["Teams", g.teams], ["Cities filled", `${g.filled} of ${g.total}`], ["Referrals", g.referrals.toLocaleString()], ["Median accept time", "16 min"]].map(([k, v]) => (
              <div key={k as string} className="rounded-md bg-bg p-4"><dt className="text-sm text-muted">{k}</dt><dd className="mt-1 font-display text-2xl font-bold tabular">{v}</dd></div>
            ))}
          </dl>
        </Container>
      </section>
      <Container className="grid gap-8 py-10 lg:grid-cols-[1fr_320px]">
        <div>
          <h2 className="text-xl font-semibold">Teams by city</h2>
          <div className="mt-4 space-y-3">
            {states.map(st => (
              <Card key={st}>
                <button className="flex w-full items-center justify-between p-4 text-left font-semibold" aria-expanded={open === st} onClick={() => setOpen(open === st ? null : st)}>
                  {st} <span className="flex items-center gap-2 text-sm font-normal text-muted">{(n => `${n} ${n === 1 ? "team" : "teams"}`)(sunBeltSeats.filter(s => s.state === st).length)}<ChevronDown size={18} className={cn("transition-transform", open === st && "rotate-180")} /></span>
                </button>
                {open === st && (
                  <div className="overflow-x-auto border-t border-line">
                    <table className="w-full min-w-[620px] text-sm">
                      <thead className="text-left text-muted"><tr>{["City", "Team", "Trust", "Sent", "Received", "Conversion", "Responds in"].map(h => <th key={h} className="px-4 py-2 font-medium">{h}</th>)}</tr></thead>
                      <tbody>{sunBeltSeats.filter(s => s.state === st).map(s => (
                        <tr key={s.city} className="border-t border-line">
                          <td className="px-4 py-3 font-medium">{s.city}</td>
                          <td className="px-4 py-3"><a href="#pro" className="hover:text-primary">{s.team}</a><div className="text-muted">{s.leader}</div></td>
                          <td className="px-4 py-3"><TrustRing score={s.trust} size={36} /></td>
                          <td className="px-4 py-3 tabular">{s.sent}</td><td className="px-4 py-3 tabular">{s.received}</td>
                          <td className="px-4 py-3 tabular">{s.trust === "new" ? "—" : `${s.conversion}%`}</td><td className="px-4 py-3 tabular">{s.response}</td>
                        </tr>))}</tbody>
                    </table>
                  </div>
                )}
              </Card>
            ))}
          </div>
        </div>
        <aside className="space-y-6">
          <Card className="p-5">
            <h2 className="font-semibold">Open cities</h2>
            <p className="mt-1 text-sm text-muted">{g.total - g.filled} cities have no team yet.</p>
            <ul className="mt-4 space-y-2">{g.open.map(c => (
              <li key={c} className="flex items-center justify-between rounded-md border border-line p-3 text-sm"><span className="flex items-center gap-2"><MapPin size={15} className="text-success" />{c}</span><a href="#signup" className="font-semibold text-primary">Claim</a></li>))}</ul>
          </Card>
          <Card className="p-5">
            <h2 className="font-semibold">Group rules</h2>
            <ul className="mt-3 space-y-2 text-sm">
              {[["Accept or reject", "within 24 hours"], ["First call, Hot leads", "within 2 hours"], ["Status updates", "every 7 days"], ["Default referral fee", "30%"], ["Protection period", "12 months"]].map(([k, v]) => <li key={k} className="flex justify-between gap-4"><span className="text-muted">{k}</span><span className="font-medium">{v}</span></li>)}
            </ul>
          </Card>
        </aside>
      </Container>
    </>
  );
}

/* ---------------- City page (with consumer request) ---------------- */
export function CityPage() {
  const [sent, setSent] = useState(false);
  const [t, setT] = useState<Temp>("hot");
  return (
    <>
      <section className="border-b border-line bg-surface">
        <Container className="py-12">
          <nav className="text-sm text-muted" aria-label="Breadcrumb"><a href="#home" className="hover:text-ink">Real estate</a> / <a href="#home" className="hover:text-ink">Texas</a> / <span className="text-ink">Houston</span></nav>
          <h1 className="display mt-3 font-display text-4xl font-extrabold sm:text-5xl">Trusted real estate teams in Houston, TX</h1>
          <p className="mt-4 max-w-3xl text-lg text-muted">
            Two verified teams hold Houston seats on Team to Team. Bayou City Homes accepts referrals in a median of 11 minutes and has closed 24 referred deals. Referral fees are agreed in writing before any lead details are shared.
          </p>
          <p className="mt-2 text-sm text-subtle">Updated November 8, 2026</p>
        </Container>
      </section>
      <Container className="grid gap-8 py-10 lg:grid-cols-[1fr_380px]">
        <div className="space-y-8">
          <section>
            <h2 className="text-xl font-semibold">Seats in Houston</h2>
            <div className="mt-4 space-y-3">
              {houston.seats.map(s => s.team ? (
                <Card key={s.group} className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                  <Avatar name={s.leader} size={44} tone="orange" />
                  <div className="min-w-0"><a href="#pro" className="font-semibold hover:text-primary">{s.team}</a><div className="text-sm text-muted">{s.leader} · {s.group}</div></div>
                  <div className="flex items-center gap-5 text-sm sm:ml-auto"><span className="flex items-center gap-1"><Clock size={15} className="text-muted" />{s.response}</span><span>{s.closed} closed</span><TrustRing score={s.trust} size={40} /></div>
                </Card>
              ) : (
                <Card key={s.group} className="flex flex-col gap-3 border-dashed p-5 sm:flex-row sm:items-center">
                  <MapPin className="text-success" /><div><div className="font-semibold">Open seat</div><div className="text-sm text-muted">{s.group}</div></div>
                  <Button size="sm" className="sm:ml-auto" href="#signup">Claim Houston</Button>
                </Card>))}
            </div>
          </section>
          <section>
            <h2 className="text-xl font-semibold">Local partners</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">{houston.partners.map(p => <Card key={p.name} className="p-4"><div className="font-medium">{p.name}</div><div className="text-sm text-muted">{p.kind}</div><Badge className="mt-3">Sponsored listing</Badge></Card>)}</div>
          </section>
          <section>
            <h2 className="text-xl font-semibold">Houston referral questions</h2>
            {[
              ["How do I refer a client to a Houston agent?", "Join a group, open Send referral, choose Houston and the team holding the seat. They accept and sign the agreement before seeing your client’s details."],
              ["What referral fee is normal in Houston?", "Most groups default to 30% of the receiving agent’s commission. You can offer more when you send."],
              ["How fast will my client hear from someone?", "Groups set deadlines. In Sun Belt Top Teams, Hot leads get a first call within 2 hours of acceptance."],
            ].map(([q, a]) => <details key={q} className="border-b border-line py-4"><summary className="cursor-pointer list-none font-semibold">{q}</summary><p className="mt-2 text-muted">{a}</p></details>)}
          </section>
        </div>
        <aside>
          <Card className="p-6 lg:sticky lg:top-24">
            {sent ? (
              <div className="text-center" role="status">
                <CheckCircle2 size={44} className="mx-auto text-success" />
                <h2 className="mt-3 text-xl font-semibold">Request sent</h2>
                <p className="mt-2 text-muted">Bayou City Homes has your request. You’ll get a text with your agent’s name and a link to follow along, usually within the hour.</p>
                <Button variant="secondary" className="mt-5" href="#portal">Preview your client page</Button>
              </div>
            ) : (
              <form onSubmit={e => { e.preventDefault(); setSent(true); }} className="space-y-4">
                <div><h2 className="text-xl font-semibold">Looking for an agent in Houston?</h2><p className="mt-1 text-sm text-muted">Tell us what you need. A verified local team will contact you.</p></div>
                <Field label="I want to"><Select><option>Buy a home</option><option>Sell a home</option><option>Buy and sell</option></Select></Field>
                <fieldset><legend className="mb-1.5 text-sm font-medium">When?</legend>
                  <div className="grid grid-cols-3 gap-2">{([["hot", "This month"], ["warm", "1–3 months"], ["future", "Later"]] as [Temp, string][]).map(([k, l]) => (
                    <button type="button" key={k} onClick={() => setT(k)} aria-pressed={t === k} className={cn("rounded-md border px-2 py-2.5 text-sm font-medium", t === k ? "border-primary bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200" : "border-line")}>{l}</button>))}</div>
                </fieldset>
                <Field label="Price range"><Select><option>$300k–$450k</option><option>$450k–$600k</option><option>$600k–$900k</option><option>$900k+</option></Select></Field>
                <div className="grid grid-cols-2 gap-3"><Field label="First name"><Input required autoComplete="given-name" /></Field><Field label="Last name"><Input required autoComplete="family-name" /></Field></div>
                <Field label="Mobile" hint="We’ll text a code to confirm it’s you."><Input required type="tel" inputMode="tel" placeholder="(713) 555-0142" /></Field>
                <Field label="Email"><Input type="email" autoComplete="email" /></Field>
                <label className="flex gap-2 text-sm text-muted"><input type="checkbox" required className="mt-1" />I agree to be contacted by a Team to Team member about my request, including by text. Reply STOP to opt out.</label>
                <Button className="w-full" type="submit">Send request</Button>
              </form>
            )}
          </Card>
        </aside>
      </Container>
    </>
  );
}

/* ---------------- "Refer to me" team profile ---------------- */
export function ProfilePage() {
  return (
    <Container className="py-12">
      <Card className="overflow-hidden">
        <div className="h-28 bg-gradient-to-r from-cobalt-500 to-orange-500" aria-hidden />
        <div className="px-6 pb-8 sm:px-10">
          <div className="-mt-12 flex flex-col gap-5 sm:flex-row sm:items-end">
            <span className="rounded-full border-4 border-surface"><Avatar name="Chris Delgado" size={96} tone="orange" /></span>
            <div className="sm:pb-2"><h1 className="display font-display text-3xl font-extrabold">Bayou City Homes</h1><p className="text-muted">Chris Delgado, team leader · Houston, TX</p></div>
            <div className="flex gap-2 sm:ml-auto sm:pb-2"><Button variant="secondary" size="sm"><QrCode size={16} />QR code</Button><Button variant="secondary" size="sm"><Share2 size={16} />Share</Button></div>
          </div>
          <div className="mt-8 grid gap-8 lg:grid-cols-[1fr_340px]">
            <div>
              <div className="flex flex-wrap gap-2"><Badge tone="success"><ShieldCheck size={13} />License verified, TX</Badge><Badge tone="cobalt">Fast Responder</Badge><Badge tone="orange">Top Closer 2026</Badge></div>
              <p className="mt-5 max-w-2xl text-[17px] leading-relaxed">Fourteen agents covering greater Houston — the Heights, Katy, Sugar Land and The Woodlands. We call every referred client within the hour and send you an update after every showing.</p>
              <dl className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {[["Trust score", "94"], ["Median accept", "11 min"], ["Referrals closed", "24"], ["Client rating", "4.9"]].map(([k, v]) => <div key={k} className="rounded-md bg-bg p-4"><dt className="text-sm text-muted">{k}</dt><dd className="mt-1 font-display text-2xl font-bold tabular">{v}</dd></div>)}
              </dl>
              <h2 className="mt-10 text-lg font-semibold">What referring agents say</h2>
              {[["Jordan Hale, St. George", "Called my buyer in 20 minutes and updated me after every showing. Closed in 41 days."], ["Keisha Grant, Tampa", "The most reliable Houston partner we’ve had."]].map(([n, q]) => (
                <figure key={n} className="mt-4 border-l-2 border-orange-500 pl-4"><div className="flex gap-0.5 text-orange-500" aria-label="5 out of 5">{[...Array(5)].map((_, i) => <Star key={i} size={14} fill="currentColor" />)}</div><blockquote className="mt-1">“{q}”</blockquote><figcaption className="mt-1 text-sm text-muted">{n}</figcaption></figure>))}
            </div>
            <Card className="h-fit p-5">
              <h2 className="font-semibold">Refer a client to Chris</h2>
              <p className="mt-1 text-sm text-muted">Members track it to closing. Not a member yet? You can still send, and we’ll invite you to follow along.</p>
              <Button className="mt-4 w-full" href="#signup">Send a referral</Button>
              <div className="mt-5 text-sm text-muted">Serves: Houston, Katy, Sugar Land, The Woodlands</div>
            </Card>
          </div>
        </div>
      </Card>
    </Container>
  );
}

/* ---------------- Auth ---------------- */
export function AuthPage({ mode }: { mode: "login" | "signup" }) {
  const signup = mode === "signup";
  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="flex flex-col px-6 py-8 sm:px-12">
        <a href="#home" aria-label="Home"><Logo className="h-9 w-auto" /></a>
        <div className="mx-auto my-auto w-full max-w-sm py-10">
          <h1 className="display font-display text-3xl font-extrabold">{signup ? "Claim your city" : "Welcome back"}</h1>
          <p className="mt-2 text-muted">{signup ? "Free during the pilot. Takes about five minutes." : "Log in to see referrals waiting for you."}</p>
          <form className="mt-8 space-y-4" onSubmit={e => { e.preventDefault(); location.hash = signup ? "onboarding" : "welcome"; }}>
            {signup && <div className="grid grid-cols-2 gap-3"><Field label="First name"><Input required /></Field><Field label="Last name"><Input required /></Field></div>}
            <Field label="Work email"><Input type="email" required autoComplete="email" /></Field>
            {signup && <Field label="Mobile" hint="For referral alerts. We’ll send a code."><Input type="tel" required /></Field>}
            <Field label="Password" hint={signup ? "At least 12 characters." : undefined}><Input type="password" required autoComplete={signup ? "new-password" : "current-password"} /></Field>
            <Button className="w-full" type="submit">{signup ? "Create account" : "Log in"}</Button>
          </form>
          <p className="mt-6 text-center text-sm text-muted">{signup ? <>Already a member? <a className="font-semibold text-primary" href="#login">Log in</a></> : <>New here? <a className="font-semibold text-primary" href="#signup">Claim your city</a></>}</p>
          {!signup && <p className="mt-2 text-center text-sm"><a className="text-muted hover:text-ink" href="#login">Forgot password?</a></p>}
        </div>
        <p className="flex items-center gap-2 text-xs text-muted"><Lock size={12} />Protected with encryption and optional two-step sign-in.</p>
      </div>
      <div className="hidden flex-col justify-between bg-navy-900 p-12 text-white lg:flex">
        <div />
        <div>
          <p className="font-display text-3xl font-bold leading-snug">“Four relocation leads closed through our group last quarter. Every fee was on the closing report.”</p>
          <p className="mt-6 text-[#AAB4CE]">Jordan Hale · Red Rock Realty Group, St. George</p>
        </div>
        <div className="flex gap-3">{(["hot", "warm", "future"] as Temp[]).map(x => <TempBadge key={x} t={x} />)}</div>
      </div>
    </div>
  );
}

/* ---------------- Blog post ---------------- */
export function BlogPost() {
  return (
    <Container className="py-14">
      <article className="mx-auto max-w-[72ch]">
        <a href="#home" className="text-sm font-medium text-primary">← Blog</a>
        <h1 className="display mt-4 font-display text-4xl font-extrabold leading-tight">How to price a referral fee your partners will actually honour</h1>
        <div className="mt-4 flex items-center gap-3 text-sm text-muted"><Avatar name="Maria Alvarez" size={32} />Maria Alvarez · 6 min read · Nov 4, 2026</div>
        <div className="mt-8 rounded-lg border border-line bg-surface p-5"><h2 className="font-semibold">In short</h2><p className="mt-1 text-muted">25–35% is standard for agent-to-agent referrals. Put it in a signed agreement before you share any client details, and agree a protection period so the fee survives a slow buyer.</p></div>
        <div className="mt-8 space-y-5 text-[17px] leading-[1.7]">
          <p>Every top producer has lost a referral fee to a handshake. The lead closes eight months later, the other agent has moved brokerages, and nobody remembers the number you agreed on the phone.</p>
          <h2 className="display pt-4 font-display text-2xl font-bold">Start from the market rate</h2>
          <p>Most groups on Team to Team default to 30%. Go higher for Hot, pre-approved buyers in competitive markets; the receiving team sees the estimated value before accepting, so a fair fee gets accepted faster.</p>
          <h2 className="display pt-4 font-display text-2xl font-bold">Sign before you share</h2>
          <p>On Team to Team, the receiving team sees only the city, budget and timeline until they accept. Accepting generates the agreement for both brokerages to sign. Only then do your client’s details unlock.</p>
        </div>
        <Card className="mt-12 flex flex-col items-start gap-4 p-6 sm:flex-row sm:items-center"><Mail className="text-primary" /><div><div className="font-semibold">Get one referral tip a week</div><div className="text-sm text-muted">Short, practical, no spam.</div></div><form className="flex w-full gap-2 sm:ml-auto sm:w-auto" onSubmit={e => e.preventDefault()}><Input type="email" placeholder="you@brokerage.com" aria-label="Email" className="sm:w-56" /><Button type="submit">Subscribe</Button></form></Card>
      </article>
    </Container>
  );
}
