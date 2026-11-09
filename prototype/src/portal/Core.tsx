import { useState } from "react";
import { ArrowRight, Phone, MessageSquare, FileCheck2, AlertTriangle, CheckCircle2, X, Lock, Unlock, PenLine, Inbox as InboxIcon, Users } from "lucide-react";
import { Shell } from "../app/Shell";
import { Card, Button, Badge, TempBadge, Avatar, TrustRing, Textarea, Field, Input, money, cn } from "../ui/kit";
import { Kpi, SentReceivedChart, Countdown, Segmented } from "../ui/data";
import { incoming, pipeline, sent, monthly } from "../data/portal";

export const estValue = (mid: number, fee: number) => Math.round(mid * 0.025 * (fee / 100));

/* ---------------- Dashboard with Today view ---------------- */
export function Dashboard({ palette }: { palette?: boolean }) {
  const today = [
    { icon: InboxIcon, tone: "text-orange-600", title: "Accept or reject Hannah Price's Hot buyer", meta: "Austin → St. George · est. " + money(estValue(560000, 30)), due: <Countdown minutesLeft={134} total={1440} />, href: "#inbox" },
    { icon: Phone, tone: "text-hot", title: "First call: Rachel Stone", meta: "Hot buyer from Alamo Front Door", due: <Countdown minutesLeft={80} total={120} />, href: "#referral" },
    { icon: FileCheck2, tone: "text-primary", title: "Reply to Dana Kimura's counter-offer", meta: "Offer stage · Desert Peak Group referral", due: <Badge tone="warning">Today 5:00 PM</Badge>, href: "#pipeline" },
    { icon: AlertTriangle, tone: "text-danger", title: "Weekly update overdue: Paul Wexler", meta: "A second missed update counts as a breach", due: <Countdown minutesLeft={0} overdue />, href: "#referral" },
    { icon: CheckCircle2, tone: "text-success", title: "Confirm closing report from Capitol Key Realty", meta: "Lucy Nguyen · " + money(3210) + " due to you", due: <Badge>Since Nov 2</Badge>, href: "#sent" },
  ];
  return (
    <Shell current="dashboard" startPalette={palette} title="Good morning, Jordan" actions={<Button href="#send" className="hidden sm:inline-flex">Send referral</Button>}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Referrals received this month" value="3" change={-25} spark={monthly.map(m => m.received)} href="#inbox" />
        <Kpi label="Your median time to accept" value="14 min" hint="Group requires 24 hours" />
        <Kpi label="Conversion (12 months)" value="36%" change={4} spark={[28, 30, 31, 33, 35, 36]} />
        <Kpi label="Referral fees received this year" value={money(19920)} change={18} spark={monthly.map(m => m.fees)} href="#earnings" />
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <Card>
          <div className="flex items-center justify-between border-b border-line p-5"><div><h2 className="text-lg font-semibold">Today</h2><p className="text-sm text-muted">Everything due today, soonest first</p></div><Badge tone="orange">5 items</Badge></div>
          <ul className="divide-y divide-line">
            {today.map(t => (
              <li key={t.title}><a href={t.href} className="flex items-center gap-4 p-4 hover:bg-bg">
                <t.icon size={20} className={cn("shrink-0", t.tone)} />
                <div className="min-w-0 flex-1"><div className="font-medium">{t.title}</div><div className="truncate text-sm text-muted">{t.meta}</div></div>
                <div className="shrink-0">{t.due}</div>
              </a></li>))}
          </ul>
        </Card>
        <div className="space-y-6">
          <Card className="p-5"><div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-semibold">Sent and received</h2><span className="text-sm text-muted">Last 6 months</span></div><SentReceivedChart data={monthly} /></Card>
          <Card className="p-5">
            <div className="flex items-center gap-4"><TrustRing score={93} size={56} /><div><h2 className="font-semibold">Your trust score</h2><p className="text-sm text-muted">Top 10% in Mountain West Alliance</p></div></div>
            <ul className="mt-4 space-y-2 text-sm">{[["Response speed", 98], ["Update compliance", 86], ["Conversion", 90], ["Fees paid on time", 100], ["Outcome accuracy", 95]].map(([k, v]) => (
              <li key={k as string}><div className="flex justify-between"><span className="text-muted">{k}</span><span className="font-medium tabular">{v}</span></div><div className="mt-1 h-1.5 rounded-full bg-line"><div className="h-full rounded-full bg-success" style={{ width: `${v}%` }} /></div></li>))}</ul>
            <p className="mt-4 text-sm text-muted">Raise “Update compliance” by posting weekly updates on time.</p>
          </Card>
        </div>
      </div>
    </Shell>
  );
}

/* ---------------- Inbox ---------------- */
export function Inbox() {
  const [tab, setTab] = useState<"mine" | "open">("mine");
  const [rejecting, setRejecting] = useState<number | null>(null);
  const [gone, setGone] = useState<number[]>([]);
  const list = incoming.filter(r => !gone.includes(r.id));
  return (
    <Shell current="inbox" title="Inbox">
      <div className="mb-5 flex flex-wrap items-center gap-3"><Segmented label="Inbox view" value={tab} onChange={setTab} options={[["mine", `For you (${list.length})`], ["open", "Open requests (2)"]]} /><p className="text-sm text-muted">You see the lead's details after you accept and sign.</p></div>
      {tab === "mine" ? (
        <div className="space-y-4">
          {list.map(r => (
            <Card key={r.id} className="p-5">
              <div className="flex flex-wrap items-center gap-2"><TempBadge t={r.temp} /><Badge>{r.kind}</Badge><span className="text-sm text-muted">#{r.id} · for {r.city}</span><span className="ml-auto"><Countdown minutesLeft={r.minutesLeft} total={r.total} /></span></div>
              <div className="mt-4 grid gap-5 lg:grid-cols-[1fr_auto]">
                <div>
                  <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt className="text-muted">Price range</dt><dd className="font-medium tabular">{r.price}</dd></div>
                    <div><dt className="text-muted">Financing</dt><dd className="font-medium">{r.financing}</dd></div>
                    <div><dt className="text-muted">Timeline</dt><dd className="font-medium">{r.timeline}</dd></div>
                    <div><dt className="text-muted">Referral fee</dt><dd className="font-medium tabular">{r.fee}%</dd></div>
                  </dl>
                  <p className="mt-4 rounded-md bg-bg p-3 text-sm"><span className="font-medium">Sender's note:</span> {r.note}</p>
                  <div className="mt-4 flex items-center gap-3 text-sm"><Avatar name={r.from} size={30} /><span><span className="font-medium">{r.from}</span> <span className="text-muted">· {r.fromTeam}, {r.fromCity}</span></span><TrustRing score={r.trust} size={34} /></div>
                </div>
                <div className="flex flex-col justify-between gap-4 rounded-md border border-line p-4 lg:w-60">
                  <div><div className="text-sm text-muted">Estimated referral value</div><div className="font-display text-2xl font-bold tabular text-success">{money(estValue(r.mid, r.fee))}</div><div className="text-xs text-muted">Owed to {r.fromTeam} if it closes</div></div>
                  <div className="flex gap-2"><Button variant="secondary" className="flex-1" onClick={() => setRejecting(r.id)}>Reject</Button><Button className="flex-1" href="#accept">Accept</Button></div>
                  <div className="text-xs text-muted">Accepting charges {money(r.temp === "hot" ? 45 : r.temp === "warm" ? 35 : 20)} to your card.</div>
                </div>
              </div>
            </Card>))}
          {list.length === 0 && <Card className="p-10 text-center"><CheckCircle2 className="mx-auto text-success" size={36} /><h2 className="mt-3 font-semibold">You're all caught up</h2><p className="text-muted">New referrals for St. George and Hurricane appear here. We'll also text you.</p></Card>}
        </div>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-muted">Referrals for Utah cities with no active seat holder. The first eligible team to accept gets it.</p>
          {[["Cedar City, UT", "Buyer", "warm", "$330k–$380k"], ["Logan, UT", "Seller", "hot", "$450k–$500k"]].map(([c, k, t, p]) => (
            <Card key={c} className="flex flex-wrap items-center gap-4 p-5"><Users className="text-muted" /><div><div className="font-semibold">{c}</div><div className="text-sm text-muted">{k} · {p}</div></div><TempBadge t={t as "hot"} /><Button size="sm" className="ml-auto" href="#accept">Accept</Button></Card>))}
        </div>
      )}
      {rejecting !== null && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-950/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="rj">
          <Card className="w-full max-w-md p-6 shadow-lg">
            <div className="flex items-center"><h2 id="rj" className="text-lg font-semibold">Reject referral #{rejecting}?</h2><button className="ml-auto rounded p-1 hover:bg-bg" onClick={() => setRejecting(null)} aria-label="Close"><X size={18} /></button></div>
            <p className="mt-1 text-sm text-muted">It goes to the backup team for this city. You won't be charged.</p>
            <div className="mt-4 flex flex-wrap gap-2">{["Outside my service area", "Price range too low", "At capacity", "Conflict of interest"].map(x => <button key={x} className="rounded-full border border-line px-3 py-1 text-sm hover:border-primary">{x}</button>)}</div>
            <Field label="Note to the sender" className="mt-4"><Textarea placeholder="Optional, but it helps them place the lead" /></Field>
            <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => setRejecting(null)}>Cancel</Button><Button variant="danger" onClick={() => { setGone([...gone, rejecting]); setRejecting(null); }}>Reject referral</Button></div>
          </Card>
        </div>)}
    </Shell>
  );
}

/* ---------------- Accept and e-sign ---------------- */
export function AcceptSign() {
  const r = incoming[0];
  const [name, setName] = useState("");
  const [signed, setSigned] = useState(false);
  return (
    <Shell current="inbox" title={signed ? "Referral accepted" : `Accept referral #${r.id}`}>
      <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
        <Card className="p-6">
          {!signed ? (<>
            <div className="flex items-center gap-2 text-sm text-muted"><FileCheck2 size={16} />Referral agreement · generated from the Mountain West Alliance template</div>
            <div className="mt-4 max-h-[420px] space-y-4 overflow-y-auto rounded-md border border-line bg-bg p-5 text-[15px] leading-relaxed">
              <h2 className="font-display text-xl font-bold">Broker-to-broker referral agreement</h2>
              <p><strong>Referring brokerage:</strong> Capitol Key Realty LLC (TX 9001234), agent Hannah Price.<br /><strong>Receiving brokerage:</strong> Red Rock Realty Group LLC (UT 5512098-PB00), agent Jordan Hale.</p>
              <p><strong>Client:</strong> details released on signature. <strong>Type:</strong> Buyer, St. George, UT, {r.price}.</p>
              <p><strong>Referral fee:</strong> {r.fee}% of the gross commission earned by the receiving brokerage on any transaction with the client within <strong>12 months</strong> of this agreement, paid broker to broker within 10 business days of closing.</p>
              <p><strong>Obligations:</strong> the receiving agent will contact the client within 2 hours, post status updates at least every 7 days, and file a closing report within 7 days of closing.</p>
              <p className="text-sm text-muted">Template text is sample content pending attorney review.</p>
            </div>
            <div className="mt-5 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
              <Field label="Type your full name to sign" hint="Signing as Jordan Hale, Red Rock Realty Group"><Input value={name} onChange={e => setName(e.target.value)} placeholder="Jordan Hale" /></Field>
              <Button disabled={name.trim().length < 3} onClick={() => setSigned(true)}><PenLine size={16} />Sign and accept</Button>
            </div>
            <p className="mt-3 text-sm text-muted">Signing charges {money(45)} (Hot referral) to Visa ending 4417 and sends the agreement to Hannah Price for her signature.</p>
          </>) : (<>
            <svg viewBox="0 0 320 90" className="mx-auto h-24 w-full max-w-md" aria-hidden><defs><linearGradient id="ag" x1="0" x2="1"><stop stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs><path className="relay-path" d="M30 70 C110 -5, 210 -5, 290 62" fill="none" stroke="url(#ag)" strokeWidth="4" strokeLinecap="round" /><circle cx="30" cy="70" r="8" fill="#2451F5" /><circle className="pop" style={{ animationDelay: "1.6s", transformOrigin: "290px 62px" }} cx="290" cy="62" r="8" fill="#FF7A1A" /></svg>
            <h2 className="mt-2 text-center font-display text-2xl font-bold">You've got the lead</h2>
            <p className="mt-1 text-center text-muted">Agreement signed by you. Hannah Price has been asked to countersign. Your first call is due within 2 hours.</p>
            <Card className="mx-auto mt-6 max-w-lg bg-bg p-5">
              <div className="flex items-center gap-2 text-sm font-medium text-success"><Unlock size={16} />Client details unlocked</div>
              <div className="mt-3 text-lg font-semibold">Dr. Amelia Ford</div>
              <div className="text-sm text-muted">(512) 555-0186 · amelia.ford@example.com</div>
              <div className="mt-4 flex gap-2"><Button size="sm"><Phone size={15} />Call now</Button><Button size="sm" variant="secondary"><MessageSquare size={15} />Text</Button><Button size="sm" variant="secondary" href="#referral">Open referral<ArrowRight size={15} /></Button></div>
            </Card>
          </>)}
        </Card>
        <div className="space-y-4">
          <Card className="p-5"><div className="flex items-center gap-2"><TempBadge t="hot" /><Badge>Buyer</Badge></div><div className="mt-3 text-sm text-muted">Estimated referral value</div><div className="font-display text-2xl font-bold tabular text-success">{money(estValue(r.mid, r.fee))}</div><dl className="mt-4 space-y-2 text-sm">{[["Price range", r.price], ["Financing", r.financing], ["Timeline", r.timeline], ["From", `${r.from}, ${r.fromCity}`]].map(([k, v]) => <div key={k} className="flex justify-between gap-4"><dt className="text-muted">{k}</dt><dd className="text-right font-medium">{v}</dd></div>)}</dl></Card>
          <Card className="flex items-center gap-3 p-5 text-sm"><Lock size={18} className="text-muted" /><span className="text-muted">Contact details stay hidden until the agreement is signed.</span></Card>
        </div>
      </div>
    </Shell>
  );
}
