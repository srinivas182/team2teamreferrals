import { useState } from "react";
import { ArrowRight, BadgeCheck, Building2, Check, ChevronRight, CreditCard, Gift, List, Map as MapIcon, MapPin, QrCode, Search, Send, ShieldCheck, Star, Timer, User, Users, Plus, Mail } from "lucide-react";
import { Container, Button, Card, Badge, TrustRing, Avatar, Input, Select, Logo, money, cn } from "../ui/kit";
import { Segmented } from "../ui/data";
import { Shell } from "../app/Shell";
import { bySlug, metros, seatStatus, industries } from "../data/industries";
import { IndustryIcon } from "../ui/brand";
import { NationalMap, MapLegend } from "../ui/national";

const solar = bySlug("solar");
const leaders = ["Ana Ruiz", "Cole Brennan", "Mia Tran", "Dev Patel", "Rosa Lin", "Marcus Hall", "Ivy Chen", "Noah Grant", "Sara Kim", "Eli Woods"];
const suffix = ["Solar", "Sun Works", "Energy", "Solar Co.", "Rooftop Solar"];
const seats = metros.slice(0, 40).map((m, k) => ({ ...m, status: seatStatus(m.city + m.st, "solar"), team: k === 9 ? "SunPath Solar" : `${m.city.split(" ")[0]} ${suffix[k % 5]}`, leader: leaders[k % 10]!, trust: 84 + (k * 7) % 15, response: 8 + (k * 5) % 30, rating: (4.5 + ((k * 3) % 5) / 10).toFixed(1) }));

/* ---------------- Group page ---------------- */
export function GroupPage2() {
  const [view, setView] = useState<"list" | "map">("list"); const [q, setQ] = useState("");
  const order = { claimed: 0, reserved: 1, open: 2 } as const;
  const shown = seats.filter(s => (s.city + s.st + s.team).toLowerCase().includes(q.toLowerCase())).sort((a, b) => order[a.status] - order[b.status]);
  return (
    <>
      <section className="border-b border-line bg-surface"><Container className="py-12">
        <nav className="text-sm text-muted" aria-label="Breadcrumb"><a href="#industry-solar" className="hover:text-ink">Solar</a> / Network</nav>
        <div className="mt-4 flex flex-wrap items-center gap-4"><IndustryIcon industry={solar} size={60} /><div><h1 className="display font-display text-4xl font-extrabold">Solar Installers Network</h1><p className="text-muted">by Team to Team · owner: Rosa Lin, Desert Bloom Energy</p></div><Button className="ml-auto" href="#claim">Reserve your city <ArrowRight size={16} /></Button></div>
        <div className="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-5">{[["618", "installers"], ["44", "states"], ["2,940", "referrals this year"], ["14 min", "avg. accept"], ["96%", "updates on time"]].map(([a, b]) => <div key={b}><div className="font-display text-2xl font-bold">{a}</div><div className="text-sm text-muted">{b}</div></div>)}</div>
      </Container></section>
      <Container className="grid gap-8 py-10 lg:grid-cols-[1fr_320px]">
        <div>
          <div className="flex flex-wrap items-center gap-3">
            <div className="flex h-11 flex-1 items-center gap-2 rounded-md border border-line bg-surface px-3"><Search size={16} className="text-subtle" /><input aria-label="Search cities or installers" value={q} onChange={e => setQ(e.target.value)} placeholder="Search a city or installer" className="min-w-0 flex-1 bg-transparent outline-none" /></div>
            <Segmented label="View" value={view} onChange={setView} options={[["list", "List"], ["map", "Map"]]} />
          </div>
          {view === "map" ? <Card className="mt-4 p-4"><NationalMap industry="solar" /><div className="mt-2"><MapLegend /></div></Card> : (
            <ul className="mt-4 space-y-3">{shown.slice(0, 14).map(s => (
              <li key={s.city + s.st}><Card className="flex flex-wrap items-center gap-4 p-4">
                {s.status === "claimed" ? <><Avatar name={s.leader} tone="orange" /><div className="min-w-0"><a href="#pro" className="font-semibold hover:text-primary">{s.team}</a><div className="text-sm text-muted"><MapPin size={13} className="mr-1 inline" />{s.city}, {s.st} · {s.leader}</div></div>
                  <div className="ml-auto flex items-center gap-5 text-sm"><span className="hidden sm:inline"><Star size={14} className="mr-1 inline text-warning" />{s.rating}</span><span className="hidden tabular text-muted sm:inline">{s.response} min</span><TrustRing score={s.trust} size={38} /><Button size="sm" href="#send"><Send size={14} />Send referral</Button></div></>
                : <><span className="flex h-9 w-9 items-center justify-center rounded-full bg-bg"><MapPin size={16} className="text-success" /></span><div><div className="font-semibold">{s.city}, {s.st}</div><div className="text-sm text-muted">{s.status === "reserved" ? "Reserved by a founding member · opens at launch" : "No installer yet. Referrals here go to the open request board."}</div></div>
                  <div className="ml-auto">{s.status === "open" ? <Button size="sm" variant="secondary" href="#claim">Reserve this city</Button> : <Badge tone="orange">Reserved</Badge>}</div></>}
              </Card></li>))}</ul>)}
        </div>
        <aside className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">Network rules</h2><ul className="mt-4 space-y-3 text-sm">{[["Accept or decline", "within 24 h"], ["First contact", "within 24 h"], ["Updates", "every 7 days"], ["Seat challenge", "after 60 days below trust 70"], ["Company cap", "25% of a state's seats"]].map(([a, b]) => <li key={a} className="flex justify-between gap-3"><span className="text-muted">{a}</span><span className="text-right font-medium">{b}</span></li>)}</ul></Card>
          <Card className="p-6"><h2 className="font-semibold">Top installers this month</h2><ol className="mt-4 space-y-3">{seats.filter(s => s.status === "claimed").slice(0, 5).map((s, k) => <li key={s.city} className="flex items-center gap-3 text-sm"><span className="w-5 font-display font-bold text-muted">{k + 1}</span><Avatar name={s.leader} size={30} tone="orange" /><div className="min-w-0"><div className="truncate font-medium">{s.team}</div><div className="text-muted">{s.city}</div></div><TrustRing score={s.trust} size={32} /></li>)}</ol></Card>
        </aside>
      </Container>
    </>
  );
}

/* ---------------- Team profile ---------------- */
export function ProfilePage2() {
  return (
    <Container className="grid gap-8 py-10 lg:grid-cols-[1fr_340px]">
      <div className="space-y-6">
        <Card className="overflow-hidden">
          <div className="h-28" style={{ background: "linear-gradient(120deg,#2451F5,#FF7A1A)" }} />
          <div className="px-6 pb-6">
            <span className="-mt-10 inline-block rounded-full ring-4 ring-surface"><Avatar name="Ana Ruiz" size={84} tone="orange" /></span>
            <div className="mt-3 flex flex-wrap items-start gap-4">
              <div className="min-w-0"><h1 className="font-display text-3xl font-extrabold">SunPath Solar</h1><p className="text-muted">Ana Ruiz, owner · Austin seat, Solar Installers Network</p></div>
              <div className="flex flex-wrap gap-2 sm:ml-auto"><Badge tone="success"><BadgeCheck size={13} />TX license verified</Badge><Badge tone="cobalt">Founding member</Badge></div>
            </div>
          </div>
        </Card>
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">{[[Star, "4.9", "client rating · 62"], [Users, "4.8", "peer rating · 41"], [Timer, "12 min", "avg. response"], [ShieldCheck, "96%", "updates on time"]].map(([I, a, b]) => { const Ic = I as typeof Star; return <Card key={b as string} className="p-4"><Ic size={18} className="text-primary" /><div className="mt-2 font-display text-2xl font-bold">{a as string}</div><div className="text-sm text-muted">{b as string}</div></Card>; })}</div>
        <Card className="p-6"><h2 className="font-semibold">City report card · last 6 months</h2>
          <div className="mt-4 flex h-32 items-end gap-3">{[72, 78, 85, 88, 91, 93].map((v, k) => <div key={k} className="flex flex-1 flex-col items-center gap-2"><div className="w-full rounded-t-md" style={{ height: `${(v - 60) * 3}px`, background: "linear-gradient(180deg,#2451F5,#6E8CFF)" }} /><span className="text-xs text-muted">{["May", "Jun", "Jul", "Aug", "Sep", "Oct"][k]}</span></div>)}</div>
          <p className="mt-3 text-sm text-muted">Trust score rose from 72 to 93. Rank #2 of 41 installers in Texas.</p></Card>
        <Card className="p-6"><div className="flex items-center gap-3"><h2 className="font-semibold">What members and clients say</h2><span className="ml-auto text-sm text-muted">Only from verified referrals</span></div>
          <ul className="mt-4 divide-y divide-line">{[["Peer", "Cole Brennan, Lone Star Sunworks", "Accepted my Austin referral in 9 minutes and closed it in five weeks. Updates every few days without me asking.", 5], ["Client", "Homeowner, Round Rock", "Clear quote, no pressure, install done on the date they promised.", 5], ["Peer", "Mia Tran, Gulf Coast Solar Co.", "Professional with my client and paid the referral fee the week it closed.", 5]].map(([t, w, q, s]) => (
            <li key={w as string} className="py-4"><div className="flex flex-wrap items-center gap-2"><Badge tone={t === "Peer" ? "cobalt" : "orange"}>{t as string}</Badge><span className="text-sm font-medium">{w as string}</span><span className="ml-auto text-warning" aria-label={`${s} out of 5 stars`}>{"★".repeat(s as number)}</span></div><p className="mt-2 text-muted">“{q as string}”</p></li>))}</ul></Card>
      </div>
      <aside className="space-y-6">
        <Card className="p-6"><h2 className="font-semibold">Send SunPath a client</h2><p className="mt-2 text-sm text-muted">Austin and Travis County, TX. Residential and small commercial.</p><Button className="mt-4 w-full" href="#send"><Send size={16} />Send a referral</Button><Button variant="secondary" className="mt-2 w-full" href="#signup">Not a member? Refer and track it</Button></Card>
        <Card className="flex items-center gap-4 p-6"><span className="flex h-20 w-20 items-center justify-center rounded-lg border border-line"><QrCode size={56} /></span><div><h3 className="font-semibold">“Refer to me” code</h3><p className="text-sm text-muted">For business cards and email signatures.</p></div></Card>
        <Card className="p-6"><h3 className="font-semibold">Also in</h3><ul className="mt-3 space-y-2 text-sm">{[["roofing", "Roofing Contractors Network · Austin"]].map(([s, t]) => <li key={s} className="flex items-center gap-3"><IndustryIcon industry={bySlug(s!)} size={28} />{t}</li>)}</ul></Card>
      </aside>
    </Container>
  );
}

/* ---------------- Claim flow ---------------- */
const steps = ["Who's claiming", "Industry and cities", "License check", "Confirm"];
export function ClaimFlow() {
  const [step, setStep] = useState(0); const [kind, setKind] = useState<"team" | "company">("team"); const [ind, setInd] = useState("solar");
  const [cities, setCities] = useState<string[]>(["Austin, TX"]); const [payer, setPayer] = useState<Record<string, "company" | "city">>({});
  const i = bySlug(ind); const options = metros.filter(m => m.st === "TX" || m.st === "OK" || m.st === "NM").map(m => `${m.city}, ${m.st}`);
  const toggle = (c: string) => setCities(cs => cs.includes(c) ? cs.filter(x => x !== c) : kind === "team" && cs.length >= 3 ? cs : [...cs, c]);
  return (
    <div className="min-h-screen bg-bg">
      <Container className="max-w-3xl py-10">
        <a href="#home" aria-label="Team to Team Referrals home"><Logo className="h-8 w-auto" /></a>
        <ol className="mt-6 flex gap-2" aria-label="Progress">{steps.map((s, k) => <li key={s} className="flex-1"><div className={cn("h-1.5 rounded-full", k <= step ? "bg-primary" : "bg-line")} /><span className={cn("mt-2 hidden text-xs sm:block", k === step ? "font-semibold text-ink" : "text-muted")}>{s}</span></li>)}</ol>
        <Card className="mt-6 p-6 sm:p-8">
          {step === 0 && <>
            <h1 className="font-display text-2xl font-bold">Who are you claiming for?</h1>
            <div className="mt-6 grid gap-4 sm:grid-cols-2">{([["team", User, "My team", "A solo pro or one team, claiming 1–3 cities."], ["company", Building2, "A company with several cities", "Branches in many cities. Add a city leader for each, choose who pays."]] as const).map(([k, I, t, d]) => (
              <button key={k} onClick={() => setKind(k)} className={cn("rounded-lg border p-5 text-left", kind === k ? "border-primary ring-2 ring-primary/20" : "border-line hover:border-primary/50")} aria-pressed={kind === k}><I className="text-primary" /><div className="mt-3 font-semibold">{t}</div><p className="mt-1 text-sm text-muted">{d}</p></button>))}</div>
          </>}
          {step === 1 && <>
            <h1 className="font-display text-2xl font-bold">Choose your industry and cities</h1>
            <label className="mt-6 block"><span className="mb-1.5 block text-sm font-medium">Industry</span><Select value={ind} onChange={e => setInd(e.target.value)}>{industries.map(x => <option key={x.slug} value={x.slug}>{x.name}</option>)}</Select></label>
            <p className="mt-6 text-sm font-medium">Cities {kind === "team" && <span className="font-normal text-muted">(up to 3)</span>}</p>
            <div className="mt-2 grid gap-2 sm:grid-cols-2">{options.map(c => { const st = seatStatus(c.replace(", ", ""), ind, 0.35); const taken = st !== "open" && c !== "Austin, TX"; return (
              <button key={c} disabled={taken} onClick={() => toggle(c)} className={cn("flex items-center gap-3 rounded-md border p-3 text-left text-sm disabled:cursor-not-allowed disabled:opacity-60", cities.includes(c) ? "border-primary bg-cobalt-50 dark:bg-cobalt-500/10" : "border-line")} aria-pressed={cities.includes(c)}>
                <span className={cn("flex h-5 w-5 items-center justify-center rounded border", cities.includes(c) ? "border-primary bg-primary text-white" : "border-line")}>{cities.includes(c) && <Check size={13} />}</span>{c}<span className="ml-auto">{taken ? <Badge>{st === "reserved" ? "Reserved" : "Held"} · waitlist</Badge> : <Badge tone="success">Open</Badge>}</span></button>); })}</div>
            {kind === "company" && cities.length > 0 && <div className="mt-6"><p className="text-sm font-medium">Who pays for each seat?</p><ul className="mt-2 space-y-2">{cities.map(c => <li key={c} className="flex flex-wrap items-center gap-3 rounded-md border border-line p-3 text-sm"><span className="font-medium">{c}</span><div className="ml-auto"><Segmented label={`Payer for ${c}`} value={payer[c] ?? "company"} onChange={v => setPayer(p => ({ ...p, [c]: v }))} options={[["company", "Company pays"], ["city", "City team pays"]]} /></div><Input aria-label={`City leader email for ${c}`} placeholder="City leader email (optional)" className="h-9 sm:w-64" /></li>)}</ul><p className="mt-2 text-xs text-muted">One company can hold up to 25% of a state's seats in this network.</p></div>}
          </>}
          {step === 2 && <>
            <h1 className="font-display text-2xl font-bold">Confirm your license</h1><p className="mt-2 text-muted">{i.name} requires a verified license in each state where you hold a seat.</p>
            <div className="mt-6 grid gap-4 sm:grid-cols-2"><label className="block"><span className="mb-1.5 block text-sm font-medium">State</span><Select defaultValue="TX"><option>TX</option><option>OK</option><option>NM</option></Select></label><label className="block"><span className="mb-1.5 block text-sm font-medium">License number</span><Input placeholder="e.g. TECL 12345" /></label></div>
            <div className="mt-4 flex items-start gap-3 rounded-md bg-bg p-4 text-sm"><ShieldCheck size={18} className="shrink-0 text-success" /><p className="text-muted">We check this against the state’s public license lookup, usually within one business day. You can keep setting up while we verify.</p></div>
          </>}
          {step === 3 && <>
            <h1 className="font-display text-2xl font-bold">You’re about to reserve {cities.length} {cities.length === 1 ? "city" : "cities"}</h1>
            <ul className="mt-6 divide-y divide-line rounded-lg border border-line">{cities.map(c => <li key={c} className="flex flex-wrap items-center gap-3 p-4 text-sm"><IndustryIcon industry={i} size={30} /><span className="font-medium">{c}</span><span className="text-muted">{i.group}</span><span className="ml-auto">{kind === "company" && <Badge>{(payer[c] ?? "company") === "company" ? "Company pays" : "City team pays"}</Badge>} <Badge tone="success">Free until Feb 9, 2027</Badge></span></li>)}</ul>
            <div className="mt-6 grid gap-3 sm:grid-cols-2"><div className="flex gap-3 rounded-md bg-bg p-4 text-sm"><Gift size={18} className="shrink-0 text-orange-500" /><span>Founding member price locked: <strong>{money(79)}</strong> per seat per month after the trial, for as long as you hold the seat.</span></div><div className="flex gap-3 rounded-md bg-bg p-4 text-sm"><ShieldCheck size={18} className="shrink-0 text-success" /><span>Any month no referral reaches a seat is free, automatically.</span></div></div>
          </>}
          <div className="mt-8 flex justify-between gap-3">{step > 0 ? <Button variant="ghost" onClick={() => setStep(step - 1)}>Back</Button> : <span />}{step < 3 ? <Button onClick={() => setStep(step + 1)} disabled={step === 1 && cities.length === 0}>Continue <ChevronRight size={16} /></Button> : <Button href={kind === "company" ? "#company" : "#welcome"}>Reserve {cities.length === 1 ? "my city" : "my cities"}</Button>}</div>
        </Card>
      </Container>
    </div>
  );
}

/* ---------------- Company dashboard ---------------- */
const companySeats = [["Austin, TX", "Ana Ruiz", "company", "Feb 9, 2027", 9], ["San Antonio, TX", "Luis Ortega", "company", "Feb 9, 2027", 6], ["Houston, TX", "Mia Tran", "city", "Feb 12, 2027", 11], ["Dallas, TX", "Invite sent", "company", "Feb 15, 2027", 0], ["Oklahoma City, OK", "Dev Patel", "city", "Mar 2, 2027", 3], ["Tulsa, OK", "Sara Kim", "company", "Mar 2, 2027", 0], ["Albuquerque, NM", "Noah Grant", "company", "Mar 9, 2027", 4]] as const;
export function CompanyDashboard() {
  return (
    <Shell current="company" title="Company view · SunPath Solar (sample)" actions={<><Button variant="secondary" href="#claim"><Plus size={16} />Add cities</Button><Button><Mail size={16} />Invite city leader</Button></>}>
      <div className="grid gap-4 sm:grid-cols-4">{[["7", "city seats"], ["5", "on trial"], ["33", "referrals received this month"], ["2", "paid by city teams"]].map(([a, b]) => <Card key={b} className="p-5"><div className="font-display text-3xl font-bold">{a}</div><div className="text-sm text-muted">{b}</div></Card>)}</div>
      <Card className="mt-6 overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="text-left text-muted"><tr>{["City", "City leader", "Who pays", "Trial ends", "Referrals (Oct)", "Status"].map(h => <th key={h} scope="col" className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
        <tbody>{companySeats.map(([c, l, p, t, r]) => <tr key={c} className="border-t border-line"><td className="px-4 py-3 font-medium">{c}</td><td className="px-4 py-3">{l === "Invite sent" ? <span className="text-muted">Invite sent · resend</span> : <span className="flex items-center gap-2"><Avatar name={l} size={26} tone="orange" />{l}</span>}</td><td className="px-4 py-3"><Select defaultValue={p} aria-label={`Payer for ${c}`} className="h-9 w-40"><option value="company">Company</option><option value="city">City team</option></Select></td><td className="px-4 py-3 tabular">{t}</td><td className="px-4 py-3 tabular">{r}</td><td className="px-4 py-3">{r === 0 ? <Badge tone="warning">Quiet · free if it stays at 0</Badge> : <Badge tone="success">Active</Badge>}</td></tr>)}</tbody></table></Card>
      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card className="p-6"><h2 className="font-semibold">State caps in this network</h2><p className="mt-1 text-sm text-muted">One company can hold up to 25% of a state’s seats.</p><ul className="mt-4 space-y-4">{[["Texas", 4, 41], ["Oklahoma", 2, 14], ["New Mexico", 1, 9]].map(([s, h, t]) => { const pct = Math.round(((h as number) / (t as number)) * 100); return <li key={s as string}><div className="flex justify-between text-sm"><span className="font-medium">{s as string}</span><span className="tabular text-muted">{h as number} of {t as number} seats · {pct}% (cap 25%)</span></div><div className="mt-1.5 h-2 rounded-full bg-bg"><div className="h-2 rounded-full bg-primary" style={{ width: `${pct * 4}%` }} /></div></li>; })}</ul></Card>
        <Card className="p-6"><h2 className="font-semibold">Route referrals between your own branches</h2><p className="mt-2 text-sm text-muted">A private network lets your branches pass clients to each other with the same tracking, agreements and reports, without using your public seats.</p><Button className="mt-4" variant="secondary">See private network plans</Button></Card>
      </div>
    </Shell>
  );
}

/* ---------------- Billing ---------------- */
export function Billing2() {
  return (
    <Shell current="billing" title="Billing">
      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">Your seats</h2>
            <ul className="mt-4 divide-y divide-line">{[["real-estate", "St. George, UT", "Trial · free until Feb 9, 2027", "success"], ["real-estate", "Hurricane, UT", "Free in October · no referrals offered", "success"], ["property-management", "St. George, UT", `${money(79)}/mo · next charge Dec 1`, "neutral"]].map(([s, c, t, tone]) => <li key={s! + c} className="flex flex-wrap items-center gap-3 py-3 text-sm"><IndustryIcon industry={bySlug(s!)} size={32} /><div><div className="font-medium">{bySlug(s!).name} · {c}</div><div className="text-muted">{bySlug(s!).group}</div></div><Badge tone={tone as "success" | "neutral"} className="ml-auto">{t}</Badge></li>)}</ul>
            <Button variant="secondary" size="sm" className="mt-4" href="#claim"><Plus size={15} />Reserve another city</Button></Card>
          <Card className="p-6"><h2 className="font-semibold">Free months credited</h2><p className="mt-1 text-sm text-muted">When no referral reaches a seat in a month, that month is on us. Automatically.</p>
            <ol className="mt-4 space-y-3 text-sm">{[["Oct 2026", "Hurricane seat", "No referrals offered", money(79)], ["Aug 2026", "Hurricane seat", "No referrals offered", money(79)]].map(([m, s, w, v]) => <li key={m} className="flex items-center gap-3"><span className="flex h-8 w-8 items-center justify-center rounded-full bg-[#ECFDF3] text-success dark:bg-success/20"><Gift size={15} /></span><div><div className="font-medium">{m} · {s}</div><div className="text-muted">{w}</div></div><span className="ml-auto font-semibold tabular text-success">−{v}</span></li>)}</ol></Card>
        </div>
        <div className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">Next invoice</h2><div className="mt-3 font-display text-3xl font-extrabold tabular">{money(79)}</div><p className="text-sm text-muted">Dec 1, 2026 · 1 paid seat · 2 seats free</p>
            <div className="mt-4 flex items-center gap-3 rounded-md border border-line p-4"><CreditCard className="text-muted" /><div><div className="font-medium">Visa ending 4417</div><div className="text-sm text-muted">Expires 09/28</div></div><Button size="sm" variant="ghost" className="ml-auto">Update</Button></div></Card>
          <Card className="p-6"><h2 className="font-semibold">Save with annual billing</h2><p className="mt-2 text-sm text-muted">Pay yearly and get 2 months free on every paid seat. Your value guarantee still applies.</p><Button className="mt-4" variant="secondary">Switch to annual</Button></Card>
          <Card className="p-6"><h2 className="font-semibold">Invoices</h2><ul className="mt-3 space-y-2 text-sm">{[["Nov 1, 2026", 79], ["Oct 1, 2026", 0], ["Sep 1, 2026", 79]].map(([d, v]) => <li key={d as string} className="flex justify-between"><span>{d as string}</span><span className="tabular">{money(v as number)} · <a href="#billing" className="text-primary">PDF</a></span></li>)}</ul></Card>
        </div>
      </div>
    </Shell>
  );
}
