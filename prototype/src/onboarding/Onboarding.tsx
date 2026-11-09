import { useEffect, useRef, useState } from "react";
import { Check, Upload, Play, Pause, Captions, CreditCard, MapPin, ShieldCheck, Send, ArrowRight, FileText, Lock } from "lucide-react";
import { Button, Card, Field, Input, Select, Textarea, Badge, Logo, Checkmark, cn } from "../ui/kit";
import { Shell } from "../app/Shell";
import { groups } from "../data/mock";

const steps = ["Team profile", "Licenses", "Brokerage W-9", "Card", "Group and city", "Review"];

export function Wizard() {
  const [i, setI] = useState(0);
  const next = () => (i < steps.length - 1 ? setI(i + 1) : (location.hash = "training"));
  return (
    <div className="min-h-screen bg-bg">
      <header className="border-b border-line bg-surface"><div className="mx-auto flex h-16 max-w-3xl items-center px-5"><Logo className="h-8 w-auto" /><span className="ml-auto text-sm text-muted">Step {i + 1} of {steps.length}</span></div></header>
      <div className="mx-auto max-w-3xl px-5 py-8">
        <ol className="mb-8 grid grid-cols-6 gap-1.5" aria-label="Progress">
          {steps.map((s, n) => <li key={s} title={s} className={cn("h-1.5 rounded-full", n <= i ? "bg-primary" : "bg-line")}><span className="sr-only">{s}{n < i ? " (done)" : n === i ? " (current)" : ""}</span></li>)}
        </ol>
        <Card className="p-6 sm:p-8">
          <h1 className="display font-display text-2xl font-bold">{steps[i]}</h1>
          <div className="mt-6 space-y-5">
            {i === 0 && <>
              <p className="text-muted">This is what other teams see on your profile and before they send you a referral.</p>
              <div className="flex items-center gap-4"><span className="flex h-16 w-16 items-center justify-center rounded-full border-2 border-dashed border-line text-muted"><Upload size={20} /></span><Button variant="secondary" size="sm">Upload logo or photo</Button></div>
              <Field label="Team name"><Input defaultValue="Red Rock Realty Group" /></Field>
              <Field label="About your team" hint="Two or three sentences. What you’re known for and how fast you respond."><Textarea defaultValue="Southern Utah’s top buyer team. Twelve agents across St. George, Hurricane and Cedar City." /></Field>
              <div className="grid gap-4 sm:grid-cols-2"><Field label="Website"><Input placeholder="https://" /></Field><Field label="Languages"><Input defaultValue="English, Spanish" /></Field></div>
            </>}
            {i === 1 && <>
              <p className="text-muted">Add a license for every state where you’ll hold a city. We check each one against the state’s public record, usually within one business day.</p>
              <Card className="space-y-4 bg-bg p-5">
                <div className="grid gap-4 sm:grid-cols-3"><Field label="State"><Select><option>Utah</option></Select></Field><Field label="License number"><Input defaultValue="10293847-SA00" /></Field><Field label="Expires"><Input type="date" defaultValue="2027-08-31" /></Field></div>
                <div className="grid gap-4 sm:grid-cols-2"><Field label="Brokerage"><Input defaultValue="Red Rock Realty Group LLC" /></Field><Field label="Broker of record"><Input defaultValue="Dana Whitfield" /></Field></div>
                <Field label="Broker license number"><Input defaultValue="5512098-PB00" /></Field>
              </Card>
              <Button variant="secondary" size="sm">Add another state</Button>
            </>}
            {i === 2 && <>
              <p className="text-muted">Brokerages that pay you a referral fee usually ask for your brokerage’s W-9 first. Upload it once and it’s shared only with brokerages that owe you a fee.</p>
              <label className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-line p-10 text-center hover:border-primary"><FileText className="text-muted" /><span className="font-medium">Drop the W-9 PDF here, or browse</span><span className="text-sm text-muted">PDF up to 10 MB</span><input type="file" accept="application/pdf" className="sr-only" /></label>
              <button className="text-sm font-medium text-primary" onClick={next}>I’ll do this later</button>
            </>}
            {i === 3 && <>
              <p className="text-muted">Joining and your first city are free. A card is needed only to accept referrals — a flat fee from $20 to $45 depending on the lead.</p>
              <Field label="Card"><div className="relative"><CreditCard size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-subtle" /><Input className="pl-10" placeholder="Card number" inputMode="numeric" /></div></Field>
              <div className="grid grid-cols-2 gap-4"><Field label="Expiry"><Input placeholder="MM / YY" /></Field><Field label="Security code"><Input placeholder="CVC" /></Field></div>
              <p className="flex items-center gap-2 text-sm text-muted"><Lock size={14} />Processed by Stripe. Card details never touch our servers.</p>
            </>}
            {i === 4 && <>
              <p className="text-muted">Choose a group and the city you want to hold. The group owner reviews your application.</p>
              <Field label="Group"><Select>{groups.filter(g => g.access === "Open").map(g => <option key={g.slug}>{g.name}</option>)}</Select></Field>
              <Field label="City"><Input defaultValue="St. George, UT" /></Field>
              <div className="flex items-center gap-3 rounded-md bg-[#ECFDF3] p-4 text-sm text-success dark:bg-success/15 dark:text-[#6EE7A0]"><MapPin size={18} />St. George is open in Mountain West Alliance. Free during the pilot.</div>
            </>}
            {i === 5 && <ul className="divide-y divide-line">
              {[["Team profile", "Red Rock Realty Group"], ["Licenses", "Utah · pending verification"], ["Brokerage W-9", "Uploaded"], ["Card", "Visa ending 4417"], ["Group and city", "Mountain West Alliance · St. George, UT"]].map(([k, v]) => (
                <li key={k} className="flex items-center gap-3 py-3"><Checkmark /><span className="font-medium">{k}</span><span className="ml-auto text-right text-sm text-muted">{v}</span></li>))}
            </ul>}
          </div>
          <div className="mt-8 flex items-center gap-3">
            {i > 0 && <Button variant="ghost" onClick={() => setI(i - 1)}>Back</Button>}
            <Button className="ml-auto" onClick={next}>{i === steps.length - 1 ? "Submit application" : "Continue"}<ArrowRight size={16} /></Button>
          </div>
        </Card>
      </div>
    </div>
  );
}

/** Training video gate: cannot skip ahead; completion unlocks the portal. */
export function TrainingGate() {
  const LENGTH = 24; // seconds in this prototype (real video ~4 minutes)
  const [t, setT] = useState(0);
  const [playing, setPlaying] = useState(false);
  const [cc, setCc] = useState(true);
  const timer = useRef<number>();
  useEffect(() => {
    if (!playing) return;
    timer.current = window.setInterval(() => setT(x => { if (x >= LENGTH) { setPlaying(false); return LENGTH; } return x + 1; }), 1000);
    return () => window.clearInterval(timer.current);
  }, [playing]);
  const done = t >= LENGTH;
  const captions = ["Welcome to Mountain West Alliance.", "Every referral has a deadline set by the group.", "Mark each lead Hot, Warm or Future.", "Accepting signs the agreement for both brokerages.", "Post an update at least every seven days.", "Miss two deadlines and your city goes to the backup team."];
  const fmt = (s: number) => `0:${String(s).padStart(2, "0")}`;
  return (
    <div className="min-h-screen bg-navy-950 text-white">
      <div className="mx-auto max-w-4xl px-5 py-10">
        <Logo className="h-8 w-auto text-white" />
        <h1 className="display mt-8 font-display text-3xl font-bold">Before you start: how this group works</h1>
        <p className="mt-2 text-[#AAB4CE]">A short video from Jordan Hale, your group owner. Watch to the end to unlock your dashboard.</p>
        <div className="relative mt-6 aspect-video overflow-hidden rounded-xl bg-navy-900">
          <div className="absolute inset-0 flex items-center justify-center">
            <svg viewBox="0 0 320 120" className="w-2/3 opacity-90" aria-hidden><path d="M40 90 C120 0, 200 0, 280 84" fill="none" stroke="#26304D" strokeWidth="3" strokeDasharray="2 8" /><path d="M40 90 C120 0, 200 0, 280 84" fill="none" stroke="url(#tg)" strokeWidth="4" strokeLinecap="round" strokeDasharray="300" strokeDashoffset={300 - (t / LENGTH) * 300} /><defs><linearGradient id="tg" x1="0" x2="1"><stop stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs><circle cx="40" cy="90" r="8" fill="#2451F5" /><circle cx="280" cy="84" r="8" fill={done ? "#FF7A1A" : "#26304D"} /></svg>
          </div>
          {!playing && !done && <button onClick={() => setPlaying(true)} className="absolute inset-0 m-auto flex h-20 w-20 items-center justify-center rounded-full bg-white/95 text-navy-900 shadow-lg" aria-label={t ? "Resume video" : "Play video"}><Play size={32} fill="currentColor" /></button>}
          {cc && (playing || t > 0) && <p className="absolute inset-x-0 bottom-14 mx-auto w-fit rounded bg-black/70 px-3 py-1 text-center text-[15px]">{captions[Math.min(captions.length - 1, Math.floor((t / LENGTH) * captions.length))]}</p>}
          <div className="absolute inset-x-0 bottom-0 flex items-center gap-3 bg-gradient-to-t from-black/70 px-4 py-3">
            <button onClick={() => setPlaying(!playing)} disabled={done} aria-label={playing ? "Pause" : "Play"}>{playing ? <Pause size={20} /> : <Play size={20} />}</button>
            <div className="relative h-1.5 flex-1 rounded-full bg-white/20" role="progressbar" aria-valuemin={0} aria-valuemax={LENGTH} aria-valuenow={t} aria-label="Video progress (skipping ahead is disabled)"><div className="h-full rounded-full bg-orange-500" style={{ width: `${(t / LENGTH) * 100}%` }} /></div>
            <span className="tabular text-sm">{fmt(t)} / {fmt(LENGTH)}</span>
            <button onClick={() => setCc(!cc)} aria-pressed={cc} aria-label="Captions" className={cn(cc ? "text-white" : "text-white/50")}><Captions size={20} /></button>
          </div>
        </div>
        <div className="mt-6 flex flex-col gap-4 sm:flex-row sm:items-center">
          <p className="text-sm text-[#AAB4CE]">{done ? "Done. Your dashboard is unlocked." : "Skipping ahead is turned off for this video. You can pause and come back."}</p>
          <Button variant="accent" className="sm:ml-auto" disabled={!done} href={done ? "#welcome" : undefined}>Go to my dashboard<ArrowRight size={16} /></Button>
        </div>
        <button className="mt-6 text-sm text-[#AAB4CE] underline" onClick={() => { setT(LENGTH); setPlaying(false); }}>Prototype only: jump to end</button>
      </div>
    </div>
  );
}

/** First-run dashboard: checklist and guided empty states. */
export function Welcome() {
  const items = [
    ["Create your team profile", true], ["Verify your Utah license", true], ["Watch the group training", true],
    ["Upload your brokerage W-9", false], ["Send your first referral", false], ["Share your “Refer to me” page", false],
  ] as const;
  const doneCount = items.filter(x => x[1]).length;
  const pct = doneCount / items.length, r = 30, c = 2 * Math.PI * r;
  return (
    <Shell current="welcome" title="Welcome, Jordan">
      <div className="grid gap-6 xl:grid-cols-[1fr_360px]">
        <div className="space-y-6">
          <Card className="flex flex-col gap-5 p-6 sm:flex-row sm:items-center">
            <svg width="76" height="76" className="-rotate-90 shrink-0" aria-hidden><circle cx="38" cy="38" r={r} fill="none" stroke="rgb(var(--line))" strokeWidth="7" /><circle cx="38" cy="38" r={r} fill="none" stroke="#2451F5" strokeWidth="7" strokeLinecap="round" strokeDasharray={`${c * pct} ${c}`} /></svg>
            <div><h2 className="text-lg font-semibold">Your seat in St. George is live</h2><p className="text-muted">{doneCount} of {items.length} setup steps done. Finish the rest to start earning referral fees.</p></div>
          </Card>
          <Card>
            <ul className="divide-y divide-line">
              {items.map(([label, done]) => (
                <li key={label} className="flex items-center gap-3 p-4">
                  {done ? <Checkmark /> : <span className="h-5 w-5 rounded-full border-2 border-line" />}
                  <span className={cn("font-medium", done && "text-muted line-through")}>{label}</span>
                  {!done && <Button size="sm" variant={label.startsWith("Send") ? "primary" : "secondary"} className="ml-auto" href={label.startsWith("Send") ? "#send" : undefined}>{label.startsWith("Send") ? "Send" : label.startsWith("Upload") ? "Upload" : "Share"}</Button>}
                </li>))}
            </ul>
          </Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Card className="p-6"><Send className="text-primary" /><h3 className="mt-3 font-semibold">No referrals sent yet</h3><p className="mt-1 text-sm text-muted">Got a lead outside Southern Utah? Send it to a verified team in under a minute.</p><Button size="sm" className="mt-4" href="#send">Send a referral</Button></Card>
            <Card className="p-6"><ShieldCheck className="text-orange-600" /><h3 className="mt-3 font-semibold">No referrals received yet</h3><p className="mt-1 text-sm text-muted">Teams in other cities can now send you St. George leads. We’ll text you the moment one arrives.</p><Button size="sm" variant="secondary" className="mt-4" href="#pro">Preview your profile</Button></Card>
          </div>
        </div>
        <Card className="h-fit p-6">
          <h2 className="font-semibold">Your group</h2>
          <div className="mt-4 flex items-center gap-3"><span className="flex h-11 w-11 items-center justify-center rounded-lg bg-cobalt-500 font-bold text-white">MW</span><div><div className="font-medium">Mountain West Alliance</div><div className="text-sm text-muted">96 teams · 44 open cities</div></div></div>
          <dl className="mt-5 space-y-2 text-sm">{[["Accept deadline", "24 hours"], ["Hot lead first call", "2 hours"], ["Updates", "every 7 days"], ["Default fee", "30%"]].map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-muted">{k}</dt><dd className="font-medium">{v}</dd></div>)}</dl>
          <Badge tone="success" className="mt-5">Free during pilot</Badge>
        </Card>
      </div>
    </Shell>
  );
}
