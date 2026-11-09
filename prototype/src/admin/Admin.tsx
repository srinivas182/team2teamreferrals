import { useState } from "react";
import { GripVertical, Plus, Eye, Monitor, Smartphone, History, Type, Image as ImageIcon, ListOrdered, BarChart3, Quote, HelpCircle, Megaphone, Map as MapIcon, LayoutGrid, Trash2, ExternalLink, Check, X, AlertTriangle, ShieldCheck, Filter, Download, Search, Lock } from "lucide-react";
import { AdminShell } from "./AdminShell";
import { Card, Button, Badge, TempBadge, Avatar, Field, Input, Select, Textarea, money, cn } from "../ui/kit";
import { Kpi, Segmented } from "../ui/data";

/* ---------------- Network dashboard ---------------- */
export function AdminDashboard() {
  const rev = [{ m: "Jun", a: 4.1, s: 0, p: 0.6 }, { m: "Jul", a: 5.2, s: 0, p: 0.9 }, { m: "Aug", a: 6.8, s: 0, p: 1.2 }, { m: "Sep", a: 7.9, s: 0, p: 1.5 }, { m: "Oct", a: 9.6, s: 0, p: 1.9 }, { m: "Nov", a: 11.2, s: 0, p: 2.2 }];
  const max = Math.max(...rev.map(r => r.a + r.s + r.p));
  return (
    <AdminShell current="admin" title="Network" actions={<><Select className="h-9 w-40 text-sm" aria-label="Period"><option>Last 6 months</option><option>Year to date</option></Select><Button size="sm" variant="secondary"><Download size={15} />Export</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label="Verified teams" value="640" change={9} spark={[480, 512, 551, 590, 618, 640]} />
        <Kpi label="Referrals this month" value="1,312" change={14} spark={[820, 910, 1010, 1120, 1150, 1312]} />
        <Kpi label="Revenue this month" value={money(13400)} change={17} hint="Seats free during pilot" />
        <Kpi label="SLA breaches (30 days)" value="41" change={-12} lowerIsBetter />
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
        <Card className="p-5">
          <div className="flex items-center justify-between"><h2 className="font-semibold">Revenue by source</h2><span className="text-sm text-muted">$ thousands</span></div>
          <div className="mt-6 flex h-52 items-end gap-5" role="img" aria-label="Monthly revenue by source">{rev.map(r => (
            <div key={r.m} className="flex flex-1 flex-col items-center gap-2"><span className="text-xs tabular text-muted">{(r.a + r.p).toFixed(1)}</span>
              <div className="flex w-full max-w-[40px] flex-col-reverse overflow-hidden rounded-t" style={{ height: `${((r.a + r.s + r.p) / max) * 170}px` }}><div className="bg-cobalt-500" style={{ height: `${(r.a / (r.a + r.p)) * 100}%` }} /><div className="bg-orange-500" style={{ height: `${(r.p / (r.a + r.p)) * 100}%` }} /></div>
              <span className="text-xs text-muted">{r.m}</span></div>))}</div>
          <div className="mt-3 flex flex-wrap gap-4 text-sm"><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-sm bg-cobalt-500" />Accepted-referral fees</span><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-sm bg-orange-500" />Partner listings</span><span className="text-muted">City subscriptions start after pilot</span></div>
        </Card>
        <div className="space-y-6">
          <Card className="p-5"><h2 className="font-semibold">Needs attention</h2><ul className="mt-3 divide-y divide-line text-sm">
            {[["12 licenses waiting for verification", "license-queue", "warning"], ["3 fee disputes open", "network-crm", "danger"], ["5 lead outcome mismatches", "network-crm", "danger"], ["Insurance industry: legal review missing", "industry-builder", "neutral"]].map(([t, h, tone]) => <li key={t}><a href={`#${h}`} className="flex items-center gap-3 py-3 hover:text-primary"><span className={cn("h-2 w-2 rounded-full", tone === "danger" ? "bg-danger" : tone === "warning" ? "bg-warning" : "bg-navy-400")} />{t}</a></li>)}</ul></Card>
          <Card className="p-5"><h2 className="font-semibold">SEO and AEO health</h2><dl className="mt-3 grid grid-cols-2 gap-3 text-sm">{[["Indexed pages", "1,904"], ["Sitemap pinged", "6 min ago"], ["Rich results valid", "100%"], ["AI crawler visits (7 d)", "3,410"]].map(([k, v]) => <div key={k} className="rounded-md bg-bg p-3"><dt className="text-muted">{k}</dt><dd className="font-semibold tabular">{v}</dd></div>)}</dl></Card>
        </div>
      </div>
      <Card className="mt-6 overflow-x-auto"><h2 className="p-5 pb-0 font-semibold">Groups</h2>
        <table className="mt-3 w-full min-w-[640px] text-sm"><thead className="text-left text-muted"><tr>{["Group", "Owner", "Seats", "Referrals (30 d)", "Median accept", "Breaches"].map(h => <th key={h} className="px-5 py-2 font-medium">{h}</th>)}</tr></thead>
          <tbody>{[["Sun Belt Top Teams", "Maria Alvarez", "214/260", 512, "14 min", 9], ["Relocation Pros Network", "Derek Owens", "131/220", 288, "21 min", 12], ["Mountain West Alliance", "Jordan Hale", "96/140", 118, "19 min", 7], ["First Home Specialists", "Lena Brooks", "77/150", 164, "26 min", 8]].map(r => <tr key={r[0] as string} className="border-t border-line"><td className="px-5 py-3 font-medium">{r[0]}</td><td className="px-5 py-3 text-muted">{r[1]}</td><td className="px-5 py-3 tabular">{r[2]}</td><td className="px-5 py-3 tabular">{r[3]}</td><td className="px-5 py-3 tabular">{r[4]}</td><td className="px-5 py-3 tabular">{r[5]}</td></tr>)}</tbody></table></Card>
    </AdminShell>
  );
}

/* ---------------- Industry builder ---------------- */
type Fld = { label: string; type: string; required: boolean };
export function IndustryBuilder() {
  const [tab, setTab] = useState("fields");
  const [fields, setFields] = useState<Fld[]>([
    { label: "Policy type", type: "Dropdown", required: true }, { label: "Coverage needed", type: "Multi-select", required: true },
    { label: "Current carrier", type: "Text", required: false }, { label: "Renewal date", type: "Date", required: true }, { label: "Household size", type: "Number", required: false },
  ]);
  const [reviewed, setReviewed] = useState(false);
  return (
    <AdminShell current="industry-builder" title="Industries" actions={<Button size="sm"><Plus size={15} />New industry</Button>}>
      <div className="grid gap-6 lg:grid-cols-[260px_1fr]">
        <Card className="h-fit p-2">{[["Real estate", "Live", "success"], ["Insurance", "Draft", "neutral"], ["Home services", "Draft", "neutral"]].map(([n, s, t]) => (
          <button key={n} className={cn("flex w-full items-center justify-between rounded-md px-3 py-2.5 text-left font-medium", n === "Insurance" ? "bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200" : "hover:bg-bg")}>{n}<Badge tone={t as "success"}>{s}</Badge></button>))}</Card>
        <div>
          <Card className="p-5">
            <div className="flex flex-wrap items-center gap-3"><h2 className="text-xl font-semibold">Insurance</h2><Badge>Draft</Badge><span className="ml-auto text-sm text-muted">Groups and public pages stay hidden until Live</span></div>
            <div className="mt-4 flex flex-wrap gap-1 border-b border-line">{[["fields", "Referral form"], ["licensing", "Licensing"], ["fees", "Fees"], ["territory", "Territory"], ["templates", "Templates"], ["compliance", "Compliance"]].map(([k, l]) => (
              <button key={k} onClick={() => setTab(k)} className={cn("-mb-px border-b-2 px-3 py-2 text-sm font-medium", tab === k ? "border-primary text-ink" : "border-transparent text-muted hover:text-ink")}>{l}</button>))}</div>
            {tab === "fields" && <div className="mt-5 grid gap-6 2xl:grid-cols-[1fr_320px]">
              <div><ul className="space-y-2">{fields.map((f, i) => (
                <li key={i} className="flex flex-wrap items-center gap-3 rounded-md border border-line bg-surface p-3"><GripVertical size={18} className="cursor-grab text-subtle" aria-label="Drag to reorder" /><Input value={f.label} onChange={e => setFields(fields.map((x, j) => j === i ? { ...x, label: e.target.value } : x))} className="h-9 min-w-[180px] flex-1" aria-label="Field label" />
                  <Select value={f.type} onChange={e => setFields(fields.map((x, j) => j === i ? { ...x, type: e.target.value } : x))} className="h-9 w-36" aria-label="Field type">{["Text", "Number", "Range", "Dropdown", "Multi-select", "Date", "File"].map(t => <option key={t}>{t}</option>)}</Select>
                  <label className="flex items-center gap-1.5 text-sm"><input type="checkbox" checked={f.required} onChange={() => setFields(fields.map((x, j) => j === i ? { ...x, required: !x.required } : x))} />Required</label>
                  <button onClick={() => setFields(fields.filter((_, j) => j !== i))} className="rounded p-1 text-muted hover:text-danger" aria-label={`Remove ${f.label}`}><Trash2 size={16} /></button></li>))}</ul>
                <Button size="sm" variant="secondary" className="mt-3" onClick={() => setFields([...fields, { label: "New field", type: "Text", required: false }])}><Plus size={15} />Add field</Button>
                <p className="mt-3 text-sm text-muted">Temperature, contact details and consent are added to every industry automatically.</p></div>
              <Card className="h-fit bg-bg p-4"><div className="mb-3 flex items-center gap-2 text-sm font-medium text-muted"><Eye size={15} />Preview: send referral</div><div className="space-y-3">{fields.map((f, i) => <Field key={i} label={f.label + (f.required ? "" : " (optional)")}>{f.type === "Dropdown" || f.type === "Multi-select" ? <Select className="h-9"><option>Choose…</option></Select> : <Input className="h-9" type={f.type === "Date" ? "date" : f.type === "Number" ? "number" : "text"} />}</Field>)}</div></Card>
            </div>}
            {tab === "licensing" && <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="License required"><Select><option>Yes</option><option>No</option></Select></Field><Field label="License type label"><Input defaultValue="Insurance producer license (NPN)" /></Field><Field label="Verification method"><Select><option>Manual review queue</option><option>Data feed (later)</option></Select></Field><Field label="Issuing authority"><Input defaultValue="State department of insurance" /></Field></div>}
            {tab === "fees" && <div className="mt-5 space-y-3">{[["Flat fee between members", false], ["Percentage between members", false], ["Reciprocity credits only", true]].map(([l, on]) => <label key={l as string} className="flex items-center justify-between rounded-md border border-line p-4"><span className="font-medium">{l}</span><input type="checkbox" defaultChecked={on as boolean} /></label>)}<p className="text-sm text-muted">Insurance referral payments are restricted in many states. Keep reciprocity-only until legal review confirms otherwise.</p></div>}
            {tab === "territory" && <div className="mt-5"><Segmented label="Territory unit" value="county" onChange={() => {}} options={[["zip", "ZIP"], ["city", "City"], ["county", "County"], ["state", "State"], ["national", "National"]]} /></div>}
            {tab === "templates" && <ul className="mt-5 grid gap-3 sm:grid-cols-2">{["CRM stages (6)", "Referral agreement", "Email and SMS (24)", "Terminology (agency, client)", "Training video", "Public page copy"].map(t => <li key={t} className="flex items-center justify-between rounded-md border border-line p-4"><span className="font-medium">{t}</span><Button size="sm" variant="ghost">Edit</Button></li>)}</ul>}
            {tab === "compliance" && <div className="mt-5 space-y-4">
              <div className="flex items-start gap-3 rounded-md bg-[#FEF9E7] p-4 text-sm text-warning dark:bg-warning/15 dark:text-[#FCD34D]"><AlertTriangle size={18} className="mt-0.5 shrink-0" />This industry can't go live until a legal review is recorded.</div>
              <div className="grid gap-4 sm:grid-cols-2"><Field label="Reviewed by"><Input placeholder="Attorney or firm" /></Field><Field label="Review date"><Input type="date" /></Field></div>
              <Field label="Findings and conditions"><Textarea placeholder="e.g. Referral fees only between licensed producers in states that allow them" /></Field>
              <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={reviewed} onChange={() => setReviewed(!reviewed)} />Legal review completed and attached</label>
              <Button disabled={!reviewed}><Lock size={15} />Switch Insurance to Live</Button>
            </div>}
          </Card>
        </div>
      </div>
    </AdminShell>
  );
}

/* ---------------- Website builder ---------------- */
const blocks = [[LayoutGrid, "Hero"], [ListOrdered, "How it works"], [BarChart3, "Stats band"], [MapIcon, "Network map"], [Quote, "Testimonials"], [HelpCircle, "FAQ"], [Megaphone, "CTA band"], [Type, "Rich text"], [ImageIcon, "Image and text"]] as const;
export function SiteBuilder() {
  const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
  const [sel, setSel] = useState(0);
  const [headline, setHeadline] = useState("Your out-of-area leads, handed to teams you trust.");
  const [cta, setCta] = useState("Claim your city free");
  const page = ["Hero", "How it works", "Stats band", "CTA band"];
  return (
    <AdminShell current="site-builder" title="Website builder" bare>
      <div className="flex flex-wrap items-center gap-3 border-b border-line bg-surface px-4 py-3">
        <Select className="h-9 w-48" aria-label="Page"><option>Home</option><option>Pricing</option><option>City page template</option></Select><Badge tone="warning">Draft changes</Badge>
        <div className="ml-auto flex items-center gap-2"><Segmented label="Preview device" value={device} onChange={setDevice} options={[["desktop", "Desktop"], ["mobile", "Mobile"]]} /><Button size="sm" variant="ghost"><History size={15} />Versions</Button><Button size="sm" variant="secondary"><Eye size={15} />Preview</Button><Button size="sm">Publish</Button></div>
      </div>
      <div className="grid min-h-[calc(100vh-130px)] lg:grid-cols-[220px_1fr_300px]">
        <aside className="hidden border-r border-line bg-surface p-3 lg:block"><h2 className="px-2 pb-2 text-xs font-semibold text-subtle">Sections</h2>{blocks.map(([I, l]) => <button key={l} draggable className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-left text-sm hover:bg-bg"><I size={16} className="text-muted" />{l}<Plus size={14} className="ml-auto text-subtle" /></button>)}<p className="mt-4 px-2 text-xs text-muted">25+ sections, each with light and dark variants and default copy.</p></aside>
        <main className="overflow-auto bg-navy-100/60 p-4 dark:bg-navy-950 sm:p-8">
          <div style={device === "desktop" ? { zoom: 0.55 } : undefined} className={cn("mx-auto overflow-hidden rounded-lg bg-white text-navy-900 shadow-lg", device === "mobile" ? "max-w-[375px]" : "w-[960px] max-w-none")}>
            {page.map((b, i) => (
              <section key={b} onClick={() => setSel(i)} className={cn("relative cursor-pointer border-2", sel === i ? "border-cobalt-500" : "border-transparent hover:border-cobalt-200")}>
                {sel === i && <span className="absolute left-2 top-2 z-10 rounded bg-cobalt-500 px-2 py-0.5 text-xs font-semibold text-white">{b}</span>}
                {b === "Hero" && <div className={cn("grid gap-6 p-8", device === "desktop" && "grid-cols-2 items-center p-12")}><div><h3 className={cn("font-display font-extrabold leading-tight tracking-tight", device === "desktop" ? "text-4xl" : "text-2xl")}>{headline}</h3><p className="mt-3 text-[#5B6785]">Send out-of-area leads to vetted teams in under a minute.</p><span className="mt-5 inline-block rounded-md bg-[#2451F5] px-4 py-2.5 text-sm font-semibold text-white">{cta}</span></div><div className="h-40 rounded-lg bg-gradient-to-br from-cobalt-100 to-orange-100" /></div>}
                {b === "How it works" && <div className="grid gap-4 bg-[#F6F7FB] p-8 sm:grid-cols-3">{["Join and claim", "Send the lead", "Track to closing"].map((t, n) => <div key={t}><span className="flex h-8 w-8 items-center justify-center rounded-full bg-cobalt-100 font-bold text-cobalt-700">{n + 1}</span><div className="mt-2 font-semibold">{t}</div></div>)}</div>}
                {b === "Stats band" && <div className="grid grid-cols-2 gap-4 bg-[#0E1B3D] p-8 text-white sm:grid-cols-4">{[["640", "Teams"], ["31", "States"], ["9,498", "Referrals"], ["16 min", "To accept"]].map(([v, k]) => <div key={k}><div className="font-display text-2xl font-bold">{v}</div><div className="text-sm text-[#AAB4CE]">{k}</div></div>)}</div>}
                {b === "CTA band" && <div className="m-6 rounded-lg bg-[#2451F5] p-8 text-white"><div className="font-display text-xl font-bold">Your city might still be open.</div><span className="mt-3 inline-block rounded-md bg-[#FF7A1A] px-4 py-2 text-sm font-semibold text-[#0E1B3D]">Check your city</span></div>}
              </section>))}
          </div>
        </main>
        <aside className="border-l border-line bg-surface p-5"><h2 className="font-semibold">{page[sel]}</h2>
          {sel === 0 ? <div className="mt-4 space-y-4"><Field label="Headline"><Textarea value={headline} onChange={e => setHeadline(e.target.value)} /></Field><Field label="Button text"><Input value={cta} onChange={e => setCta(e.target.value)} /></Field><Field label="Button link"><Select><option>Sign up</option><option>Group directory</option></Select></Field><Field label="Image"><Button size="sm" variant="secondary"><ImageIcon size={15} />Replace image</Button></Field><Field label="Style"><Segmented label="Style" value="light" onChange={() => {}} options={[["light", "Light"], ["dark", "Dark"]]} /></Field></div>
            : <p className="mt-2 text-sm text-muted">Select the hero to edit its text live. Every section has its own settings panel.</p>}
          <div className="mt-6 flex gap-2 border-t border-line pt-4"><Button size="sm" variant="ghost">Move up</Button><Button size="sm" variant="ghost">Duplicate</Button><Button size="sm" variant="ghost" className="text-danger">Remove</Button></div>
        </aside>
      </div>
    </AdminShell>
  );
}

/* ---------------- Network CRM ---------------- */
export function NetworkCrm() {
  const rows = [
    [4822, "Austin, TX", "Capitol Key Realty", "St. George, UT", "Red Rock Realty Group", "hot", "Awaiting acceptance", "neutral", 4200, ""],
    [4821, "St. George, UT", "Red Rock Realty Group", "Houston, TX", "Bayou City Homes", "hot", "Contacted", "info", 3638, ""],
    [4808, "Denver, CO", "Mile High Movers", "Reno, NV", "Silver State Partners", "warm", "Rerouted to backup", "warning", 2700, "SLA breach"],
    [4774, "St. George, UT", "Red Rock Realty Group", "Austin, TX", "Capitol Key Realty", "warm", "Closed · fee due", "warning", 3210, ""],
    [4752, "Tampa, FL", "Bay Area Living", "Atlanta, GA", "Peachtree Partners", "hot", "Disputed", "danger", 5100, "Fee dispute"],
    [4730, "Phoenix, AZ", "Desert Peak Group", "Las Vegas, NV", "Neon Valley Realty", "future", "Lost", "neutral", 1950, "Outcome mismatch"],
  ] as const;
  return (
    <AdminShell current="network-crm" title="Network CRM" actions={<Button size="sm" variant="secondary"><Download size={15} />Export CSV</Button>}>
      <Card className="mb-4 flex flex-wrap items-center gap-2 p-3"><Filter size={16} className="text-muted" />{["Real estate", "All groups", "All states", "All stages", "Last 30 days"].map(f => <Select key={f} className="h-9 w-auto text-sm" aria-label={f}><option>{f}</option></Select>)}<div className="relative ml-auto"><Search size={15} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-subtle" /><Input className="h-9 w-56 pl-8 text-sm" placeholder="Referral #, team, client" aria-label="Search" /></div></Card>
      <Card className="overflow-x-auto"><table className="w-full min-w-[900px] text-sm"><thead className="text-left text-muted"><tr>{["#", "From", "", "To", "Temp.", "Status", "Est. value", "Flag"].map((h, i) => <th key={i} className="px-4 py-3 font-medium">{h}</th>)}</tr></thead>
        <tbody>{rows.map(r => (
          <tr key={r[0]} className="border-t border-line hover:bg-bg"><td className="px-4 py-3 tabular text-muted">{r[0]}</td>
            <td className="px-4 py-3"><div className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-cobalt-500" /><div><div className="font-medium">{r[2]}</div><div className="text-muted">{r[1]}</div></div></div></td>
            <td className="px-1 text-muted">→</td>
            <td className="px-4 py-3"><div className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-orange-500" /><div><div className="font-medium">{r[4]}</div><div className="text-muted">{r[3]}</div></div></div></td>
            <td className="px-4 py-3"><TempBadge t={r[5]} /></td><td className="px-4 py-3"><Badge tone={r[7] as "info"}>{r[6]}</Badge></td><td className="px-4 py-3 tabular">{money(r[8])}</td>
            <td className="px-4 py-3">{r[9] && <Badge tone="danger"><AlertTriangle size={12} />{r[9]}</Badge>}</td></tr>))}</tbody></table></Card>
    </AdminShell>
  );
}

/* ---------------- License queue ---------------- */
export function LicenseQueue() {
  const items = [
    { name: "Nora Castillo", team: "Front Range Homes", state: "Colorado", num: "FA100087642", exp: "Mar 31, 2028", broker: "Front Range Homes LLC · ER100054321", waited: "3 h" },
    { name: "Wes Adler", team: "Magic Valley Realty", state: "Idaho", num: "SP58821", exp: "Jun 30, 2027", broker: "Magic Valley Realty · DB31877", waited: "9 h" },
    { name: "Priya Shah", team: "Lakeshore Living", state: "Illinois", num: "475.198832", exp: "Apr 30, 2026", broker: "Lakeshore Living Inc · 478.012345", waited: "1 d", expired: true },
  ];
  const [sel, setSel] = useState(0);
  const [result, setResult] = useState<Record<number, string>>({});
  const it = items[sel];
  return (
    <AdminShell current="license-queue" title="License verification" actions={<Badge tone="warning">12 waiting · oldest 1 day</Badge>}>
      <div className="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
        <Card className="divide-y divide-line">{items.map((x, i) => (
          <button key={x.num} onClick={() => setSel(i)} className={cn("flex w-full items-center gap-3 p-4 text-left", sel === i ? "bg-cobalt-50 dark:bg-cobalt-500/15" : "hover:bg-bg")}><Avatar name={x.name} size={36} /><div className="min-w-0 flex-1"><div className="font-medium">{x.name}</div><div className="text-sm text-muted">{x.team} · {x.state}</div></div>{result[i] ? <Badge tone={result[i] === "Verified" ? "success" : "danger"}>{result[i]}</Badge> : <span className="text-sm text-muted">{x.waited}</span>}</button>))}</Card>
        <Card className="p-6">
          <div className="flex items-center gap-3"><Avatar name={it.name} size={44} /><div><h2 className="text-lg font-semibold">{it.name}</h2><div className="text-sm text-muted">{it.team}</div></div></div>
          <dl className="mt-5 divide-y divide-line rounded-md border border-line text-sm">{[["State", it.state], ["License number", it.num], ["Expires", it.exp], ["Brokerage and broker license", it.broker]].map(([k, v]) => <div key={k} className="flex justify-between gap-4 p-3"><dt className="text-muted">{k}</dt><dd className={cn("text-right font-medium", k === "Expires" && it.expired && "text-danger")}>{v}{k === "Expires" && it.expired && " (expired)"}</dd></div>)}</dl>
          <a href="#license-queue" className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary"><ExternalLink size={14} />Open {it.state} public license lookup</a>
          <div className="mt-5 space-y-2 text-sm">{["Name matches the state record", "License is active and not expired", "Brokerage matches the record"].map((c, i) => <label key={c} className="flex items-center gap-2"><input type="checkbox" defaultChecked={!(it.expired && i === 1)} />{c}</label>)}</div>
          {it.expired && <div className="mt-4 flex items-start gap-2 rounded-md bg-[#FDECEE] p-3 text-sm text-danger dark:bg-danger/15 dark:text-[#FDA4AF]"><AlertTriangle size={16} className="mt-0.5 shrink-0" />The expiry date is in the past. Ask for the renewed license before verifying.</div>}
          <div className="mt-6 flex gap-2"><Button onClick={() => setResult({ ...result, [sel]: "Verified" })}><ShieldCheck size={16} />Verify</Button><Button variant="secondary" onClick={() => setResult({ ...result, [sel]: "Rejected" })}><X size={16} />Reject with reason</Button></div>
        </Card>
      </div>
    </AdminShell>
  );
}
