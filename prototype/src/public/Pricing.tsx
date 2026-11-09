import { useState } from "react";
import { Check, ShieldCheck, Building2, Gift } from "lucide-react";
import { Container, Button, Card, Badge, money, cn } from "../ui/kit";
import { Segmented } from "../ui/data";

const SEAT = 79; // sample price per city seat per month; set per group by the admin
const discount = (n: number) => (n >= 20 ? 0.25 : n >= 5 ? 0.15 : n >= 2 ? 0.1 : 0);

function Calculator() {
  const [cities, setCities] = useState(3); const [groups, setGroups] = useState(1); const [billing, setBilling] = useState<"monthly" | "annual">("monthly");
  const seats = cities * groups; const d = discount(seats);
  const monthly = seats * SEAT * (1 - d); const annualMonthly = monthly * (10 / 12);
  const shown = billing === "annual" ? annualMonthly : monthly;
  return (
    <Card className="p-6 sm:p-8">
      <div className="flex flex-wrap items-center gap-3"><h2 className="font-display text-2xl font-bold">What you’d pay</h2><div className="ml-auto"><Segmented label="Billing" value={billing} onChange={setBilling} options={[["monthly", "Monthly"], ["annual", "Annual · 2 months free"]]} /></div></div>
      <div className="mt-6 grid gap-6 sm:grid-cols-2">
        <label className="block"><span className="flex justify-between text-sm font-medium"><span>Cities</span><span className="tabular">{cities}</span></span><input type="range" min={1} max={50} value={cities} onChange={e => setCities(+e.target.value)} className="mt-3 w-full accent-[#2451F5]" /></label>
        <label className="block"><span className="flex justify-between text-sm font-medium"><span>Industry networks (groups)</span><span className="tabular">{groups}</span></span><input type="range" min={1} max={5} value={groups} onChange={e => setGroups(+e.target.value)} className="mt-3 w-full accent-[#2451F5]" /></label>
      </div>
      <div className="mt-8 grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg bg-bg p-4"><div className="text-sm text-muted">First 3 months</div><div className="mt-1 font-display text-3xl font-extrabold text-success">$0</div></div>
        <div className="rounded-lg bg-bg p-4"><div className="text-sm text-muted">Then, per month</div><div className="mt-1 font-display text-3xl font-extrabold tabular">{money(Math.round(shown))}</div><div className="text-xs text-muted">{seats} seat{seats > 1 ? "s" : ""} · {money(SEAT)} each{d ? ` · ${Math.round(d * 100)}% multi-city saving` : ""}</div></div>
        <div className="rounded-lg bg-bg p-4"><div className="text-sm text-muted">Months without referrals</div><div className="mt-1 font-display text-3xl font-extrabold text-success">$0</div><div className="text-xs text-muted">Credited automatically</div></div>
      </div>
      <p className="mt-4 text-xs text-muted">Sample pricing for this prototype. Each network sets its own seat price.</p>
    </Card>
  );
}

export function Pricing() {
  return (
    <>
      <section className="border-b border-line bg-surface">
        <Container className="py-16 text-center sm:py-20">
          <Badge tone="success">No referrals, no charge</Badge>
          <h1 className="display mx-auto mt-4 max-w-3xl font-display text-4xl font-extrabold sm:text-5xl">Pay per city. Never for a quiet month.</h1>
          <p className="mx-auto mt-4 max-w-2xl text-lg text-muted">Every seat starts with 3 months free. After that you pay a flat monthly price per city, and any month no referral reaches you is free. We never take a share of your commission.</p>
        </Container>
      </section>
      <Container className="grid gap-6 py-14 lg:grid-cols-3">
        {[
          { name: "Team", price: `${money(SEAT)}`, unit: "per city seat / month", icon: ShieldCheck, points: ["3 months free on every seat", "Free months when no referral reaches you", "Unlimited referrals sent and received", "CRM, tracking, agreements and e-sign", "City report card and trust score"], cta: "Reserve my city", primary: true },
          { name: "Multi-city", price: "Save up to 25%", unit: "on 2+ seats", icon: Gift, points: ["10% off 2–4 seats, 15% off 5–19, 25% off 20+", "Annual billing: 2 months free", "One invoice for every seat", "Same value guarantee on every seat"], cta: "Price my cities" },
          { name: "Company", price: "Custom", unit: "for branches in many cities", icon: Building2, points: ["City-leader logins for each branch", "Company pays centrally, or each city pays", "Private network for your own branches", "Dashboard of every seat and trial"], cta: "Talk to us" },
        ].map(p => (
          <Card key={p.name} className={cn("flex flex-col p-7", p.primary && "border-primary ring-2 ring-primary/15")}>
            <p.icon className="text-primary" /><h2 className="mt-4 text-lg font-semibold">{p.name}</h2>
            <div className="mt-2 font-display text-3xl font-extrabold">{p.price}</div><div className="text-sm text-muted">{p.unit}</div>
            <ul className="mt-6 flex-1 space-y-3 text-sm">{p.points.map(t => <li key={t} className="flex gap-2"><Check size={18} className="shrink-0 text-success" />{t}</li>)}</ul>
            <Button className="mt-7" variant={p.primary ? "primary" : "secondary"} href="#signup">{p.cta}</Button>
          </Card>))}
      </Container>
      <Container className="pb-6"><Calculator /></Container>
      <Container className="grid gap-6 py-14 md:grid-cols-2">
        {[["When does billing start?", "Three months after you claim each seat. We remind you 14, 7 and 1 days before."], ["What counts as a quiet month?", "A month in which no referral was offered to your seat. It’s credited automatically. You don’t need to ask."], ["Do you take a share of my commission?", "No. Referral fees are agreed between members and paid business to business. We charge only for your seats."], ["Can I lose my city?", "Only if your reputation stays low for 60 days and the top waitlisted pro challenges for it. Your network owner decides."]].map(([q, a]) => (
          <div key={q}><h3 className="font-semibold">{q}</h3><p className="mt-2 text-muted">{a}</p></div>))}
      </Container>
    </>
  );
}
