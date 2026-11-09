import { useEffect, useMemo, useRef, useState } from "react";
import { Search, Send, Inbox, KanbanSquare, Wallet, Users, FileText, CornerDownLeft, Bell, CheckCircle2, AlertTriangle, MessageSquare, DollarSign, UserPlus, Info, X, Loader2, Settings } from "lucide-react";
import { Card, Button, Badge, TempBadge, Avatar, TrustRing, Field, Input, Select, Textarea, cn } from "../ui/kit";
import { Kpi, Countdown, Segmented } from "../ui/data";

/* ---------------- Command palette ---------------- */
const commands = [
  { group: "Actions", icon: Send, label: "Send a referral", hint: "S", href: "send" },
  { group: "Actions", icon: Inbox, label: "Review referrals waiting for you", hint: "3", href: "inbox" },
  { group: "Go to", icon: KanbanSquare, label: "Pipeline", href: "pipeline" }, { group: "Go to", icon: Wallet, label: "Earnings", href: "earnings" },
  { group: "Go to", icon: Users, label: "Groups and network map", href: "my-groups" }, { group: "Go to", icon: Settings, label: "Billing", href: "billing" },
  { group: "Referrals", icon: FileText, label: "#4821 Tom & Ana Brennan → Houston", href: "referral" }, { group: "Referrals", icon: FileText, label: "#4822 Hot buyer from Austin", href: "inbox" },
  { group: "Teams", icon: Users, label: "Bayou City Homes · Houston, TX", href: "pro" }, { group: "Teams", icon: Users, label: "Capitol Key Realty · Austin, TX", href: "pro" },
];
export function CommandPalette({ onClose }: { onClose: () => void }) {
  const [q, setQ] = useState(""); const [i, setI] = useState(0);
  const ref = useRef<HTMLInputElement>(null);
  const list = useMemo(() => commands.filter(c => c.label.toLowerCase().includes(q.toLowerCase())), [q]);
  useEffect(() => { ref.current?.focus(); }, []);
  useEffect(() => setI(0), [q]);
  const go = (h: string) => { onClose(); location.hash = h; };
  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center bg-navy-950/50 p-4 pt-[12vh]" role="dialog" aria-modal="true" aria-label="Command palette" onClick={onClose}>
      <Card className="w-full max-w-xl overflow-hidden shadow-lg" onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-3 border-b border-line px-4"><Search size={18} className="text-subtle" />
          <input ref={ref} value={q} onChange={e => setQ(e.target.value)} placeholder="Search or jump to…" aria-label="Search commands" className="no-ring h-14 flex-1 bg-transparent text-[16px] outline-none placeholder:text-subtle"
            onKeyDown={e => { if (e.key === "ArrowDown") { e.preventDefault(); setI(Math.min(i + 1, list.length - 1)); } if (e.key === "ArrowUp") { e.preventDefault(); setI(Math.max(i - 1, 0)); } if (e.key === "Enter" && list[i]) go(list[i].href); if (e.key === "Escape") onClose(); }} />
          <kbd className="rounded border border-line px-1.5 text-xs text-muted">Esc</kbd></div>
        <ul className="max-h-[50vh] overflow-y-auto p-2" role="listbox">
          {list.map((c, n) => (<li key={c.label}>{(n === 0 || list[n - 1].group !== c.group) && <div className="px-3 pb-1 pt-3 text-xs font-semibold text-subtle">{c.group}</div>}
            <button role="option" aria-selected={n === i} onMouseEnter={() => setI(n)} onClick={() => go(c.href)} className={cn("flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left", n === i && "bg-cobalt-50 dark:bg-cobalt-500/15")}>
              <c.icon size={17} className="text-muted" /><span className="flex-1">{c.label}</span>{n === i ? <CornerDownLeft size={15} className="text-muted" /> : c.hint && <kbd className="rounded border border-line px-1.5 text-xs text-muted">{c.hint}</kbd>}</button></li>))}
          {list.length === 0 && <li className="p-6 text-center text-sm text-muted">No results for “{q}”. Try a client name, team or city.</li>}
        </ul>
        <div className="flex gap-4 border-t border-line px-4 py-2 text-xs text-muted"><span>↑↓ to move</span><span>Enter to open</span><span>Ctrl/⌘ K anywhere</span></div>
      </Card>
    </div>
  );
}

/* ---------------- Notifications ---------------- */
export const notes = [
  { icon: Inbox, tone: "text-orange-600", title: "New Hot referral from Hannah Price", body: "Buyer for St. George · est. $4,200 · accept within 24 hours", at: "8 min ago", unread: true, href: "inbox", day: "Today" },
  { icon: MessageSquare, tone: "text-primary", title: "Chris Delgado posted an update", body: "“Showings done. They're serious about the Oxford St house.”", at: "1 h ago", unread: true, href: "referral", day: "Today" },
  { icon: AlertTriangle, tone: "text-danger", title: "Weekly update overdue: Paul Wexler", body: "Post an update to avoid a breach", at: "3 h ago", unread: true, href: "pipeline", day: "Today" },
  { icon: UserPlus, tone: "text-success", title: "Nora Castillo applied for Pueblo, CO", body: "Mountain West Alliance · license verified", at: "3 h ago", unread: true, href: "applications", day: "Today" },
  { icon: DollarSign, tone: "text-success", title: "Closing report: Lucy Nguyen", body: "$3,210 due to you from Capitol Key Realty", at: "Nov 2", unread: false, href: "earnings", day: "Earlier" },
  { icon: CheckCircle2, tone: "text-success", title: "Your Utah license was verified", body: "St. George seat is now live", at: "Oct 28", unread: false, href: "dashboard", day: "Earlier" },
];
export function NotificationList({ compact }: { compact?: boolean }) {
  const days = Array.from(new Set(notes.map(n => n.day)));
  return <>{days.map(d => (<div key={d}><div className="px-4 pb-1 pt-3 text-xs font-semibold text-subtle">{d}</div><ul>{notes.filter(n => n.day === d).map(n => (
    <li key={n.title}><a href={`#${n.href}`} className="flex gap-3 px-4 py-3 hover:bg-bg"><n.icon size={18} className={cn("mt-0.5 shrink-0", n.tone)} /><div className="min-w-0 flex-1"><div className={cn("text-[15px]", n.unread && "font-semibold")}>{n.title}</div>{!compact && <div className="text-sm text-muted">{n.body}</div>}<div className="text-xs text-subtle">{n.at}</div></div>{n.unread && <span className="mt-2 h-2 w-2 shrink-0 rounded-full bg-orange-500" aria-label="Unread" />}</a></li>))}</ul></div>))}</>;
}
export function NotificationsPage() {
  const [f, setF] = useState<"all" | "unread">("all");
  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3"><Segmented label="Show" value={f} onChange={setF} options={[["all", "All"], ["unread", "Unread (4)"]]} /><Button size="sm" variant="ghost" className="ml-auto">Mark all as read</Button><Button size="sm" variant="secondary">Notification settings</Button></div>
      <Card className="pb-2"><NotificationList /></Card>
      <Card className="mt-6 overflow-x-auto p-5"><h2 className="font-semibold">How you're notified</h2>
        <table className="mt-3 w-full min-w-[520px] text-sm"><thead className="text-left text-muted"><tr>{["", "Email", "Text", "Push", "In app"].map(h => <th key={h} className="py-2 font-medium">{h}</th>)}</tr></thead>
          <tbody>{["New referral for you", "Deadline reminders", "Updates on referrals you sent", "Group announcements", "Billing"].map((r, i) => <tr key={r} className="border-t border-line"><td className="py-3">{r}</td>{[0, 1, 2, 3].map(c => <td key={c} className="py-3"><input type="checkbox" aria-label={`${r} by ${["email", "text", "push", "in app"][c]}`} defaultChecked={c === 3 || i < 2 || (i === 2 && c !== 1)} disabled={c === 3} /></td>)}</tr>)}</tbody></table>
        <p className="mt-3 text-sm text-muted">Non-urgent messages wait for your quiet hours (9 PM–7 AM Mountain Time).</p></Card>
    </div>
  );
}

/* ---------------- Component gallery ---------------- */
function Row({ title, children }: { title: string; children: React.ReactNode }) {
  return <section className="border-t border-line py-6 first:border-t-0"><h2 className="mb-4 text-sm font-semibold text-muted">{title}</h2><div className="flex flex-wrap items-center gap-3">{children}</div></section>;
}
export function Gallery() {
  const [seg, setSeg] = useState("week");
  return (
    <Card className="px-6">
      <Row title="Buttons"><Button>Primary</Button><Button variant="accent">Accent</Button><Button variant="secondary">Secondary</Button><Button variant="ghost">Ghost</Button><Button variant="danger">Danger</Button><Button disabled>Disabled</Button><Button><Loader2 size={16} className="animate-spin" />Sending…</Button><Button size="sm">Small</Button><Button size="lg">Large</Button></Row>
      <Row title="Inputs"><div className="grid w-full gap-4 sm:grid-cols-3"><Field label="Default" hint="Helpful hint text"><Input placeholder="Placeholder" /></Field><Field label="Error" error="Enter a valid US mobile number"><Input defaultValue="555" className="border-danger focus:border-danger focus:ring-danger/25" aria-invalid /></Field><Field label="Disabled"><Input disabled defaultValue="Read only" /></Field><Field label="Select"><Select><option>Real estate</option></Select></Field><Field label="Textarea" className="sm:col-span-2"><Textarea placeholder="Notes" /></Field></div></Row>
      <Row title="Choice"><label className="flex items-center gap-2"><input type="checkbox" defaultChecked />Checkbox</label><label className="flex items-center gap-2"><input type="radio" name="r" defaultChecked />Radio</label><Segmented label="Period" value={seg} onChange={setSeg} options={[["day", "Day"], ["week", "Week"], ["month", "Month"]]} /></Row>
      <Row title="Badges and status"><Badge>Neutral</Badge><Badge tone="success">Active</Badge><Badge tone="warning">Warned</Badge><Badge tone="danger">Deactivated</Badge><Badge tone="info">Contacted</Badge><Badge tone="cobalt">Sender</Badge><Badge tone="orange">Receiver</Badge><TempBadge t="hot" /><TempBadge t="warm" /><TempBadge t="future" /></Row>
      <Row title="Countdowns (SLA)"><Countdown minutesLeft={1200} total={1440} /><Countdown minutesLeft={600} total={1440} /><Countdown minutesLeft={60} total={1440} /><Countdown minutesLeft={0} overdue /></Row>
      <Row title="People and trust"><Avatar name="Jordan Hale" /><Avatar name="Chris Delgado" tone="orange" /><Avatar name="Group Owner" tone="navy" /><TrustRing score={94} /><TrustRing score={71} /><TrustRing score="new" /></Row>
      <Row title="KPI card"><div className="w-64"><Kpi label="Referral fees this year" value="$19,920" change={18} spark={[2, 3, 2, 5, 4, 6]} /></div><div className="w-64"><Kpi label="Median accept time" value="19 min" change={-8} lowerIsBetter /></div></Row>
      <Row title="Feedback"><div className="flex w-full max-w-md items-center gap-3 rounded-md border border-line bg-surface p-3 shadow-md" role="status"><CheckCircle2 className="text-success" size={18} /><span className="flex-1 text-sm">Referral sent to Houston.</span><button className="text-sm font-semibold text-primary">Undo</button><X size={16} className="text-muted" /></div>
        <div className="flex w-full items-start gap-3 rounded-md bg-[#EDF4FF] p-3 text-sm text-info dark:bg-info/15 dark:text-[#93C5FD]"><Info size={18} className="mt-0.5 shrink-0" />Your group's free period ends Feb 1, 2027.</div></Row>
      <Row title="Loading and empty"><div className="w-64 space-y-2 rounded-md border border-line p-4" aria-hidden><div className="h-3 w-2/3 animate-pulse rounded bg-line" /><div className="h-3 w-full animate-pulse rounded bg-line" /><div className="h-3 w-1/2 animate-pulse rounded bg-line" /></div>
        <div className="w-72 rounded-md border border-dashed border-line p-6 text-center"><Inbox className="mx-auto text-muted" /><div className="mt-2 font-semibold">No referrals yet</div><div className="text-sm text-muted">Send your first one in under a minute.</div><Button size="sm" className="mt-3">Send referral</Button></div></Row>
      <Row title="Notification bell"><button className="relative rounded-md border border-line p-2" aria-label="Notifications, 4 unread"><Bell size={20} /><span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-orange-500" /></button></Row>
      <p className="border-t border-line py-6 text-sm text-muted">Full inventory: 42 components in <code>docs/design/components.md</code>. Use the theme button to check every state in dark mode.</p>
    </Card>
  );
}
