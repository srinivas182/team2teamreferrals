import { useMemo, useState } from "react";
import { geoAlbersUsa, geoPath } from "d3-geo";
import { feature, mesh } from "topojson-client";
import us from "us-atlas/states-albers-10m.json";
import { Download, CreditCard, FileText, Plus, MapPin, Search } from "lucide-react";
import { Shell } from "../app/Shell";
import { Card, Button, Badge, TempBadge, Avatar, Input, money, cn } from "../ui/kit";
import { Kpi, Segmented } from "../ui/data";
import { sent, monthly, cityPoints } from "../data/portal";

/* ---------------- Sent ---------------- */
export function SentList() {
  const [f, setF] = useState<"all" | "active" | "fees">("all");
  const rows = sent.filter(s => f === "all" || (f === "fees" ? /fee/i.test(s.status) : !/fee|Nurture/i.test(s.status)));
  return (
    <Shell current="sent" title="Sent referrals" actions={<Button href="#send">Send referral</Button>}>
      <div className="mb-4"><Segmented label="Filter" value={f} onChange={setF} options={[["all", "All (6)"], ["active", "In progress"], ["fees", "Fees"]]} /></div>
      <Card className="hidden overflow-x-auto md:block">
        <table className="w-full min-w-[760px] text-sm"><thead className="text-left text-muted"><tr>{["Client", "Sent to", "", "Status", "Last update", "Referral fee"].map((h, i) => <th key={i} className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
          <tbody>{rows.map(s => (
            <tr key={s.id} className="border-t border-line hover:bg-bg">
              <td className="px-4 py-3"><a href="#referral" className="font-medium hover:text-primary">{s.lead}</a><div className="text-muted">#{s.id}</div></td>
              <td className="px-4 py-3"><div className="flex items-center gap-2"><Avatar name={s.to} size={28} tone="orange" /><div><div>{s.toTeam}</div><div className="text-muted">{s.city}</div></div></div></td>
              <td className="px-4 py-3"><TempBadge t={s.temp} /></td>
              <td className="px-4 py-3"><Badge tone={s.tone}>{s.status}</Badge></td>
              <td className="px-4 py-3 text-muted">{s.updated}</td><td className="px-4 py-3 font-medium tabular">{s.fee}</td>
            </tr>))}</tbody></table>
      </Card>
      <div className="space-y-3 md:hidden">{rows.map(s => (
        <a key={s.id} href="#referral" className="block rounded-lg border border-line bg-surface p-4"><div className="flex items-center gap-2"><span className="font-semibold">{s.lead}</span><span className="ml-auto"><TempBadge t={s.temp} /></span></div><div className="mt-1 text-sm text-muted">{s.toTeam} · {s.city}</div><div className="mt-3 flex items-center justify-between"><Badge tone={s.tone}>{s.status}</Badge><span className="text-sm font-medium tabular">{s.fee}</span></div></a>))}</div>
    </Shell>
  );
}

/* ---------------- Earnings ---------------- */
export function Earnings() {
  const max = Math.max(...monthly.map(m => m.fees));
  return (
    <Shell current="earnings" title="Earnings" actions={<Button variant="secondary"><Download size={16} />Year-end export</Button>}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Received this year" value={money(19920)} change={18} />
        <Kpi label="Expected (in pipeline)" value={money(16249)} hint="From 5 active referrals you sent" />
        <Kpi label="Due now" value={money(3210)} hint="Capitol Key Realty · closed Nov 2" />
        <Kpi label="Reciprocity credits" value="+6" hint="Sent 29 · received 23" />
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.3fr_1fr]">
        <Card className="p-5"><h2 className="font-semibold">Fees received by month</h2>
          <div className="mt-6 flex h-48 items-end gap-4" role="img" aria-label="Referral fees received per month">{monthly.map(m => (
            <div key={m.m} className="flex flex-1 flex-col items-center gap-2"><span className="text-xs tabular text-muted">{m.fees ? money(m.fees) : "—"}</span><div className="w-full max-w-[44px] rounded-t bg-success/80" style={{ height: `${(m.fees / max) * 140}px` }} /><span className="text-xs text-muted">{m.m}</span></div>))}</div>
        </Card>
        <Card className="p-5"><h2 className="font-semibold">By partner</h2>
          <ul className="mt-4 space-y-3">{[["Bay Area Living", "Tampa, FL", 8880], ["Desert Peak Group", "Phoenix, AZ", 5310], ["Capitol Key Realty", "Austin, TX", 3210], ["Peachtree Partners", "Atlanta, GA", 2520]].map(([t, c, v]) => (
            <li key={t as string} className="flex items-center gap-3"><Avatar name={t as string} size={32} tone="orange" /><div className="min-w-0 flex-1"><div className="truncate font-medium">{t}</div><div className="text-sm text-muted">{c}</div></div><span className="font-semibold tabular">{money(v as number)}</span></li>))}</ul>
        </Card>
      </div>
      <Card className="mt-6 p-5"><h2 className="font-semibold">Payments to confirm</h2><div className="mt-3 flex flex-wrap items-center gap-3 rounded-md border border-line p-4"><div><div className="font-medium">Lucy Nguyen · Capitol Key Realty</div><div className="text-sm text-muted">Closed Nov 2 · $428,000 · 30% of $10,700 GCI</div></div><span className="ml-auto font-display text-xl font-bold tabular">{money(3210)}</span><Button size="sm">Mark received</Button><Button size="sm" variant="ghost">Dispute</Button></div></Card>
    </Shell>
  );
}

/* ---------------- Network map ---------------- */
const statusStyle = { you: ["#2451F5", "Your city"], active: ["#FF7A1A", "Active seat"], waitlist: ["#A16207", "Waitlist"], open: ["#15803D", "Open — claim it"] } as const;
export function NetworkMap({ filter = "all", onPick }: { filter?: string; onPick?: (c: string) => void }) {
  const { paths, borders } = useMemo(() => {
    const topo = us as any;
    const p = geoPath();
    return { paths: (feature(topo, topo.objects.states) as any).features.map((f: any) => p(f) as string), borders: p(mesh(topo, topo.objects.states, (a: any, b: any) => a !== b) as any) as string };
  }, []);
  const proj = useMemo(() => geoAlbersUsa().scale(1300).translate([487.5, 305]), []);
  // Zoom to the group's region: fit the view to its cities with padding.
  const box = useMemo(() => {
    const xy = cityPoints.map(c => proj([c.lon, c.lat])!).filter(Boolean);
    const xs = xy.map(p => p[0]), ys = xy.map(p => p[1]), pad = 60;
    const x0 = Math.min(...xs) - pad, y0 = Math.min(...ys) - pad, w = Math.max(...xs) - x0 + pad + 90, h = Math.max(...ys) - y0 + pad;
    return `${x0} ${y0} ${w} ${Math.max(h, w * 0.62)}`;
  }, [proj]);
  return (
    <svg viewBox={box} className="h-auto w-full" role="img" aria-label="Map of group cities by seat status">
      {paths.map((d: string, i: number) => <path key={i} d={d} fill="rgb(var(--line))" opacity={0.55} />)}
      <path d={borders} fill="none" stroke="rgb(var(--surface))" strokeWidth={1} />
      {cityPoints.filter(c => filter === "all" || c.status === filter).map(c => { const xy = proj([c.lon, c.lat]); if (!xy) return null; const [col] = statusStyle[c.status];
        return <g key={c.city} transform={`translate(${xy[0]} ${xy[1]})`} className="cursor-pointer" onClick={() => onPick?.(c.city)}>
          {c.status === "you" && <circle r={16} fill={col} opacity={0.2} />}<circle r={c.status === "you" ? 8 : 6.5} fill={col} stroke="rgb(var(--surface))" strokeWidth={2} /><text x={10} y={4} fontSize={11} fill="rgb(var(--muted))" className="select-none">{c.city.split(",")[0]}</text><title>{c.city}: {statusStyle[c.status][1]}</title></g>; })}
    </svg>
  );
}

export function MyGroups() {
  const [filter, setFilter] = useState("all");
  const [picked, setPicked] = useState<string | null>("Pueblo, CO");
  const pc = cityPoints.find(c => c.city === picked);
  return (
    <Shell current="my-groups" title="Groups" actions={<Button variant="secondary" href="#groups"><Search size={16} />Browse groups</Button>}>
      <div className="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <Card className="p-5">
          <div className="flex flex-wrap items-center gap-3"><h2 className="font-semibold">Mountain West Alliance</h2><Badge tone="cobalt">Your seat: St. George</Badge>
            <div className="ml-auto"><Segmented label="Show" value={filter} onChange={setFilter} options={[["all", "All"], ["open", "Open"], ["waitlist", "Waitlist"], ["active", "Active"]]} /></div></div>
          <div className="mt-4"><NetworkMap filter={filter} onPick={setPicked} /></div>
          <ul className="mt-2 flex flex-wrap gap-4 text-sm">{Object.entries(statusStyle).map(([k, [c, l]]) => <li key={k} className="flex items-center gap-2"><span className="h-3 w-3 rounded-full" style={{ background: c }} />{l}</li>)}</ul>
          <details className="mt-4 text-sm"><summary className="cursor-pointer text-primary">Show cities as a list</summary><ul className="mt-2 grid gap-1 sm:grid-cols-2">{cityPoints.map(c => <li key={c.city}>{c.city} — {statusStyle[c.status][1]}</li>)}</ul></details>
        </Card>
        <div className="space-y-6">
          {pc && <Card className="p-5"><div className="flex items-center gap-2"><MapPin size={18} style={{ color: statusStyle[pc.status][0] }} /><h2 className="font-semibold">{pc.city}</h2></div><p className="mt-1 text-sm text-muted">{statusStyle[pc.status][1]} in Mountain West Alliance</p>
            {pc.status === "open" && <Button className="mt-4 w-full">Claim {pc.city.split(",")[0]}</Button>}{pc.status === "waitlist" && <Button variant="secondary" className="mt-4 w-full">Join waitlist (3 ahead)</Button>}{pc.status === "active" && <Button variant="secondary" className="mt-4 w-full" href="#send">Send a referral here</Button>}</Card>}
          <Card className="p-5"><h2 className="font-semibold">Your memberships</h2>
            <ul className="mt-4 space-y-3 text-sm">{[["Mountain West Alliance", "Seat · St. George, UT", "Active", "success"], ["Mountain West Alliance", "Seat · Hurricane, UT", "Active", "success"], ["Sun Belt Top Teams", "Waitlist · Las Vegas, NV", "2nd in line", "warning"]].map(([g, s, st, t], i) => (
              <li key={i} className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-md bg-cobalt-500 text-xs font-bold text-white">{(g as string).split(" ").map(w => w[0]).slice(0, 2).join("")}</span><div className="min-w-0 flex-1"><div className="font-medium">{g}</div><div className="text-muted">{s}</div></div><Badge tone={t as "success"}>{st}</Badge></li>))}</ul>
          </Card>
        </div>
      </div>
    </Shell>
  );
}

/* ---------------- Billing ---------------- */
export function Billing() {
  return (
    <Shell current="billing" title="Billing">
      <div className="grid gap-6 lg:grid-cols-[1fr_1.4fr]">
        <div className="space-y-6">
          <Card className="p-5"><h2 className="font-semibold">Payment method</h2><div className="mt-4 flex items-center gap-3 rounded-md border border-line p-4"><CreditCard className="text-muted" /><div><div className="font-medium">Visa ending 4417</div><div className="text-sm text-muted">Expires 09/28</div></div><Button size="sm" variant="ghost" className="ml-auto">Update</Button></div></Card>
          <Card className="p-5"><h2 className="font-semibold">City seats</h2><ul className="mt-4 space-y-3 text-sm">{[["St. George, UT", "Free during pilot"], ["Hurricane, UT", "Free during pilot"]].map(([c, p]) => <li key={c} className="flex justify-between"><span>{c}</span><Badge tone="success">{p}</Badge></li>)}</ul><p className="mt-4 text-sm text-muted">Mountain West Alliance's free period ends Feb 1, 2027. You'll get notice 14, 7 and 1 days before.</p><Button variant="secondary" size="sm" className="mt-4" href="#my-groups"><Plus size={15} />Claim another city</Button></Card>
        </div>
        <Card className="overflow-x-auto"><h2 className="p-5 pb-0 font-semibold">Charges and invoices</h2>
          <table className="mt-4 w-full min-w-[520px] text-sm"><thead className="text-left text-muted"><tr>{["Date", "Description", "Amount", ""].map((h, i) => <th key={i} className="px-5 py-2 font-medium">{h}</th>)}</tr></thead>
            <tbody>{[["Nov 8", "Accepted referral #4822 · Hot", 45], ["Nov 1", "Accepted referral #4812 · Hot", 45], ["Oct 24", "Accepted referral #4790 · Warm", 35], ["Oct 9", "Accepted referral #4788 · Future", 20], ["Sep 30", "Accepted referral #4784 · Future", 20]].map(([d, t, a]) => (
              <tr key={t as string} className="border-t border-line"><td className="px-5 py-3 text-muted">{d}</td><td className="px-5 py-3">{t}</td><td className="px-5 py-3 font-medium tabular">{money(a as number)}</td><td className="px-5 py-3 text-right"><button className="inline-flex items-center gap-1 text-primary"><FileText size={14} />PDF</button></td></tr>))}</tbody></table>
        </Card>
      </div>
    </Shell>
  );
}
