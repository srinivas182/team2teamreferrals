import { useMemo, useState } from "react";
import { Search, MapPin, Clock, ArrowRight, ArrowLeft, CheckCircle2, Link2, Copy, Send as SendIcon, Paperclip, Phone, LayoutList, Columns3, Rows3, Rows2, FileText, X } from "lucide-react";
import { Shell } from "../app/Shell";
import { Card, Button, Badge, TempBadge, Avatar, TrustRing, Field, Input, Select, Textarea, money, cn, type Temp } from "../ui/kit";
import { Countdown, Segmented } from "../ui/data";
import { pipeline, stages, type Lead, type Stage } from "../data/portal";

/* ---------------- Send referral ---------------- */
const cityIndex = [
  { city: "Houston, TX", seats: [{ group: "Sun Belt Top Teams", team: "Bayou City Homes", leader: "Chris Delgado", trust: 94, resp: "11 min", status: "Active" }, { group: "Relocation Pros Network", team: "Gulf Coast Moves", leader: "Aisha Patel", trust: 89, resp: "22 min", status: "Active" }] },
  { city: "Huntsville, AL", seats: [{ group: "Sun Belt Top Teams", team: "Rocket City Realty", leader: "Dee Morgan", trust: 86, resp: "31 min", status: "Active" }] },
  { city: "Hurricane, UT", seats: [{ group: "Mountain West Alliance", team: "Red Rock Realty Group", leader: "You", trust: 93, resp: "14 min", status: "Yours" }] },
  { city: "Huntington Beach, CA", seats: [{ group: "Coastal Luxury Collective", team: "", leader: "", trust: 0, resp: "", status: "Open request" }] },
];

export function SendReferral() {
  const [step, setStep] = useState(0);
  const [q, setQ] = useState("Hou");
  const [pick, setPick] = useState<{ city: string; team: string; leader: string; group: string } | null>(null);
  const [temp, setTemp] = useState<Temp>("hot");
  const [lo, setLo] = useState(450), [hi, setHi] = useState(520), [fee, setFee] = useState(30), [kind, setKind] = useState("Buyer");
  const [done, setDone] = useState(false);
  const matches = useMemo(() => cityIndex.filter(c => q.length > 1 && c.city.toLowerCase().startsWith(q.toLowerCase())), [q]);
  const value = Math.round(((lo + hi) / 2) * 1000 * 0.025 * (fee / 100));
  if (done) return (
    <Shell current="send" title="Referral sent">
      <Card className="mx-auto max-w-xl p-8 text-center">
        <CheckCircle2 size={48} className="mx-auto text-success" />
        <h2 className="mt-4 font-display text-2xl font-bold">Sent to {pick?.leader} in {pick?.city}</h2>
        <p className="mt-2 text-muted">They have 24 hours to accept. Your client gets a text asking them to confirm, and you'll see every step on the timeline.</p>
        <div className="mt-6 flex justify-center gap-3"><Button variant="secondary" href="#sent">View sent referrals</Button><Button onClick={() => { setDone(false); setStep(0); setPick(null); }}>Send another</Button></div>
      </Card>
    </Shell>);
  return (
    <Shell current="send" title="Send a referral">
      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card className="p-6">
          <ol className="mb-6 flex gap-2 text-sm" aria-label="Steps">{["Where", "Lead", "Review"].map((s, i) => <li key={s} className={cn("flex items-center gap-2 font-medium", i === step ? "text-ink" : "text-muted")}><span className={cn("flex h-6 w-6 items-center justify-center rounded-full text-xs", i <= step ? "bg-primary text-on-primary" : "bg-line")}>{i + 1}</span>{s}{i < 2 && <span className="mx-1 text-line">—</span>}</li>)}</ol>
          {step === 0 && <>
            <Field label="Which city is your client moving to?"><div className="relative"><Search size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-subtle" /><Input autoFocus value={q} onChange={e => setQ(e.target.value)} className="pl-10" placeholder="Start typing a city" /></div></Field>
            <ul className="mt-4 space-y-3" aria-live="polite">
              {matches.map(c => (
                <li key={c.city}><div className="font-semibold">{c.city}</div>
                  <ul className="mt-2 space-y-2">{c.seats.map(s => (
                    <li key={s.group}><button disabled={s.status === "Yours"} onClick={() => { setPick({ city: c.city, team: s.team || "Open request board", leader: s.leader || "the first eligible team", group: s.group }); setStep(1); }}
                      className="flex w-full items-center gap-3 rounded-md border border-line p-3 text-left hover:border-primary disabled:opacity-50">
                      {s.team ? <Avatar name={s.leader === "You" ? "Jordan Hale" : s.leader} size={34} tone="orange" /> : <MapPin className="text-success" />}
                      <div className="min-w-0 flex-1"><div className="font-medium">{s.team || "No seat holder yet"}</div><div className="text-sm text-muted">{s.group}{s.leader && s.leader !== "You" ? ` · ${s.leader}` : ""}</div></div>
                      {s.status === "Active" && <><span className="hidden items-center gap-1 text-sm text-muted sm:flex"><Clock size={14} />{s.resp}</span><TrustRing score={s.trust} size={36} /></>}
                      {s.status === "Yours" && <Badge>Your city</Badge>}{s.status === "Open request" && <Badge tone="success">Open request</Badge>}
                    </button></li>))}</ul>
                </li>))}
              {q.length > 1 && matches.length === 0 && <li className="text-sm text-muted">No groups cover “{q}” yet. Post it as an open request and any licensed member in that state can take it.</li>}
            </ul>
          </>}
          {step === 1 && <div className="space-y-5">
            <div className="grid gap-4 sm:grid-cols-2"><Field label="Client name"><Input defaultValue="Tom & Ana Brennan" /></Field><Field label="Looking to"><Select value={kind} onChange={e => setKind(e.target.value)}><option>Buyer</option><option>Seller</option><option>Buyer and seller</option></Select></Field></div>
            <div className="grid gap-4 sm:grid-cols-2"><Field label="Mobile"><Input type="tel" defaultValue="(435) 555-0119" /></Field><Field label="Email"><Input type="email" defaultValue="brennans@example.com" /></Field></div>
            <fieldset><legend className="mb-2 text-sm font-medium">How soon will they act?</legend>
              <div className="grid gap-2 sm:grid-cols-3">{([["hot", "Within 30 days"], ["warm", "1–3 months"], ["future", "3–12+ months"]] as [Temp, string][]).map(([t, l]) => (
                <button key={t} type="button" onClick={() => setTemp(t)} aria-pressed={temp === t} className={cn("rounded-md border p-3 text-left", temp === t ? "border-primary ring-2 ring-primary/20" : "border-line")}><TempBadge t={t} /><div className="mt-2 text-sm text-muted">{l}</div></button>))}</div>
            </fieldset>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="Price from ($k)"><Input type="number" value={lo} onChange={e => setLo(+e.target.value || 0)} /></Field>
              <Field label="Price to ($k)"><Input type="number" value={hi} onChange={e => setHi(+e.target.value || 0)} /></Field>
              <Field label="Financing"><Select><option>Pre-approved</option><option>Cash</option><option>Needs lender</option></Select></Field>
            </div>
            <Field label={`Referral fee: ${fee}%`} hint="Group default is 30%. You can offer more, not less."><input type="range" min={30} max={50} step={5} value={fee} onChange={e => setFee(+e.target.value)} className="w-full accent-[#2451F5]" /></Field>
            <Field label="What they're looking for"><Textarea defaultValue="Relocating with work in February. 4 bed, good schools, under 40 minutes to the Energy Corridor." /></Field>
          </div>}
          {step === 2 && <div className="space-y-4">
            <dl className="divide-y divide-line rounded-md border border-line">{[["To", `${pick?.team} · ${pick?.leader}`], ["City", pick?.city], ["Client", "Tom & Ana Brennan, " + kind.toLowerCase()], ["Price", `$${lo}k–$${hi}k`], ["Fee", `${fee}%`]].map(([k, v]) => <div key={k} className="flex justify-between gap-4 p-3 text-sm"><dt className="text-muted">{k}</dt><dd className="text-right font-medium">{v}</dd></div>)}</dl>
            <label className="flex gap-2 text-sm"><input type="checkbox" defaultChecked className="mt-1" />My client agreed to be referred and to be contacted by the receiving team.</label>
            <p className="text-sm text-muted">No earlier referral for this client found in your groups.</p>
          </div>}
          <div className="mt-8 flex gap-3">{step > 0 && <Button variant="ghost" onClick={() => setStep(step - 1)}><ArrowLeft size={16} />Back</Button>}
            {step === 1 && <Button className="ml-auto" onClick={() => setStep(2)}>Review<ArrowRight size={16} /></Button>}
            {step === 2 && <Button className="ml-auto" onClick={() => setDone(true)}><SendIcon size={16} />Send referral</Button>}</div>
        </Card>
        <Card className="h-fit p-5 lg:sticky lg:top-24">
          <h2 className="text-sm font-medium text-muted">Estimated referral value</h2>
          <div className="mt-1 font-display text-4xl font-extrabold tabular text-success" aria-live="polite">{money(value)}</div>
          <p className="mt-2 text-sm text-muted">Midpoint ${Math.round((lo + hi) / 2)}k × 2.5% typical commission × {fee}% fee. The receiving team sees this before they accept.</p>
          {pick && <div className="mt-5 flex items-center gap-3 border-t border-line pt-4 text-sm"><Avatar name={pick.leader === "the first eligible team" ? "Open Request" : pick.leader} size={32} tone="orange" /><div><div className="font-medium">{pick.team}</div><div className="text-muted">{pick.city}</div></div></div>}
          <div className="mt-4"><TempBadge t={temp} /></div>
        </Card>
      </div>
    </Shell>
  );
}

/* ---------------- Pipeline ---------------- */
export function Pipeline() {
  const [view, setView] = useState<"board" | "list">("board");
  const [density, setDensity] = useState<"comfortable" | "compact">("comfortable");
  const [leads, setLeads] = useState<Lead[]>(pipeline);
  const [drag, setDrag] = useState<number | null>(null);
  const move = (id: number, stage: Stage) => setLeads(ls => ls.map(l => (l.id === id ? { ...l, stage } : l)));
  const compact = density === "compact";
  const LeadCard = ({ l }: { l: Lead }) => (
    <div draggable onDragStart={() => setDrag(l.id)} className={cn("cursor-grab rounded-md border border-line bg-surface shadow-sm active:cursor-grabbing", compact ? "p-2.5" : "p-3.5")}>
      <div className="flex items-center gap-2"><TempBadge t={l.temp} /><span className="ml-auto text-xs font-medium tabular text-success">{money(l.value)}</span></div>
      <a href="#referral" className="mt-2 block font-semibold hover:text-primary">{l.name}</a>
      {!compact && <div className="text-sm text-muted">{l.price} · from {l.from}</div>}
      <div className={cn("mt-2 flex items-center gap-2 text-xs", l.overdue ? "text-danger" : "text-muted")}><Clock size={12} />{l.next} · {l.due}</div>
      <label className="sr-only" htmlFor={`mv${l.id}`}>Move {l.name} to stage</label>
      <select id={`mv${l.id}`} value={l.stage} onChange={e => move(l.id, e.target.value as Stage)} className="mt-2 w-full rounded border border-line bg-bg px-2 py-1 text-xs">{stages.map(s => <option key={s}>{s}</option>)}</select>
    </div>);
  return (
    <Shell current="pipeline" title="Pipeline" actions={<><Segmented label="View" value={view} onChange={setView} options={[["board", "Board"], ["list", "List"]]} /><Segmented label="Density" value={density} onChange={setDensity} options={[["comfortable", "Comfortable"], ["compact", "Compact"]]} /></>}>
      <div className="mb-4 flex flex-wrap gap-2 text-sm"><Badge tone="danger">1 overdue update</Badge><Badge tone="warning">2 due today</Badge><span className="text-muted">Pipeline value {money(leads.reduce((a, l) => a + l.value, 0))} in referral fees owed to partners</span></div>
      {view === "board" ? (
        <div className="-mx-4 overflow-x-auto px-4 pb-4 sm:-mx-6 sm:px-6"><div className="flex min-w-max gap-4">
          {stages.map(s => { const col = leads.filter(l => l.stage === s); return (
            <section key={s} onDragOver={e => e.preventDefault()} onDrop={() => { if (drag !== null) move(drag, s); setDrag(null); }} className="w-72 shrink-0 rounded-lg bg-navy-100/60 p-3 dark:bg-navy-800/40" aria-label={`${s}, ${col.length} leads`}>
              <h2 className="mb-3 flex items-center justify-between px-1 text-sm font-semibold">{s}<span className="rounded-full bg-surface px-2 text-xs text-muted">{col.length}</span></h2>
              <div className="space-y-2.5">{col.map(l => <LeadCard key={l.id} l={l} />)}{col.length === 0 && <p className="rounded-md border border-dashed border-line p-4 text-center text-xs text-muted">Drop a lead here</p>}</div>
            </section>); })}
        </div></div>
      ) : (
        <Card className="overflow-x-auto"><table className="w-full min-w-[720px] text-sm"><thead className="text-left text-muted"><tr>{["Client", "Temperature", "Stage", "Next step", "Due", "Fee owed"].map(h => <th key={h} className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
          <tbody>{leads.map(l => <tr key={l.id} className="border-t border-line"><td className={cn("px-4", compact ? "py-2" : "py-3")}><a href="#referral" className="font-medium hover:text-primary">{l.name}</a><div className="text-muted">{l.from}</div></td><td className="px-4"><TempBadge t={l.temp} /></td><td className="px-4">{l.stage}</td><td className="px-4">{l.next}</td><td className={cn("px-4 tabular", l.overdue && "text-danger")}>{l.due}</td><td className="px-4 tabular">{money(l.value)}</td></tr>)}</tbody></table></Card>
      )}
    </Shell>
  );
}

/* ---------------- Referral detail ---------------- */
export function ReferralDetail() {
  const [share, setShare] = useState(false);
  const [msg, setMsg] = useState("");
  const [thread, setThread] = useState([
    { who: "sender", name: "Jordan Hale", text: "Thanks for jumping on this, Chris. They loved the Heights when they visited last spring.", at: "Tue 9:20 AM" },
    { who: "receiver", name: "Chris Delgado", text: "Spoke with Tom for 30 minutes. Lined up four homes in the Heights and Garden Oaks for Saturday.", at: "Tue 9:44 AM" },
    { who: "receiver", name: "Chris Delgado", text: "Showings done. They're serious about the Oxford St house — offer likely Monday.", at: "Today 4:12 PM" },
  ]);
  const events: [string, string, "sender" | "receiver"][] = [["Sent by you", "Tue 9:02 AM", "sender"], ["Client confirmed consent", "Tue 9:05 AM", "sender"], ["Accepted and signed by Chris Delgado", "Tue 9:16 AM · 14 min", "receiver"], ["First call with Tom", "Tue 9:41 AM", "receiver"], ["Stage: Showing (4 homes)", "Sat 11:30 AM", "receiver"], ["Update posted", "Today 4:12 PM", "receiver"]];
  const steps = ["Sent", "Accepted", "Contacted", "Showing", "Offer", "Under contract", "Closed"];
  return (
    <Shell current="sent" title="Tom & Ana Brennan" actions={<Button variant="secondary" onClick={() => setShare(true)}><Link2 size={16} />Share status with client</Button>}>
      <div className="mb-5 flex flex-wrap items-center gap-2 text-sm"><TempBadge t="hot" /><Badge>Buyer</Badge><span className="text-muted">#4821 · St. George → Houston · $450k–$520k · 30% fee</span><span className="ml-auto font-semibold tabular text-success">{money(3638)} est.</span></div>
      <Card className="mb-6 overflow-x-auto p-5"><ol className="flex min-w-[640px] items-center">{steps.map((s, i) => (
        <li key={s} className="flex flex-1 items-center last:flex-none"><div className="flex flex-col items-center gap-1.5"><span className={cn("h-3.5 w-3.5 rounded-full border-2", i === 0 ? "border-cobalt-500 bg-cobalt-500" : i <= 3 ? "border-orange-500 bg-orange-500" : "border-line bg-surface")} /><span className={cn("text-xs", i <= 3 ? "font-semibold" : "text-muted")}>{s}</span></div>{i < steps.length - 1 && <span className={cn("mx-1 mb-5 h-0.5 flex-1", i < 3 ? "bg-gradient-to-r from-cobalt-500 to-orange-500" : "bg-line")} />}</li>))}</ol></Card>
      <div className="grid gap-6 xl:grid-cols-[1fr_1.1fr]">
        <Card className="p-5">
          <h2 className="font-semibold">Timeline</h2>
          <ol className="mt-4 space-y-4 border-l-2 border-line pl-5">{events.map(([t, at, who]) => (
            <li key={t} className="relative"><span className={cn("absolute -left-[27px] top-1 h-3 w-3 rounded-full ring-4 ring-surface", who === "sender" ? "bg-cobalt-500" : "bg-orange-500")} /><div className="font-medium">{t}</div><div className="text-sm tabular text-muted">{at}</div></li>))}</ol>
          <div className="mt-6 flex items-center gap-3 rounded-md bg-bg p-3 text-sm"><Avatar name="Chris Delgado" size={34} tone="orange" /><div><div className="font-medium">Chris Delgado</div><div className="text-muted">Bayou City Homes · next update due in 6 days</div></div><TrustRing score={94} size={36} /></div>
          <Button variant="secondary" size="sm" className="mt-4"><FileText size={15} />Agreement PDF</Button>
        </Card>
        <Card className="flex flex-col">
          <h2 className="border-b border-line p-5 font-semibold">Messages with Chris</h2>
          <ul className="flex-1 space-y-4 p-5">{thread.map((m, i) => (
            <li key={i} className={cn("flex max-w-[85%] flex-col", m.who === "sender" ? "ml-auto items-end" : "items-start")}>
              <div className={cn("rounded-lg px-4 py-2.5 text-[15px]", m.who === "sender" ? "rounded-br-sm bg-cobalt-500 text-white" : "rounded-bl-sm bg-orange-50 text-navy-900 dark:bg-orange-500/15 dark:text-white")}>{m.text}</div>
              <span className="mt-1 text-xs text-muted">{m.name} · {m.at}</span></li>))}</ul>
          <form className="flex gap-2 border-t border-line p-3" onSubmit={e => { e.preventDefault(); if (msg.trim()) { setThread([...thread, { who: "sender", name: "Jordan Hale", text: msg, at: "Just now" }]); setMsg(""); } }}>
            <button type="button" className="rounded-md p-2 text-muted hover:bg-bg" aria-label="Attach file"><Paperclip size={18} /></button>
            <Input value={msg} onChange={e => setMsg(e.target.value)} placeholder="Message Chris" aria-label="Message" /><Button type="submit" aria-label="Send message"><SendIcon size={16} /></Button>
          </form>
        </Card>
      </div>
      {share && (
        <div className="fixed inset-0 z-50 flex items-end justify-center bg-navy-950/50 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="sh">
          <Card className="w-full max-w-md p-6 shadow-lg">
            <div className="flex items-center"><h2 id="sh" className="text-lg font-semibold">Share progress with your client</h2><button className="ml-auto rounded p-1 hover:bg-bg" onClick={() => setShare(false)} aria-label="Close"><X size={18} /></button></div>
            <p className="mt-1 text-sm text-muted">A read-only page showing their Houston agent and the steps so far, branded as coming from you. No fees or private notes are shown.</p>
            <div className="mt-4 flex gap-2"><Input readOnly value="teamtoteamreferrals.com/c/8fK2qW" aria-label="Status link" /><Button variant="secondary" aria-label="Copy link"><Copy size={16} /></Button></div>
            <div className="mt-4 flex gap-2"><Button className="flex-1"><Phone size={16} />Text to Tom</Button><Button variant="secondary" className="flex-1" href="#portal">Preview page</Button></div>
          </Card>
        </div>)}
    </Shell>
  );
}
