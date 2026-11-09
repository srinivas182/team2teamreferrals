import { useState } from "react";
import { ArrowRight, Check, Search, ShieldCheck, Trophy, Timer, Send, Sparkles, MapPin } from "lucide-react";
import { Container, Button, Card, TempBadge, Badge, Select, money, cn } from "../ui/kit";
import { industries, families, totalClaimed, bySlug, type FamilyKey } from "../data/industries";
import { IndustryIcon } from "../ui/brand";
import { NationalMap, MapLegend } from "../ui/national";
import { CountUp } from "../ui/count";

/** Hero visual: a real referral moving from St. George to Houston (the product’s signature relay). */
function RelayCard() {
  return (
    <Card className="relative overflow-hidden p-5 shadow-lg sm:p-6">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-muted">Referral #4821</span>
        <TempBadge t="hot" />
      </div>
      <svg viewBox="0 0 320 96" className="mt-4 h-24 w-full" aria-hidden>
        <defs><linearGradient id="rg" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs>
        <path d="M30 70 C 110 -10, 210 -10, 290 62" fill="none" stroke="rgb(var(--line))" strokeWidth="2" strokeDasharray="2 6" />
        <path className="relay-path" d="M30 70 C 110 -10, 210 -10, 290 62" fill="none" stroke="url(#rg)" strokeWidth="3" strokeLinecap="round" />
        <circle cx="30" cy="70" r="7" fill="#2451F5" /><circle cx="290" cy="62" r="7" fill="#FF7A1A" className="pop" style={{ animationDelay: "1.7s", transformOrigin: "290px 62px" }} />
      </svg>
      <div className="-mt-2 flex justify-between text-sm">
        <div><div className="font-semibold">St. George, UT</div><div className="text-muted">Red Rock Realty Group</div></div>
        <div className="text-right"><div className="font-semibold">Houston, TX</div><div className="text-muted">Bayou City Homes</div></div>
      </div>
      <div className="mt-5 grid grid-cols-2 gap-3 rounded-md bg-bg p-4 text-sm">
        <div><div className="text-muted">Lead</div><div className="font-medium">Buyer, pre-approved</div></div>
        <div><div className="text-muted">Price range</div><div className="font-medium tabular">$450k–$520k</div></div>
        <div><div className="text-muted">Referral fee</div><div className="font-medium tabular">30%</div></div>
        <div><div className="text-muted">Est. referral value</div><div className="font-semibold tabular text-success">{money(3638)}</div></div>
      </div>
      <ol className="mt-5 space-y-3 text-sm">
        {[["Sent", "9:02 AM", "#2451F5"], ["Accepted by Chris Delgado", "9:16 AM · 14 min", "#FF7A1A"], ["First call with the buyer", "9:41 AM", "#FF7A1A"]].map(([a, b, c]) => (
          <li key={a} className="flex items-center gap-3"><span className="h-2.5 w-2.5 rounded-full" style={{ background: c }} /><span className="font-medium">{a}</span><span className="ml-auto tabular text-muted">{b}</span></li>
        ))}
      </ol>
    </Card>
  );
}


const popular = ["real-estate", "solar", "mortgage", "insurance", "roofing", "hvac", "financial-advisors", "moving"];

function IndustryPicker() {
  const [q, setQ] = useState("");
  const hits = q.trim() ? industries.filter(i => (i.name + " " + i.group).toLowerCase().includes(q.toLowerCase())).slice(0, 6) : [];
  return (
    <div className="relative mt-8 max-w-xl">
      <label className="sr-only" htmlFor="ind-search">Find your industry</label>
      <div className="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-2 shadow-md focus-within:border-primary sm:flex-nowrap">
        <Search size={20} className="ml-2 text-subtle" aria-hidden />
        <input id="ind-search" value={q} onChange={e => setQ(e.target.value)} placeholder="What do you do? Solar, mortgage, roofing…" className="h-11 min-w-0 flex-1 bg-transparent text-base outline-none placeholder:text-subtle" autoComplete="off" />
        <Button className="w-full sm:w-auto" href={hits[0] ? `#industry-${hits[0].slug}` : "#industry-real-estate"}>Find my network</Button>
      </div>
      {hits.length > 0 && (
        <ul className="absolute inset-x-0 z-10 mt-2 overflow-hidden rounded-xl border border-line bg-surface shadow-lg" role="listbox">
          {hits.map(i => <li key={i.slug}><a href={`#industry-${i.slug}`} className="flex items-center gap-3 px-4 py-3 hover:bg-bg"><IndustryIcon industry={i} size={32} /><span className="font-medium">{i.name}</span><span className="ml-auto text-sm text-muted">{i.group}</span></a></li>)}
        </ul>
      )}
      <div className="mt-4 flex flex-wrap gap-2">
        {popular.map(slug => { const i = bySlug(slug); return <a key={slug} href={`#industry-${slug}`} className="inline-flex items-center gap-2 rounded-full border border-line bg-surface py-1 pl-1 pr-3 text-sm font-medium hover:border-primary"><IndustryIcon industry={i} size={26} />{i.name}</a>; })}
        <a href="#industries" className="inline-flex items-center px-2 text-sm font-semibold text-primary">All 46 industries <ArrowRight size={14} /></a>
      </div>
    </div>
  );
}

const demoSteps = [["Sent", "0:00"], ["Seen by Ana Ruiz, SunPath Solar", "0:02"], ["Accepted", "0:14"], ["First call with the homeowner", "0:39"]] as const;
function SendDemo() {
  const [ind, setInd] = useState("solar"); const [temp, setTemp] = useState<"hot" | "warm" | "future">("hot"); const [sent, setSent] = useState(false); const i = bySlug(ind);
  return (
    <Card className="grid overflow-hidden lg:grid-cols-2">
      <div className="p-6 sm:p-8">
        <Badge tone="orange">Try it, no sign-up</Badge>
        <h3 className="display mt-3 font-display text-2xl font-bold">Send a referral in three taps</h3>
        <div className="mt-6 space-y-4">
          <label className="block"><span className="mb-1.5 block text-sm font-medium">Industry</span><Select value={ind} onChange={e => { setInd(e.target.value); setSent(false); }}>{industries.filter(x => x.tier !== "C").map(x => <option key={x.slug} value={x.slug}>{x.name}</option>)}</Select></label>
          <div><span className="mb-1.5 block text-sm font-medium">City</span><div className="flex h-11 items-center gap-2 rounded-md border border-line bg-bg px-3 text-[15px]"><MapPin size={16} className="text-muted" />Austin, TX <span className="ml-auto text-sm text-success">Seat held · trust 93</span></div></div>
          <div><span className="mb-1.5 block text-sm font-medium">How soon is the {i.client} ready?</span>
            <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Lead temperature">{(["hot", "warm", "future"] as const).map(t => <button key={t} role="radio" aria-checked={temp === t} onClick={() => setTemp(t)} className={cn("rounded-md border p-2", temp === t ? "border-primary ring-2 ring-primary/20" : "border-line")}><TempBadge t={t} /></button>)}</div></div>
          <Button className="w-full" size="lg" onClick={() => setSent(true)}><Send size={18} />Send to Austin</Button>
        </div>
      </div>
      <div className="border-t border-line bg-bg p-6 sm:p-8 lg:border-l lg:border-t-0">
        {!sent ? (
          <div className="flex h-full flex-col items-center justify-center text-center text-muted"><Sparkles className="text-primary" /><p className="mt-3 max-w-xs">Press send to watch a referral travel to a verified {i.pro} in Austin, with every step tracked.</p></div>
        ) : (
          <div>
            <svg viewBox="0 0 320 90" className="h-20 w-full" aria-hidden><path className="relay-path" d="M24 70 C 110 -6, 210 -6, 296 62" fill="none" stroke="url(#rg2)" strokeWidth="3" strokeLinecap="round" /><defs><linearGradient id="rg2" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs><circle cx="24" cy="70" r="7" fill="#2451F5" /><circle cx="296" cy="62" r="7" fill="#FF7A1A" className="pop" style={{ animationDelay: "1.6s", transformOrigin: "296px 62px" }} /></svg>
            <ol className="mt-4 space-y-3">{demoSteps.map(([a, b], k) => <li key={a} className="pop flex items-center gap-3 text-sm" style={{ animationDelay: `${0.5 + k * 0.45}s` }}><span className="flex h-6 w-6 items-center justify-center rounded-full bg-success text-white"><Check size={14} /></span><span className="font-medium">{a}</span><span className="ml-auto tabular text-muted">{b}</span></li>)}</ol>
            <p className="mt-5 rounded-md bg-surface p-3 text-sm text-muted">In the real product you’d see every status change live, message the {i.pro}, and get a closing report. Minutes shown are typical for this network.</p>
            <Button className="mt-4 w-full" variant="secondary" href="#signup">Claim my city to send real referrals</Button>
          </div>
        )}
      </div>
    </Card>
  );
}

const states = [["Texas", 71], ["Florida", 68], ["Arizona", 64], ["California", 58], ["Georgia", 55], ["Utah", 52]] as const;

export default function Home() {
  const [mapInd, setMapInd] = useState("real-estate");
  const fams = Object.keys(families) as FamilyKey[];
  return (
    <>
      <section className="relative overflow-hidden border-b border-line bg-surface">
        <div className="pointer-events-none absolute -right-40 -top-40 h-[520px] w-[520px] rounded-full opacity-[.10]" style={{ background: "radial-gradient(circle,#2451F5,transparent 65%)" }} />
        <Container className="grid gap-12 py-14 sm:py-20 lg:grid-cols-[1.15fr_1fr] lg:items-center">
          <div className="min-w-0">
            <span className="inline-flex flex-wrap items-center gap-2 rounded-2xl bg-cobalt-50 px-3 py-1 text-sm font-semibold text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200">46 industries · every US city · one verified pro per city</span>
            <h1 className="display mt-5 break-words font-display text-[38px] font-extrabold leading-[1.05] sm:text-[56px] lg:text-[64px]">Every out-of-area client, handed to a pro you trust.</h1>
            <p className="mt-6 max-w-xl text-lg text-muted">Licensed and verified professionals hold one seat per city in their industry’s network. Send a client anywhere in the country in seconds, track every step, and get the credit you’re owed.</p>
            <IndustryPicker />
          </div>
          <RelayCard />
        </Container>
      </section>

      <section className="border-b border-line">
        <Container className="grid grid-cols-2 gap-6 py-10 sm:grid-cols-4">
          {[[<CountUp key="a" to={totalClaimed} />, "city seats claimed"], [<CountUp key="b" to={46} />, "industries, one network each"], [<span key="c" className="tabular">16 min</span>, "average time to accept"], [<span key="d">$0</span>, "for any month without a referral"]].map(([v, l], k) => (
            <div key={k}><div className="font-display text-3xl font-extrabold sm:text-4xl">{v}</div><div className="mt-1 text-sm text-muted">{l as string}</div></div>))}
        </Container>
      </section>

      <section className="py-20">
        <Container>
          <div className="flex flex-wrap items-end gap-4">
            <div className="max-w-2xl"><h2 className="display font-display text-3xl font-bold sm:text-4xl">Cities are being claimed right now</h2><p className="mt-3 text-muted">Each city has one seat per industry. When it’s gone, the next pro joins the waitlist, ranked by reputation, not sign-up order.</p></div>
            <div className="ml-auto w-full sm:w-64"><label className="text-sm font-medium" htmlFor="map-ind">Industry</label><Select id="map-ind" value={mapInd} onChange={e => setMapInd(e.target.value)}>{industries.map(i => <option key={i.slug} value={i.slug}>{i.name}</option>)}</Select></div>
          </div>
          <div className="mt-8 grid gap-6 lg:grid-cols-[1fr_300px]">
            <Card className="p-4 sm:p-6"><NationalMap industry={mapInd} /><div className="mt-3"><MapLegend /></div></Card>
            <Card className="p-6">
              <h3 className="flex items-center gap-2 font-semibold"><Trophy size={18} className="text-orange-500" />Most claimed states</h3>
              <ul className="mt-4 space-y-4">{states.map(([s, p]) => <li key={s}><div className="flex justify-between text-sm"><span className="font-medium">{s}</span><span className="tabular text-muted">{p}% claimed</span></div><div className="mt-1.5 h-2 rounded-full bg-bg"><div className="h-2 rounded-full" style={{ width: `${p}%`, background: "linear-gradient(90deg,#2451F5,#FF7A1A)" }} /></div></li>)}</ul>
              <Button className="mt-6 w-full" href={`#industry-${mapInd}`}>See open cities in {bySlug(mapInd).name.toLowerCase()}</Button>
            </Card>
          </div>
        </Container>
      </section>

      <section id="industries" className="border-y border-line bg-surface py-20">
        <Container>
          <h2 className="display max-w-2xl font-display text-3xl font-bold sm:text-4xl">One trusted network for every licensed, local profession</h2>
          <div className="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            {fams.map(f => (
              <div key={f}>
                <h3 className="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide" style={{ color: families[f].accent }}>{families[f].name}</h3>
                <ul className="mt-3 space-y-1">{industries.filter(i => i.family === f).map(i => <li key={i.slug}><a href={`#industry-${i.slug}`} className="flex items-center gap-3 rounded-md p-1.5 hover:bg-bg"><IndustryIcon industry={i} size={30} /><span className="text-sm font-medium">{i.name}</span></a></li>)}</ul>
              </div>))}
          </div>
        </Container>
      </section>

      <section className="py-20"><Container><SendDemo /></Container></section>

      <section className="pb-20">
        <Container className="grid gap-6 lg:grid-cols-2">
          <Card className="p-8">
            <ShieldCheck className="text-success" size={28} />
            <h2 className="display mt-4 font-display text-2xl font-bold">No referrals, no charge</h2>
            <p className="mt-3 text-muted">Every seat starts with 3 months free. After that, any month in which no referral reaches you is free, automatically. You only ever pay for a network that’s working for you.</p>
            <Button className="mt-6" variant="secondary" href="#pricing">See pricing</Button>
          </Card>
          <Card className="p-8">
            <Timer className="text-primary" size={28} />
            <h2 className="display mt-4 font-display text-2xl font-bold">Seats are earned, not owned</h2>
            <p className="mt-3 text-muted">Every month, each pro gets a city report card: response time, conversion and rank. Fast, reliable pros keep their city. Slow ones can be challenged by the best pro on the waitlist.</p>
            <div className="mt-6 grid grid-cols-3 gap-3 text-center text-sm">{[["11 min", "response"], ["38%", "conversion"], ["#2", "in Texas"]].map(([a, b]) => <div key={b} className="rounded-md bg-bg p-3"><div className="font-display text-xl font-bold">{a}</div><div className="text-muted">{b}</div></div>)}</div>
          </Card>
        </Container>
      </section>

      <section className="pb-8">
        <Container>
          <div className="overflow-hidden rounded-2xl bg-navy-900 p-10 text-white sm:p-14" style={{ backgroundImage: "radial-gradient(circle at 85% 20%,rgba(255,122,26,.35),transparent 45%),radial-gradient(circle at 10% 90%,rgba(36,81,245,.45),transparent 50%)" }}>
            <h2 className="display max-w-2xl font-display text-3xl font-extrabold sm:text-5xl">Your city is open today. It may not be tomorrow.</h2>
            <p className="mt-4 max-w-xl text-[#C9D2EA]">Reserve your seat now as a founding member and lock in founding pricing for as long as you hold it.</p>
            <div className="mt-8 flex flex-wrap gap-3"><Button size="lg" variant="accent" href="#signup">Reserve my city <ArrowRight size={18} /></Button><Button size="lg" variant="secondary" href="#how-it-works">How it works</Button></div>
          </div>
        </Container>
      </section>
    </>
  );
}
