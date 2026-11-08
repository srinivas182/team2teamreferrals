import { useMemo, useState } from "react";
import { Check, Search, Users, MapPin, Lock, Flame, Sun, Sprout } from "lucide-react";
import { Container, Button, Card, Badge, Input, Select, money, cn } from "../ui/kit";
import { groups } from "../data/mock";

function PageHead({ title, sub }: { title: string; sub: string }) {
  return (
    <section className="border-b border-line bg-surface">
      <Container className="py-14 sm:py-16">
        <h1 className="display max-w-3xl font-display text-4xl font-extrabold sm:text-5xl">{title}</h1>
        <p className="mt-4 max-w-2xl text-lg text-muted">{sub}</p>
      </Container>
    </section>
  );
}

export function HowItWorks() {
  const steps = [
    ["Join and verify", "Create your team profile and add your license for each state. Our team checks it against the state record, usually within one business day.", ["License number and brokerage", "Broker of record", "W-9 so fees reach you fast"]],
    ["Claim your city in a group", "Groups are networks of teams run by a group owner. Each group has one seat per city. Claim an open seat, or join the waitlist.", ["Group owner reviews you", "Short training video", "Your seat goes live"]],
    ["Send a referral", "Pick the city. Add the lead and mark it Hot, Warm or Future so the receiving team knows what it’s worth before they accept.", ["Lead agrees to be referred", "Fee pre-filled at the group rate", "Duplicate check protects you"]],
    ["They accept and sign", "Acceptance signs the referral agreement for both brokerages. Then the lead’s details unlock for the receiving team.", ["Accept deadline set by the group", "Missed deadline goes to the backup team", "Agreement PDF for both sides"]],
    ["Follow every step", "Calls, showings, offers and notes appear on your timeline as they happen. Message the team on the referral thread.", ["Updates required weekly", "Future leads get monthly check-ins", "Your client can see progress too"]],
    ["Close and get paid", "The receiving team files a closing report with the price and your fee. Their brokerage pays yours, and you confirm when it arrives.", ["Fee tracked to the dollar", "Overdue fees affect their trust score", "Year-end earnings export"]],
  ] as const;
  const temps = [
    [Flame, "Hot", "Acting within 30 days — pre-approved, actively searching or ready to list.", "text-hot"],
    [Sun, "Warm", "Acting in 1 to 3 months.", "text-warm"],
    [Sprout, "Future", "Acting in 3 to 12+ months. Nurtured with monthly check-ins so it’s never forgotten.", "text-future"],
  ] as const;
  return (
    <>
      <PageHead title="How Team to Team works" sub="From the moment an out-of-area lead calls you to the day your referral fee arrives." />
      <Container className="py-16">
        <ol className="relative space-y-10 border-l-2 border-line pl-8">
          {steps.map(([t, d, pts], i) => (
            <li key={t} className="relative">
              <span className="absolute -left-[45px] flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-bold text-on-primary">{i + 1}</span>
              <h2 className="text-xl font-semibold">{t}</h2>
              <p className="mt-2 max-w-2xl text-muted">{d}</p>
              <ul className="mt-3 flex flex-wrap gap-2">{pts.map(p => <li key={p}><Badge>{p}</Badge></li>)}</ul>
            </li>
          ))}
        </ol>
        <h2 className="display mt-20 font-display text-3xl font-bold">Lead temperature tells the other team what a lead is worth</h2>
        <div className="mt-8 grid gap-4 md:grid-cols-3">
          {temps.map(([I, t, d, c]) => (
            <Card key={t} className="p-6"><I className={c} /><h3 className="mt-3 text-lg font-semibold">{t}</h3><p className="mt-1 text-muted">{d}</p></Card>
          ))}
        </div>
      </Container>
    </>
  );
}

export function Pricing() {
  const plans = [
    { name: "Member", price: "Free", note: "during the pilot", cta: "Claim your city", featured: true,
      items: ["Join any open group", "One city seat per group", "Send unlimited referrals", "Signed agreements and tracking", "Trust score and public profile"] },
    { name: "Additional cities", price: "Set per group", note: "per city, per month", cta: "See open cities", featured: false,
      items: ["Hold more cities in a group", "Hold a city in another group", "Backup and waitlist priority"] },
    { name: "Premium", price: "Coming soon", note: "per month", cta: "Join the waitlist", featured: false,
      items: ["Advanced analytics", "Featured in directory and search", "Extra team seats", "Priority support"] },
  ];
  return (
    <>
      <PageHead title="Simple, flat pricing" sub="Joining is free. You pay a small flat fee only when you accept a referral — never a share of your commission." />
      <Container className="py-16">
        <div className="grid gap-6 lg:grid-cols-3">
          {plans.map(p => (
            <Card key={p.name} className={cn("flex flex-col p-7", p.featured && "border-2 border-primary shadow-md")}>
              <h2 className="text-lg font-semibold">{p.name}</h2>
              <div className="mt-4 font-display text-4xl font-extrabold">{p.price}</div>
              <div className="text-sm text-muted">{p.note}</div>
              <ul className="mt-6 flex-1 space-y-3">{p.items.map(i => <li key={i} className="flex gap-2 text-[15px]"><Check size={18} className="shrink-0 text-success" />{i}</li>)}</ul>
              <Button className="mt-8" variant={p.featured ? "primary" : "secondary"} href={p.featured ? "#signup" : "#groups"}>{p.cta}</Button>
            </Card>
          ))}
        </div>
        <Card className="mt-10 grid gap-6 p-7 md:grid-cols-3">
          <div className="md:col-span-1"><h2 className="text-lg font-semibold">Accepted-referral fee</h2><p className="mt-1 text-sm text-muted">Charged to the receiving team when they accept. Set by temperature.</p></div>
          <dl className="grid grid-cols-3 gap-4 md:col-span-2">
            {[["Hot", 45], ["Warm", 35], ["Future", 20]].map(([t, v]) => <div key={t as string} className="rounded-md bg-bg p-4"><dt className="text-sm text-muted">{t}</dt><dd className="mt-1 font-display text-2xl font-bold tabular">{money(v as number)}</dd></div>)}
          </dl>
        </Card>
        <p className="mt-4 text-sm text-muted">Sample amounts. Final pricing is set by the super admin and confirmed before launch.</p>
        <section className="mt-16 max-w-3xl">
          <h2 className="display font-display text-2xl font-bold">Questions</h2>
          {[
            ["Do you take a percentage of my commission?", "No. Referral fees are paid broker to broker under your signed agreement. We charge flat fees only."],
            ["When do city subscriptions start?", "After your group’s free period ends. You get notice 14, 7 and 1 days before, and you can release a city any time."],
            ["What if I don’t want to accept a referral?", "Reject it with a short reason before the deadline. You’re not charged, and it goes to the backup team."],
          ].map(([q, a]) => (
            <details key={q} className="group border-b border-line py-5">
              <summary className="cursor-pointer list-none font-semibold">{q}</summary>
              <p className="mt-2 text-muted">{a}</p>
            </details>
          ))}
        </section>
      </Container>
    </>
  );
}

export function Groups() {
  const [q, setQ] = useState("");
  const [access, setAccess] = useState("all");
  const list = useMemo(() => groups.filter(g =>
    (access === "all" || g.access === access) &&
    (q === "" || (g.name + g.tagline + g.open.join(" ") + g.ownerCity).toLowerCase().includes(q.toLowerCase()))), [q, access]);
  return (
    <>
      <PageHead title="Find a group and claim your city" sub="Each group is a trusted network with one team per city. Search for your city to see where it’s still open." />
      <Container className="py-10">
        <div className="flex flex-col gap-3 sm:flex-row">
          <div className="relative flex-1"><Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-subtle" /><Input aria-label="Search groups or cities" placeholder="Search a city, e.g. Pensacola" value={q} onChange={e => setQ(e.target.value)} className="pl-10" /></div>
          <Select aria-label="Industry" className="sm:w-48" defaultValue="re"><option value="re">Real estate</option><option disabled>Insurance (coming soon)</option></Select>
          <Select aria-label="Access" className="sm:w-44" value={access} onChange={e => setAccess(e.target.value)}><option value="all">All groups</option><option value="Open">Open to apply</option><option value="Invite only">Invite only</option></Select>
        </div>
        <p className="mt-4 text-sm text-muted">{list.length} groups in real estate, United States</p>
        <div className="mt-6 grid gap-5 md:grid-cols-2">
          {list.map(g => (
            <a key={g.slug} href="#group" className="block rounded-lg border border-line bg-surface p-6 transition-shadow hover:shadow-md">
              <div className="flex items-start gap-4">
                <span className={cn("flex h-12 w-12 shrink-0 items-center justify-center rounded-lg font-display text-lg font-bold",
                  g.hue === "cobalt" ? "bg-cobalt-500 text-white" : g.hue === "orange" ? "bg-orange-500 text-navy-900" : "bg-navy-800 text-white")}>{g.name.split(" ").map(w => w[0]).slice(0, 2).join("")}</span>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2"><h2 className="text-lg font-semibold">{g.name}</h2>{g.access === "Invite only" && <Badge><Lock size={12} />Invite only</Badge>}</div>
                  <p className="mt-1 text-sm text-muted">{g.tagline}</p>
                </div>
              </div>
              <dl className="mt-5 grid grid-cols-3 gap-3 text-sm">
                <div><dt className="text-muted">Teams</dt><dd className="font-semibold tabular">{g.teams}</dd></div>
                <div><dt className="text-muted">Referrals</dt><dd className="font-semibold tabular">{g.referrals.toLocaleString()}</dd></div>
                <div><dt className="text-muted">Fees reported</dt><dd className="font-semibold tabular">{money(g.fees)}</dd></div>
              </dl>
              <div className="mt-5 flex flex-wrap items-center gap-2 text-sm">
                <span className="flex items-center gap-1 font-medium text-success"><MapPin size={14} />{g.total - g.filled} open cities</span>
                {g.open.slice(0, 3).map(c => <Badge key={c} tone="success">{c}</Badge>)}
              </div>
              <div className="mt-4 flex items-center gap-2 text-sm text-muted"><Users size={14} />Owner: {g.owner}, {g.ownerCity}</div>
            </a>
          ))}
        </div>
        {list.length === 0 && <Card className="mt-6 p-10 text-center"><h2 className="font-semibold">No groups match “{q}”</h2><p className="mt-1 text-muted">Try a nearby city, or ask to be notified when a group opens there.</p><Button className="mt-4" variant="secondary">Notify me</Button></Card>}
      </Container>
    </>
  );
}
