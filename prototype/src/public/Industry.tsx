import { ArrowRight, BadgeCheck, MapPin, Star, Timer, Users, Info, Send } from "lucide-react";
import { Container, Button, Card, Badge, TrustRing, Avatar } from "../ui/kit";
import { bySlug, families, metros, seatStatus, tierCopy, industries } from "../data/industries";
import { IndustryIcon } from "../ui/brand";
import { NationalMap, MapLegend } from "../ui/national";

const cap = (s: string) => s[0]!.toUpperCase() + s.slice(1);
const plural = (t: string) => (t.endsWith("y") ? t.slice(0, -1) + "ies" : t + "s");

function Faq({ items }: { items: [string, string][] }) {
  return <div className="divide-y divide-line rounded-lg border border-line bg-surface">{items.map(([q, a]) => <details key={q} className="group p-5"><summary className="cursor-pointer list-none font-semibold">{q}</summary><p className="mt-2 text-muted">{a}</p></details>)}</div>;
}

export function IndustryHub({ slug }: { slug: string }) {
  const i = bySlug(slug); const f = families[i.family]; const tier = tierCopy[i.tier];
  const open = metros.filter(m => seatStatus(m.city + m.st, i.slug) === "open").slice(0, 8);
  return (
    <>
      <section className="border-b border-line bg-surface">
        <Container className="grid gap-10 py-14 lg:grid-cols-[1.1fr_1fr] lg:items-center">
          <div>
            <a href="#industries" className="text-sm font-medium text-muted hover:text-ink">All industries</a>
            <div className="mt-4 flex items-center gap-4"><IndustryIcon industry={i} size={64} /><span className="text-sm font-semibold uppercase tracking-wide" style={{ color: f.accent }}>{f.name}</span></div>
            <h1 className="display mt-5 font-display text-4xl font-extrabold sm:text-5xl">The {i.name.toLowerCase()} referral network</h1>
            <p className="mt-4 max-w-xl text-lg text-muted"><strong className="text-ink">{i.group}</strong> connects one verified {i.pro} per {i.territory.toLowerCase()} across the US. When a {i.client} needs help somewhere you don’t serve, send them to a {i.pro} the network trusts, and track every step.</p>
            <div className="mt-7 flex flex-wrap gap-3"><Button size="lg" href="#signup">Reserve my {i.territory.toLowerCase()} <ArrowRight size={18} /></Button><Button size="lg" variant="secondary" href="#state-texas">Browse by state</Button></div>
            <div className="mt-8 grid grid-cols-3 gap-4 text-sm">{[[i.claimed.toLocaleString(), `${i.territory.toLowerCase()} seats claimed`], [open.length ? "Open" : "Waitlist", "in your area"], ["3 months", "free on every seat"]].map(([a, b]) => <div key={b}><div className="font-display text-2xl font-bold">{a}</div><div className="text-muted">{b}</div></div>)}</div>
          </div>
          <Card className="p-4"><NationalMap industry={i.slug} compact /><div className="mt-2"><MapLegend /></div></Card>
        </Container>
      </section>
      <Container className="grid gap-8 py-14 lg:grid-cols-[1fr_340px]">
        <div className="space-y-10">
          <section aria-labelledby="ans"><h2 id="ans" className="font-display text-2xl font-bold">How {i.name.toLowerCase()} referrals work here</h2>
            <p className="mt-3 text-muted">A member sends a {i.client} to the {i.pro} holding the target {i.territory.toLowerCase()}. The receiving {i.pro} accepts within the network’s deadline, contacts the {i.client}, and posts updates the sender can see in real time. {tier.note}</p></section>
          <section><h2 className="font-display text-2xl font-bold">Open {plural(i.territory.toLowerCase())} right now</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-2">{open.map(m => <a key={m.city + m.st} href="#city" className="flex items-center gap-3 rounded-lg border border-line bg-surface p-4 hover:border-primary"><MapPin size={18} className="text-success" /><span className="font-medium">{m.city}, {m.st}</span><span className="ml-auto text-sm font-semibold text-primary">Reserve</span></a>)}</div></section>
          <section><h2 className="font-display text-2xl font-bold">Questions {i.pros} ask</h2><div className="mt-4"><Faq items={[[`Who can join ${i.group}?`, `Licensed and verified ${i.pros} in good standing. We check licenses where your industry requires one.`], [`How many ${i.pros} per city?`, `One seat per ${i.territory.toLowerCase()}, plus a backup and a waitlist ranked by reputation.`], ["What does it cost?", "3 months free, then a flat monthly price per seat. Any month no referral reaches you is free."], ["How are referral fees handled?", tier.note]]} /></div></section>
        </div>
        <aside className="space-y-6">
          <Card className="p-6"><h3 className="font-semibold">Network rules</h3><ul className="mt-4 space-y-3 text-sm">{[["Accept or decline within", "24 hours"], ["First contact within", "24 hours"], ["Status updates every", "7 days"], ["Territory", i.territory]].map(([a, b]) => <li key={a} className="flex justify-between gap-3"><span className="text-muted">{a}</span><span className="font-medium">{b}</span></li>)}</ul></Card>
          {i.tier !== "A" && <Card className="flex gap-3 p-5 text-sm"><Info size={18} className="shrink-0 text-info" /><p className="text-muted"><strong className="text-ink">{tier.label} industry.</strong> {tier.note}</p></Card>}
          <Card className="p-6"><h3 className="font-semibold">Related networks</h3><ul className="mt-3 space-y-1">{industries.filter(x => x.family === i.family && x.slug !== i.slug).slice(0, 5).map(x => <li key={x.slug}><a href={`#industry-${x.slug}`} className="flex items-center gap-3 rounded-md p-1.5 hover:bg-bg"><IndustryIcon industry={x} size={28} /><span className="text-sm font-medium">{x.name}</span></a></li>)}</ul></Card>
        </aside>
      </Container>
    </>
  );
}

const texas = metros.filter(m => m.st === "TX");
export function StatePage() {
  const i = bySlug("solar");
  return (
    <>
      <section className="border-b border-line bg-surface"><Container className="py-12">
        <nav className="text-sm text-muted" aria-label="Breadcrumb"><a href="#industry-solar" className="hover:text-ink">Solar</a> / Texas</nav>
        <div className="mt-4 flex items-center gap-4"><IndustryIcon industry={i} size={56} /><h1 className="display font-display text-4xl font-extrabold">Solar referral partners in Texas</h1></div>
        <p className="mt-4 max-w-2xl text-lg text-muted"><strong className="text-ink">71% of Texas cities are claimed</strong> in the Solar Installers Network. Each city has one verified installer, with a backup ready if they can’t respond in time.</p>
      </Container></section>
      <Container className="py-12">
        <Card className="overflow-x-auto"><table className="w-full min-w-[640px] text-sm"><thead className="text-left text-muted"><tr>{["City", "Installer", "Trust", "Avg. response", ""].map(h => <th key={h} scope="col" className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
          <tbody>{texas.map((m, k) => { const s = seatStatus(m.city + m.st, i.slug, 0.71); return (
            <tr key={m.city} className="border-t border-line"><td className="px-4 py-3 font-medium">{m.city}</td>
              <td className="px-4 py-3">{s === "claimed" ? ["SunPath Solar", "Lone Star Sunworks", "Gulf Coast Solar Co.", "Prairie Power Solar", "Big Sky Solar"][k % 5] : s === "reserved" ? <Badge tone="orange">Reserved · founding member</Badge> : <Badge tone="success">Open</Badge>}</td>
              <td className="px-4 py-3">{s === "claimed" ? <TrustRing score={88 + (k % 9)} size={36} /> : "—"}</td><td className="px-4 py-3 tabular">{s === "claimed" ? `${9 + k * 3} min` : "—"}</td>
              <td className="px-4 py-3 text-right">{s === "open" ? <Button size="sm" href="#signup">Reserve</Button> : <Button size="sm" variant="ghost" href="#city">View</Button>}</td></tr>); })}</tbody></table></Card>
      </Container>
    </>
  );
}

export function CityPage2() {
  const i = bySlug("solar");
  return (
    <>
      <section className="border-b border-line bg-surface"><Container className="py-12">
        <nav className="text-sm text-muted" aria-label="Breadcrumb"><a href="#industry-solar" className="hover:text-ink">Solar</a> / <a href="#state-texas" className="hover:text-ink">Texas</a> / Austin</nav>
        <h1 className="display mt-4 font-display text-4xl font-extrabold">Trusted solar installer in Austin, TX</h1>
        <p className="mt-3 max-w-2xl text-lg text-muted">Austin’s seat in the Solar Installers Network is held by <strong className="text-ink">SunPath Solar</strong>, verified and rated by the installers who send them clients.</p>
      </Container></section>
      <Container className="grid gap-8 py-12 lg:grid-cols-[1fr_380px]">
        <div className="space-y-6">
          <Card className="p-6">
            <div className="flex flex-wrap items-center gap-4"><Avatar name="Ana Ruiz" size={56} tone="orange" /><div><div className="text-lg font-semibold">SunPath Solar</div><div className="text-muted">Ana Ruiz, owner · Austin and Travis County</div></div><div className="ml-auto"><TrustRing score={93} size={56} /></div></div>
            <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">{[[Star, "4.9", "62 client ratings"], [Users, "4.8", "41 peer ratings"], [Timer, "12 min", "avg. response"], [BadgeCheck, "Verified", "TX license"]].map(([Icon, a, b]) => { const I2 = Icon as typeof Star; return <div key={b as string} className="rounded-md bg-bg p-3"><I2 size={16} className="text-primary" /><div className="mt-1 font-display text-lg font-bold">{a as string}</div><div className="text-xs text-muted">{b as string}</div></div>; })}</div>
            <div className="mt-6 flex flex-wrap gap-3"><Button href="#send"><Send size={16} />Send a referral (members)</Button><Button variant="secondary" href="#pro">View profile</Button></div>
          </Card>
          <Card className="p-6"><h2 className="font-semibold">October city report card</h2>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">{[["Referrals received", "9"], ["Accepted on time", "100%"], ["Rank in Texas", "#2 of 41"]].map(([a, b]) => <div key={a} className="rounded-md border border-line p-4"><div className="text-sm text-muted">{a}</div><div className="font-display text-2xl font-bold">{b}</div></div>)}</div>
            <p className="mt-4 text-sm text-muted">Seats are earned: if a holder’s trust score stays low for 60 days, the top waitlisted installer can challenge for the seat.</p></Card>
          <Card className="p-6"><h2 className="font-semibold">Other pros in Austin</h2>
            <ul className="mt-4 grid gap-2 sm:grid-cols-2">{["real-estate", "mortgage", "roofing", "hvac", "insurance", "moving"].map((s, k) => { const x = bySlug(s); const st = seatStatus("Austin" + "TX", s); return <li key={s}><a href={`#industry-${s}`} className="flex items-center gap-3 rounded-md border border-line p-3 hover:border-primary"><IndustryIcon industry={x} size={30} /><span className="text-sm font-medium">{x.name}</span><span className="ml-auto">{st === "open" ? <Badge tone="success">Open</Badge> : st === "reserved" ? <Badge tone="orange">Reserved</Badge> : <Badge>Held{k === 0 ? " · 96" : ""}</Badge>}</span></a></li>; })}</ul></Card>
        </div>
        <aside className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">Need a solar installer in Austin?</h2><p className="mt-2 text-sm text-muted">Tell us what you need and SunPath Solar will contact you within the network’s response time.</p>
            <div className="mt-4 space-y-3">{["Your name", "Mobile", "Email"].map(l => <input key={l} aria-label={l} placeholder={l} className="h-11 w-full rounded-md border border-line bg-surface px-3" />)}<Button className="w-full" href="#request-done">Request a call back</Button></div>
            <p className="mt-3 text-xs text-muted">By requesting, you agree to be contacted by text and email about this request. Reply STOP to opt out.</p></Card>
          <Card className="p-6"><h2 className="font-semibold">Are you an installer near Austin?</h2><p className="mt-2 text-sm text-muted">Austin is held. Join the waitlist: it’s ranked by reputation, so you can earn the next open seat.</p><Button variant="secondary" className="mt-4 w-full" href="#signup">Join the waitlist</Button></Card>
        </aside>
      </Container>
    </>
  );
}

export { cap };
