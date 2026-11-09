import { useState } from "react";
import { Lock, Info, TrendingUp, ArrowRight, Check } from "lucide-react";
import { AdminShell } from "./AdminShell";
import { Card, Button, Badge, Input, Select, money, cn } from "../ui/kit";
import { Segmented } from "../ui/data";
import { industries, bySlug } from "../data/industries";
import { IndustryIcon } from "../ui/brand";

function Toggle({ on, onChange, label, disabled }: { on: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return <button role="switch" aria-checked={on} aria-label={label} disabled={disabled} onClick={() => onChange(!on)} className={cn("relative h-6 w-11 rounded-full transition-colors disabled:opacity-50", on ? "bg-primary" : "bg-line")}><span className={cn("absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all", on ? "left-[22px]" : "left-0.5")} /></button>;
}

/* ---------------- Group pricing ---------------- */
export function AdminGroupPricing() {
  const [slug, setSlug] = useState("solar"); const i = bySlug(slug);
  const [price, setPrice] = useState(79); const [trial, setTrial] = useState(3); const [guarantee, setGuarantee] = useState(true);
  const [cap, setCap] = useState(25); const [annual, setAnnual] = useState(2); const [founding, setFounding] = useState(59);
  const [fee, setFee] = useState<"none" | "percent" | "flat">("none");
  const tierC = i.tier === "C";
  return (
    <AdminShell current="admin-groups" title="Group pricing and rules" actions={<><Button variant="secondary">Preview as member</Button><Button>Save changes</Button></>}>
      <div className="mb-6 flex flex-wrap items-center gap-3"><IndustryIcon industry={i} size={40} /><Select aria-label="Group" value={slug} onChange={e => setSlug(e.target.value)} className="max-w-sm">{industries.map(x => <option key={x.slug} value={x.slug}>{x.group}</option>)}</Select><Badge tone={i.tier === "A" ? "success" : i.tier === "B" ? "warning" : "danger"}>Tier {i.tier}</Badge><span className="text-sm text-muted">{i.claimed.toLocaleString()} seats · territory: {i.territory.toLowerCase()}</span></div>
      <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
        <div className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">Seat pricing</h2>
            <div className="mt-5 grid gap-4 sm:grid-cols-3">
              <label className="block"><span className="mb-1.5 block text-sm font-medium">Price per seat / month</span><Input type="number" value={price} onChange={e => setPrice(+e.target.value)} /></label>
              <label className="block"><span className="mb-1.5 block text-sm font-medium">Founding member price</span><Input type="number" value={founding} onChange={e => setFounding(+e.target.value)} /></label>
              <label className="block"><span className="mb-1.5 block text-sm font-medium">Free trial (months)</span><Input type="number" min={0} max={12} value={trial} onChange={e => setTrial(+e.target.value)} /></label>
            </div>
            <div className="mt-6"><span className="text-sm font-medium">Multi-city discounts</span><div className="mt-2 grid gap-3 sm:grid-cols-3">{[["2–4 seats", 10], ["5–19 seats", 15], ["20+ seats", 25]].map(([l, v]) => <label key={l as string} className="flex items-center gap-2 rounded-md border border-line p-3 text-sm"><span className="flex-1">{l as string}</span><Input type="number" defaultValue={v as number} className="h-9 w-16" aria-label={`Discount for ${l}`} />%</label>)}</div></div>
            <label className="mt-4 flex items-center gap-3 text-sm"><span className="flex-1 font-medium">Annual billing: months free</span><Input type="number" value={annual} onChange={e => setAnnual(+e.target.value)} className="h-9 w-20" /></label>
          </Card>
          <Card className="p-6"><div className="flex items-start gap-4"><div className="flex-1"><h2 className="font-semibold">Value guarantee</h2><p className="mt-1 text-sm text-muted">Credit any billing month in which no referral was offered to the seat, and tell the member why.</p></div><Toggle on={guarantee && !tierC} onChange={setGuarantee} label="Value guarantee" disabled={tierC} /></div>
            {tierC && <p className="mt-3 flex gap-2 rounded-md bg-bg p-3 text-sm text-muted"><Info size={16} className="shrink-0" />Always off in Tier C: fees must be uniform and not depend on referrals.</p>}</Card>
          <Card className="p-6"><h2 className="font-semibold">Rules</h2><div className="mt-4 grid gap-4 sm:grid-cols-2">
            <label className="block"><span className="mb-1.5 block text-sm font-medium">Company cap (% of a state's seats)</span><Input type="number" value={cap} onChange={e => setCap(+e.target.value)} /></label>
            <label className="block"><span className="mb-1.5 block text-sm font-medium">Seat challenge after</span><Select defaultValue="60"><option value="45">45 days below threshold</option><option value="60">60 days below threshold</option><option value="90">90 days below threshold</option></Select></label>
            <label className="block"><span className="mb-1.5 block text-sm font-medium">Challenge threshold (trust score)</span><Input type="number" defaultValue={70} /></label>
            <label className="block"><span className="mb-1.5 block text-sm font-medium">Waitlist ranking</span><Select defaultValue="trust"><option value="trust">Trust score and profile strength</option><option value="date">Date joined</option></Select></label>
          </div></Card>
          <Card className="p-6"><div className="flex items-center gap-2"><h2 className="font-semibold">Platform referral fee</h2><Badge tone="warning"><Lock size={12} />Locked until licensing is approved</Badge></div>
            <p className="mt-1 text-sm text-muted">Built and configurable now; it cannot be charged until switched on after legal sign-off. Never available in Tier B or C.</p>
            <div className="mt-4 opacity-60"><Segmented label="Fee mode" value={fee} onChange={setFee} options={[["none", "None"], ["percent", "% of referral commission"], ["flat", "Flat per referral"]]} /></div></Card>
        </div>
        <aside className="space-y-6">
          <Card className="p-6"><h2 className="font-semibold">What members see</h2>
            <div className="mt-4 rounded-lg border border-line p-5"><div className="text-sm text-muted">{i.group}</div><div className="mt-1 font-display text-3xl font-extrabold">{money(price)}<span className="text-base font-normal text-muted"> / city / month</span></div>
              <ul className="mt-4 space-y-2 text-sm">{[`First ${trial} months free`, guarantee && !tierC ? "Free for any month without a referral" : "Flat monthly listing fee", `Founding members: ${money(founding)}, locked`, `Annual billing: ${annual} months free`].map(t => <li key={t} className="flex gap-2"><Check size={16} className="text-success" />{t}</li>)}</ul></div></Card>
          <Card className="p-6"><h2 className="font-semibold">Projected monthly revenue</h2><p className="mt-1 text-sm text-muted">From current seats after trials, with this month's quiet-seat rate (18%).</p><div className="mt-3 font-display text-3xl font-extrabold tabular">{money(Math.round(i.claimed * price * (guarantee && !tierC ? 0.82 : 1) * 0.9))}</div><p className="text-xs text-muted">Estimate includes average multi-city discount.</p></Card>
          <Card className="p-6 text-sm"><h2 className="font-semibold">Change history</h2><ul className="mt-3 space-y-2 text-muted"><li>Oct 30 · Trial set to 3 months · Srini</li><li>Oct 12 · Company cap 25% · Srini</li></ul></Card>
        </aside>
      </div>
    </AdminShell>
  );
}

/* ---------------- Overflow demand ---------------- */
const demand = [["real-estate", 312, 81], ["insurance", 148, 74], ["mortgage", 96, 69], ["solar", 71, 62], ["roofing", 58, 66], ["hvac", 44, 61], ["financial-advisors", 39, 58], ["moving", 22, 52]] as const;
export function AdminOverflow() {
  const [open, setOpen] = useState<string | null>(null); const [region, setRegion] = useState("West");
  const i = open ? bySlug(open) : null;
  return (
    <AdminShell current="admin-overflow" title="Overflow demand">
      <p className="mb-6 max-w-3xl text-muted">When an industry's network can't seat everyone who wants in, open the next group. It copies the first group's rules and pricing, and waitlisted teams in its region are offered seats first.</p>
      <Card className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="text-left text-muted"><tr>{["Network", "Waitlisted teams", "Top-300 metros filled", "Demand", ""].map(h => <th key={h} scope="col" className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
        <tbody>{demand.map(([s, w, f]) => { const x = bySlug(s); const hot = w > 90 || f > 70; return <tr key={s} className="border-t border-line"><td className="px-4 py-3"><span className="flex items-center gap-3"><IndustryIcon industry={x} size={30} /><span className="font-medium">{x.group}</span></span></td><td className="px-4 py-3 tabular">{w}</td><td className="px-4 py-3"><div className="flex items-center gap-3"><div className="h-2 w-32 rounded-full bg-bg"><div className="h-2 rounded-full bg-primary" style={{ width: `${f}%` }} /></div><span className="tabular">{f}%</span></div></td><td className="px-4 py-3">{hot ? <Badge tone="danger"><TrendingUp size={12} />Ready for a second group</Badge> : <Badge>Growing</Badge>}</td><td className="px-4 py-3 text-right">{hot && <Button size="sm" onClick={() => setOpen(s)}>Open next group</Button>}</td></tr>; })}</tbody></table></Card>
      {i && <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-950/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="ng-title"><Card className="w-full max-w-lg p-6">
        <h2 id="ng-title" className="text-lg font-semibold">Open the next {i.name.toLowerCase()} group</h2>
        <div className="mt-4 space-y-4"><label className="block"><span className="mb-1.5 block text-sm font-medium">Region</span><Select value={region} onChange={e => setRegion(e.target.value)}>{["West", "South", "Midwest", "Northeast", "Nationwide (second network)"].map(r => <option key={r}>{r}</option>)}</Select></label>
          <label className="block"><span className="mb-1.5 block text-sm font-medium">Group name</span><Input defaultValue={`${i.group} — ${region}`} key={region} /></label>
          <ul className="space-y-2 text-sm">{["Copies rules, pricing and training from " + i.group, "Offers seats to waitlisted teams in the region, ranked by trust score", "Announced to waitlisted teams by email, text and in-app"].map(t => <li key={t} className="flex gap-2"><Check size={16} className="text-success" />{t}</li>)}</ul></div>
        <div className="mt-6 flex justify-end gap-2"><Button variant="ghost" onClick={() => setOpen(null)}>Cancel</Button><Button onClick={() => setOpen(null)}>Open group <ArrowRight size={16} /></Button></div></Card></div>}
    </AdminShell>
  );
}
