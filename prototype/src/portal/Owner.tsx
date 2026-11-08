import { useState } from "react";
import { Lightbulb, Phone, X, ShieldAlert, RotateCcw, UserMinus } from "lucide-react";
import { Shell } from "../app/Shell";
import { Card, Button, Badge, Avatar, TrustRing, Field, Textarea, Select, cn } from "../ui/kit";
import { Kpi, SentReceivedChart, Segmented } from "../ui/data";
import { NetworkMap } from "./More";
import { monthly, applicants, members, cityPoints } from "../data/portal";

export function OwnerOverview() {
  return (
    <Shell current="owner" title="Mountain West Alliance" actions={<Button variant="secondary">Post announcement</Button>}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Seats filled" value="96 / 140" hint="44 open · 9 on waitlists" />
        <Kpi label="Referrals this month" value="118" change={12} spark={[84, 92, 99, 105, 111, 118]} />
        <Kpi label="Median time to accept" value="19 min" change={-8} lowerIsBetter />
        <Kpi label="SLA breaches (90 days)" value="7" change={-30} lowerIsBetter />
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <Card className="p-5"><h2 className="font-semibold">Group activity</h2><div className="mt-4"><SentReceivedChart data={monthly.map(m => ({ ...m, sent: m.sent * 17, received: m.received * 18 }))} /></div></Card>
        <Card className="p-5">
          <h2 className="flex items-center gap-2 font-semibold"><Lightbulb size={18} className="text-orange-600" />Insights this week</h2>
          <ul className="mt-4 space-y-4 text-sm">
            <li><div className="font-medium">Pueblo, CO has had 6 open requests in 30 days</div><div className="text-muted">Nora Castillo applied for it 2 hours ago.</div><a href="#applications" className="font-medium text-primary">Review application</a></li>
            <li><div className="font-medium">Reno's seat holder was auto-deactivated</div><div className="text-muted">Two missed accept deadlines. Decide whether to reactivate or remove.</div><a href="#enforcement" className="font-medium text-primary">Decide now</a></li>
            <li><div className="font-medium">Denver closes 46% of referrals</div><div className="text-muted">Highest in the group. Consider featuring Mile High Movers in your next announcement.</div></li>
          </ul>
        </Card>
      </div>
      <Card className="mt-6 p-5">
        <h2 className="font-semibold">Leaderboard · November</h2>
        <div className="mt-4 grid gap-6 md:grid-cols-2">{[["Top referrers", [["Jordan Hale", "St. George", 7], ["Carla Diaz", "Denver", 6], ["Tyler Moss", "Provo", 5]]], ["Top closers", [["Carla Diaz", "Denver", 4], ["Jordan Hale", "St. George", 3], ["Andre Silva", "Las Vegas", 2]]]].map(([t, rows]) => (
          <div key={t as string}><h3 className="text-sm font-medium text-muted">{t as string}</h3><ol className="mt-2 space-y-2">{(rows as [string, string, number][]).map(([n, c, v], i) => <li key={n} className={cn("flex items-center gap-3 rounded-md p-2", n === "Jordan Hale" && "bg-cobalt-50 dark:bg-cobalt-500/15")}><span className="w-5 text-center font-bold tabular text-muted">{i + 1}</span><Avatar name={n} size={30} /><div className="flex-1"><div className="font-medium">{n}</div><div className="text-xs text-muted">{c}</div></div><span className="font-semibold tabular">{v}</span></li>)}</ol></div>))}</div>
      </Card>
    </Shell>
  );
}

const seatStyle: Record<string, string> = { you: "Active", active: "Active", open: "Open", waitlist: "Open · waitlist" };
export function SeatMap() {
  const [view, setView] = useState<"map" | "table">("map");
  return (
    <Shell current="seat-map" title="Seat map" actions={<Segmented label="View" value={view} onChange={setView} options={[["map", "Map"], ["table", "Table"]]} />}>
      {view === "map" ? <Card className="p-5"><NetworkMap /></Card> : (
        <Card className="overflow-x-auto"><table className="w-full min-w-[640px] text-sm"><thead className="text-left text-muted"><tr>{["City", "Status", "Seat holder", "Backup", "Waitlist"].map(h => <th key={h} className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
          <tbody>{cityPoints.map((c, i) => (
            <tr key={c.city} className="border-t border-line"><td className="px-4 py-3 font-medium">{c.city}</td>
              <td className="px-4 py-3"><Badge tone={c.status === "open" ? "success" : c.status === "waitlist" ? "warning" : "orange"}>{seatStyle[c.status]}</Badge></td>
              <td className="px-4 py-3">{c.status === "you" ? "Red Rock Realty Group (you)" : c.status === "active" ? ["Wasatch Key Group", "Mile High Movers", "Neon Valley Realty", "Silver State Partners", "Big Sky Homes"][i % 5] : <span className="text-muted">—</span>}</td>
              <td className="px-4 py-3 text-muted">{c.status === "active" && i % 3 === 0 ? "Summit Partners" : "—"}</td><td className="px-4 py-3 tabular">{c.status === "waitlist" ? 3 : 0}</td></tr>))}</tbody></table></Card>)}
    </Shell>
  );
}

export function Applications() {
  const [done, setDone] = useState<Record<string, string>>({});
  return (
    <Shell current="applications" title="Applications">
      <div className="space-y-4">{applicants.map(a => (
        <Card key={a.name} className="p-5">
          <div className="flex flex-wrap items-start gap-4">
            <Avatar name={a.name} size={48} tone="orange" />
            <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="text-lg font-semibold">{a.team}</h2><Badge tone="success">License verified</Badge></div><div className="text-sm text-muted">{a.name} · applying for <span className="font-medium text-ink">{a.city}</span> · {a.applied}</div>
              <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm"><div><dt className="inline text-muted">Experience </dt><dd className="inline font-medium">{a.years} years</dd></div><div><dt className="inline text-muted">Deals last year </dt><dd className="inline font-medium tabular">{a.deals}</dd></div><div><dt className="inline text-muted">Also in </dt><dd className="inline font-medium">{a.other}</dd></div></dl></div>
            <TrustRing score={a.trust} size={48} />
          </div>
          {done[a.name] ? <p className="mt-4 rounded-md bg-bg p-3 text-sm font-medium">{done[a.name]}</p> : (
            <div className="mt-5 flex flex-wrap gap-2"><Button size="sm" onClick={() => setDone({ ...done, [a.name]: `Approved to the ${a.city} seat. ${a.name.split(" ")[0]} has been sent the training video.` })}>Approve to seat</Button><Button size="sm" variant="secondary" onClick={() => setDone({ ...done, [a.name]: "Added as backup team." })}>Approve as backup</Button><Button size="sm" variant="secondary" onClick={() => setDone({ ...done, [a.name]: "Added to the waitlist." })}>Waitlist</Button><Button size="sm" variant="ghost"><Phone size={15} />Schedule call</Button><Button size="sm" variant="ghost" className="text-danger" onClick={() => setDone({ ...done, [a.name]: "Declined with a note." })}>Decline</Button></div>)}
        </Card>))}</div>
    </Shell>
  );
}

export function Enforcement() {
  const [acting, setActing] = useState<(typeof members)[number] | null>(null);
  const [mode, setMode] = useState<"reactivate" | "remove">("reactivate");
  const tone = (s: string) => (s === "Deactivated" ? "danger" : s === "Warned" ? "warning" : "success") as "danger";
  return (
    <Shell current="enforcement" title="Members and enforcement">
      <div className="mb-4 flex items-center gap-3 rounded-md border border-[#F5C2C7] bg-[#FDECEE] p-4 text-sm text-danger dark:border-danger/40 dark:bg-danger/15 dark:text-[#FDA4AF]"><ShieldAlert size={18} /><span><strong>Silver State Partners</strong> was auto-deactivated after a second breach. Reno referrals are going to the backup team.</span></div>
      <Card className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="text-left text-muted"><tr>{["Team", "City", "Trust", "Breaches (90 d)", "Status", "Last breach", ""].map((h, i) => <th key={i} className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
        <tbody>{members.map(m => (
          <tr key={m.team} className="border-t border-line"><td className="px-4 py-3"><div className="font-medium">{m.team}</div><div className="text-muted">{m.leader}</div></td><td className="px-4 py-3">{m.city}</td><td className="px-4 py-3"><TrustRing score={m.trust} size={34} /></td>
            <td className="px-4 py-3"><span className="flex gap-1" aria-label={`${m.breaches} of 2`}>{[0, 1].map(i => <span key={i} className={cn("h-2.5 w-6 rounded-full", i < m.breaches ? "bg-danger" : "bg-line")} />)}</span></td>
            <td className="px-4 py-3"><Badge tone={tone(m.status)}>{m.status}</Badge></td><td className="px-4 py-3 text-muted">{m.last}</td>
            <td className="px-4 py-3 text-right">{m.status === "Deactivated" && <Button size="sm" onClick={() => setActing(m)}>Decide</Button>}</td></tr>))}</tbody></table></Card>
      {acting && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-950/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="dc">
          <Card className="w-full max-w-lg p-6 shadow-lg">
            <div className="flex items-center"><h2 id="dc" className="text-lg font-semibold">{acting.team}, {acting.city}</h2><button className="ml-auto rounded p-1 hover:bg-bg" onClick={() => setActing(null)} aria-label="Close"><X size={18} /></button></div>
            <ul className="mt-3 space-y-1 text-sm text-muted"><li>Oct 21 · Missed weekly update (warning sent)</li><li>Nov 6 · Missed accept deadline on #4808 (auto-deactivated)</li></ul>
            <div className="mt-4"><Segmented label="Decision" value={mode} onChange={setMode} options={[["reactivate", "Reactivate"], ["remove", "Remove from seat"]]} /></div>
            {mode === "remove" && <Field label="Offer the Reno seat to" className="mt-4"><Select><option>Backup: Summit Partners</option><option>Waitlist: first in line</option></Select></Field>}
            <Field label="Note (shared with the team)" className="mt-4"><Textarea placeholder={mode === "reactivate" ? "e.g. Agreed to add a second agent for referrals." : "e.g. Repeated missed deadlines after warning."} /></Field>
            <p className="mt-3 text-sm text-muted">{mode === "reactivate" ? "Their breach count resets and Reno referrals return to them." : "In-flight referrals move to the new seat holder. Signed agreements still protect senders."}</p>
            <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => setActing(null)}>Cancel</Button><Button variant={mode === "remove" ? "danger" : "primary"} onClick={() => setActing(null)}>{mode === "remove" ? <><UserMinus size={16} />Remove team</> : <><RotateCcw size={16} />Reactivate team</>}</Button></div>
          </Card>
        </div>)}
    </Shell>
  );
}
