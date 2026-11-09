import { useState } from "react";
import { Phone, MessageSquare, Mail, CheckCircle2, ShieldCheck, Star, CalendarDays } from "lucide-react";
import { Card, Button, Avatar, Badge, Logo, Input, cn } from "../ui/kit";

/** Read-only page for the referred client, branded as coming from the referring agent. */
export function ClientPortal() {
  const [answer, setAnswer] = useState<string | null>(null);
  const steps: [string, string, boolean][] = [["Introduced to Chris", "Tue, Nov 3", true], ["First call", "Tue, Nov 3", true], ["Home tours", "Sat, Nov 7 · 4 homes", true], ["Offer", "Next", false], ["Closing", "", false]];
  return (
    <div className="min-h-screen bg-bg">
      <header className="border-b border-line bg-surface"><div className="mx-auto flex h-16 max-w-2xl items-center gap-3 px-5"><Avatar name="Jordan Hale" size={34} /><div className="text-sm"><div className="font-semibold">From Jordan Hale</div><div className="text-muted">Red Rock Realty Group, St. George</div></div></div></header>
      <main className="mx-auto max-w-2xl space-y-5 px-5 py-8">
        <div><h1 className="display font-display text-3xl font-extrabold">Hi Tom and Ana, meet your agent in Houston</h1><p className="mt-2 text-muted">I've handed your move to a team I trust. Here's who they are and how things are going.</p></div>
        <Card className="p-5">
          <div className="flex items-center gap-4"><Avatar name="Chris Delgado" size={64} tone="orange" /><div><h2 className="text-xl font-semibold">Chris Delgado</h2><div className="text-muted">Bayou City Homes · Houston, TX</div><div className="mt-1 flex items-center gap-1 text-sm"><Star size={14} className="text-orange-500" fill="currentColor" />4.9 from referred clients</div></div></div>
          <div className="mt-4 flex flex-wrap gap-2"><Badge tone="success"><ShieldCheck size={13} />Licensed in Texas, verified</Badge><Badge tone="cobalt">Replies in about 11 minutes</Badge></div>
          <div className="mt-5 grid grid-cols-3 gap-2"><Button size="sm"><Phone size={15} />Call</Button><Button size="sm" variant="secondary"><MessageSquare size={15} />Text</Button><Button size="sm" variant="secondary"><Mail size={15} />Email</Button></div>
        </Card>
        <Card className="p-5"><h2 className="font-semibold">Your progress</h2>
          <ol className="mt-4 space-y-4">{steps.map(([t, d, done], i) => (
            <li key={t} className="flex items-start gap-3">{done ? <CheckCircle2 className="mt-0.5 shrink-0 text-success" size={20} /> : <span className={cn("mt-0.5 h-5 w-5 shrink-0 rounded-full border-2", i === 3 ? "border-orange-500" : "border-line")} />}<div><div className={cn("font-medium", !done && i !== 3 && "text-muted")}>{t}</div>{d && <div className="text-sm text-muted">{d}</div>}</div></li>))}</ol></Card>
        <Card className="p-5"><h2 className="flex items-center gap-2 font-semibold"><CalendarDays size={18} />Next with Chris</h2><p className="mt-2">Offer review call · Monday 10:00 AM</p></Card>
        <Card className="p-5"><h2 className="font-semibold">Quick check-in</h2><p className="mt-1 text-muted">Has Chris been in touch as often as you'd like?</p>
          {answer ? <p className="mt-3 rounded-md bg-bg p-3 text-sm" role="status">Thanks — your answer goes to Jordan and helps keep partner quality high.</p> : <div className="mt-3 flex flex-wrap gap-2">{["Yes, great", "Could be more", "Haven't heard back"].map(a => <Button key={a} size="sm" variant="secondary" onClick={() => setAnswer(a)}>{a}</Button>)}</div>}</Card>
        <footer className="flex flex-col items-center gap-2 pt-4 text-center text-xs text-muted"><Logo className="h-6 w-auto" /><span>Connected through Team to Team Referrals. Reply STOP to texts to opt out.</span></footer>
      </main>
    </div>
  );
}

/** Consumer request confirmation: verify the phone, then confirm. */
export function RequestDone() {
  const [verified, setVerified] = useState(false);
  return (
    <div className="flex min-h-screen items-center justify-center bg-bg px-5 py-10">
      <Card className="w-full max-w-md p-7">
        <Logo className="h-8 w-auto" />
        {!verified ? (<>
          <h1 className="display mt-6 font-display text-2xl font-bold">Check your phone</h1>
          <p className="mt-2 text-muted">We texted a 6-digit code to (713) ••• •142. Enter it so the Houston team knows your request is real.</p>
          <form className="mt-6" onSubmit={e => { e.preventDefault(); setVerified(true); }}>
            <div className="flex gap-2">{[...Array(6)].map((_, i) => <Input key={i} inputMode="numeric" maxLength={1} aria-label={`Digit ${i + 1}`} className="h-12 text-center text-lg font-semibold" defaultValue={"482913"[i]} />)}</div>
            <Button className="mt-5 w-full" type="submit">Confirm</Button>
          </form>
          <button className="mt-3 w-full text-sm text-muted hover:text-ink">Resend code in 0:42</button>
        </>) : (
          <div className="mt-6 text-center" role="status">
            <CheckCircle2 size={48} className="mx-auto text-success" />
            <h1 className="display mt-3 font-display text-2xl font-bold">You're all set</h1>
            <p className="mt-2 text-muted">Bayou City Homes in Houston has your request. Expect a call from a verified agent, usually within the hour.</p>
            <ul className="mt-6 space-y-2 text-left text-sm">{["We'll text you your agent's name and photo", "You'll get a link to follow your progress", "You can stop texts any time by replying STOP"].map(t => <li key={t} className="flex gap-2"><CheckCircle2 size={16} className="mt-0.5 shrink-0 text-success" />{t}</li>)}</ul>
            <Button variant="secondary" className="mt-6 w-full" href="#portal">Preview your progress page</Button>
          </div>)}
      </Card>
    </div>
  );
}
