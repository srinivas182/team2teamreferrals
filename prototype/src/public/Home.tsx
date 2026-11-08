import { ArrowRight, Clock, FileSignature, ShieldCheck, MapPin, X, Check } from "lucide-react";
import { Container, Button, Card, TempBadge, TrustRing, Avatar, money } from "../ui/kit";
import { network } from "../data/mock";

/** Hero visual: a real referral moving from St. George to Houston (the product’s signature relay). */
function RelayCard() {
  return (
    <Card className="relative overflow-hidden p-5 shadow-lg sm:p-6">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium text-muted">Referral #4821</span>
        <TempBadge t="hot" />
      </div>
      <svg viewBox="0 0 320 96" className="mt-4 h-24 w-full" aria-hidden>
        <defs><linearGradient id="rg" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs>
        <path d="M30 70 C 110 -10, 210 -10, 290 62" fill="none" stroke="rgb(var(--line))" strokeWidth="2" strokeDasharray="2 6" />
        <path className="relay-path" d="M30 70 C 110 -10, 210 -10, 290 62" fill="none" stroke="url(#rg)" strokeWidth="3" strokeLinecap="round" />
        <circle cx="30" cy="70" r="7" fill="#2451F5" /><circle cx="290" cy="62" r="7" fill="#FF7A1A" className="pop" style={{ animationDelay: "1.7s", transformOrigin: "290px 62px" }} />
      </svg>
      <div className="-mt-2 flex justify-between text-sm">
        <div><div className="font-semibold">St. George, UT</div><div className="text-muted">Red Rock Realty Group</div></div>
        <div className="text-right"><div className="font-semibold">Houston, TX</div><div className="text-muted">Bayou City Homes</div></div>
      </div>
      <div className="mt-5 grid grid-cols-2 gap-3 rounded-md bg-bg p-4 text-sm">
        <div><div className="text-muted">Lead</div><div className="font-medium">Buyer, pre-approved</div></div>
        <div><div className="text-muted">Price range</div><div className="font-medium tabular">$450k–$520k</div></div>
        <div><div className="text-muted">Referral fee</div><div className="font-medium tabular">30%</div></div>
        <div><div className="text-muted">Est. referral value</div><div className="font-semibold tabular text-success">{money(3638)}</div></div>
      </div>
      <ol className="mt-5 space-y-3 text-sm">
        {[["Sent", "9:02 AM", "#2451F5"], ["Accepted by Chris Delgado", "9:16 AM · 14 min", "#FF7A1A"], ["First call with the buyer", "9:41 AM", "#FF7A1A"]].map(([a, b, c]) => (
          <li key={a} className="flex items-center gap-3"><span className="h-2.5 w-2.5 rounded-full" style={{ background: c }} /><span className="font-medium">{a}</span><span className="ml-auto tabular text-muted">{b}</span></li>
        ))}
      </ol>
    </Card>
  );
}

export default function Home() {
  return (
    <>
      <section className="border-b border-line bg-surface">
        <Container className="grid items-center gap-12 py-14 sm:py-20 lg:grid-cols-[1.1fr_1fr] lg:py-24">
          <div>
            <h1 className="display font-display text-[40px] font-extrabold leading-[1.08] sm:text-[52px] lg:text-[60px]">
              Your out-of-area leads, handed to teams you trust.
            </h1>
            <p className="mt-6 max-w-xl text-lg text-muted">
              When a buyer calls about a city you don’t serve, send them to a vetted team there in under a minute. Track every step, and collect your referral fee when they close.
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Button size="lg" href="#signup">Claim your city free <ArrowRight size={18} /></Button>
              <Button size="lg" variant="secondary" href="#how-it-works">See how it works</Button>
            </div>
            <p className="mt-6 text-sm text-muted">Free during the pilot. Verified licenses only. We never take a share of your commission.</p>
          </div>
          <RelayCard />
        </Container>
      </section>

      <section>
        <Container className="grid gap-10 py-20 lg:grid-cols-2 lg:gap-16">
          <div>
            <h2 className="display font-display text-3xl font-bold sm:text-4xl">Every top team leaks out-of-area leads.</h2>
            <p className="mt-4 text-lg text-muted">A buyer in Houston calls your St. George office. You’re not licensed in Texas, so the lead goes to junk — or to a friend-of-a-friend you can’t hold to anything. Either way, the referral fee walks out the door.</p>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Card className="p-5">
              <h3 className="font-semibold">Today</h3>
              <ul className="mt-4 space-y-3 text-sm text-muted">
                {["Lead parked in a spreadsheet", "Referral texted to a contact", "No idea if anyone called", "Fee chased months later, if ever"].map(t => <li key={t} className="flex gap-2"><X size={16} className="mt-0.5 shrink-0 text-danger" />{t}</li>)}
              </ul>
            </Card>
            <Card className="border-cobalt-200 p-5 dark:border-cobalt-700">
              <h3 className="font-semibold">With Team to Team</h3>
              <ul className="mt-4 space-y-3 text-sm">
                {["One verified team per city", "Signed agreement on acceptance", "Live status on every step", "Closing reported, fee tracked"].map(t => <li key={t} className="flex gap-2"><Check size={16} className="mt-0.5 shrink-0 text-success" />{t}</li>)}
              </ul>
            </Card>
          </div>
        </Container>
      </section>

      <section className="border-y border-line bg-surface">
        <Container className="py-20">
          <h2 className="display max-w-2xl font-display text-3xl font-bold sm:text-4xl">Three steps from “not my market” to “fee received”.</h2>
          <ol className="mt-12 grid gap-8 md:grid-cols-3">
            {[
              ["Join a group and claim your city", "Groups are networks of vetted teams, one per city. Your license is verified before you go live."],
              ["Send the lead to the right team", "Pick the city, mark the lead Hot, Warm or Future, and send. The team there sees its value before accepting."],
              ["Track it to closing", "Accepting signs the referral agreement. You see every call and update, and the closing report shows your fee."],
            ].map(([t, d], i) => (
              <li key={t}>
                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-cobalt-50 font-display text-lg font-bold text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200">{i + 1}</span>
                <h3 className="mt-4 text-lg font-semibold">{t}</h3>
                <p className="mt-2 text-muted">{d}</p>
              </li>
            ))}
          </ol>
        </Container>
      </section>

      <section>
        <Container className="py-20">
          <h2 className="display max-w-2xl font-display text-3xl font-bold sm:text-4xl">Accountability is built in, not promised.</h2>
          <div className="mt-12 grid gap-6 lg:grid-cols-3">
            <Card className="p-6">
              <Clock className="text-primary" />
              <h3 className="mt-4 text-lg font-semibold">Deadlines your group sets</h3>
              <p className="mt-2 text-muted">Accept within 24 hours, call within 2 for Hot leads, update weekly. Miss twice and the city goes to the backup team.</p>
              <div className="mt-5 flex items-center justify-between rounded-md border border-line p-3 text-sm"><span>Accept referral #4822</span><span className="rounded-full bg-[#FEF9E7] px-2 py-0.5 font-semibold tabular text-warning dark:bg-warning/20 dark:text-[#FCD34D]">2h 14m left</span></div>
            </Card>
            <Card className="p-6">
              <FileSignature className="text-primary" />
              <h3 className="mt-4 text-lg font-semibold">An agreement on every referral</h3>
              <p className="mt-2 text-muted">Both brokerages, license numbers, fee and protection period, signed in the app the moment a team accepts.</p>
              <div className="mt-5 flex items-center gap-3 rounded-md border border-line p-3 text-sm"><ShieldCheck size={18} className="text-success" /><span>Signed by both teams</span><span className="ml-auto text-muted">9:17 AM</span></div>
            </Card>
            <Card className="p-6">
              <ShieldCheck className="text-primary" />
              <h3 className="mt-4 text-lg font-semibold">A trust score everyone can see</h3>
              <p className="mt-2 text-muted">Response time, updates, conversions and fees paid on time, rolled into one score from 0 to 100.</p>
              <div className="mt-5 flex items-center gap-3 rounded-md border border-line p-3 text-sm"><Avatar name="Chris Delgado" size={32} tone="orange" /><div><div className="font-medium">Bayou City Homes</div><div className="text-muted">Houston, TX</div></div><span className="ml-auto"><TrustRing score={94} size={40} /></span></div>
            </Card>
          </div>
        </Container>
      </section>

      <section className="bg-navy-900 text-white">
        <Container className="grid gap-10 py-16 lg:grid-cols-[1fr_1.4fr] lg:items-center">
          <div>
            <h2 className="display font-display text-3xl font-bold">The network today</h2>
            <p className="mt-3 text-[#AAB4CE]">Sample figures for the prototype.</p>
            <Button variant="accent" className="mt-6" href="#groups">Browse groups <MapPin size={16} /></Button>
          </div>
          <dl className="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-4">
            {[["Verified teams", network.teams.toLocaleString()], ["States covered", network.states], ["Referrals sent", network.referrals.toLocaleString()], ["Median time to accept", network.avgAccept]].map(([k, v]) => (
              <div key={k as string} className="border-l border-navy-700 pl-4"><dt className="text-sm text-[#AAB4CE]">{k}</dt><dd className="mt-1 font-display text-3xl font-bold tabular">{v}</dd></div>
            ))}
          </dl>
        </Container>
      </section>

      <section>
        <Container className="grid gap-6 py-20 lg:grid-cols-2">
          {[
            ["We used to lose a dozen relocation leads a quarter. Last quarter four of them closed through our group, and every fee was on the closing report.", "Jordan Hale", "Red Rock Realty Group, St. George"],
            ["I know a referral is real before I accept it — the budget, the timeline, whether they’re pre-approved. That alone changed how fast we respond.", "Chris Delgado", "Bayou City Homes, Houston"],
          ].map(([q, n, r]) => (
            <figure key={n} className="rounded-lg border border-line bg-surface p-8">
              <blockquote className="text-lg leading-relaxed">“{q}”</blockquote>
              <figcaption className="mt-6 flex items-center gap-3"><Avatar name={n} tone={n === "Jordan Hale" ? "cobalt" : "orange"} /><div><div className="font-semibold">{n}</div><div className="text-sm text-muted">{r}</div></div></figcaption>
            </figure>
          ))}
        </Container>
      </section>

      <section>
        <Container>
          <div className="flex flex-col items-start gap-6 rounded-xl bg-cobalt-500 px-8 py-12 text-white sm:px-12 lg:flex-row lg:items-center">
            <div>
              <h2 className="display font-display text-3xl font-bold">Your city might still be open.</h2>
              <p className="mt-2 text-cobalt-100">Claiming is free during the pilot. One team per city, per group.</p>
            </div>
            <div className="flex gap-3 lg:ml-auto">
              <Button variant="accent" size="lg" href="#groups">Check your city</Button>
            </div>
          </div>
        </Container>
      </section>
    </>
  );
}
