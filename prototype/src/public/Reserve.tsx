import { useEffect, useState } from "react";
import { ArrowRight, Copy, Gift, Lock, Trophy, Users, Share2, Check } from "lucide-react";
import { Container, Button, Card, Badge, Select, Input, money } from "../ui/kit";
import { industries, bySlug, totalClaimed } from "../data/industries";
import { NationalMap, MapLegend } from "../ui/national";
import { ShareCard } from "../system/BrandKit";
import { CountUp } from "../ui/count";

const LAUNCH = new Date("2027-03-01T15:00:00Z").getTime();
function Countdown() {
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const t = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(t); }, []);
  const s = Math.max(0, Math.floor((LAUNCH - now) / 1000)); const parts = [[Math.floor(s / 86400), "days"], [Math.floor(s / 3600) % 24, "hours"], [Math.floor(s / 60) % 60, "min"], [s % 60, "sec"]] as const;
  return <div className="flex gap-3" role="timer" aria-label="Time until launch">{parts.map(([v, l]) => <div key={l} className="min-w-[64px] rounded-lg bg-white/10 px-3 py-2 text-center"><div className="font-display text-2xl font-extrabold tabular">{String(v).padStart(2, "0")}</div><div className="text-xs text-[#C9D2EA]">{l}</div></div>)}</div>;
}

const leaders = [["Texas", 71], ["Florida", 68], ["Arizona", 64], ["California", 58], ["Georgia", 55], ["Utah", 52], ["Ohio", 47], ["Colorado", 45]] as const;

export function ReservePage() {
  const [ind, setInd] = useState("roofing"); const [city, setCity] = useState("Austin, TX"); const [done, setDone] = useState(false); const i = bySlug(ind);
  return (
    <>
      <section className="relative overflow-hidden bg-navy-900 text-white" style={{ backgroundImage: "radial-gradient(circle at 85% 15%,rgba(255,122,26,.35),transparent 45%),radial-gradient(circle at 5% 95%,rgba(36,81,245,.5),transparent 50%)" }}>
        <Container className="grid gap-10 py-14 sm:py-20 lg:grid-cols-[1.1fr_1fr] lg:items-center">
          <div>
            <Badge tone="orange">Founding member reservations are open</Badge>
            <h1 className="display mt-5 font-display text-[38px] font-extrabold leading-[1.05] sm:text-6xl">Reserve your city before launch day.</h1>
            <p className="mt-5 max-w-xl text-lg text-[#C9D2EA]">One verified pro per city, per industry. Founding members lock in their price for as long as they hold the seat, and get a badge no one can earn after launch.</p>
            <div className="mt-8"><p className="mb-2 text-sm text-[#C9D2EA]">National launch in</p><Countdown /></div>
          </div>
          <Card className="p-6 text-ink">
            {!done ? <>
              <h2 className="font-display text-xl font-bold">Reserve in under two minutes</h2>
              <div className="mt-5 space-y-4">
                <label className="block"><span className="mb-1.5 block text-sm font-medium">Your industry</span><Select value={ind} onChange={e => setInd(e.target.value)}>{industries.map(x => <option key={x.slug} value={x.slug}>{x.name}</option>)}</Select></label>
                <label className="block"><span className="mb-1.5 block text-sm font-medium">Your city</span><Input value={city} onChange={e => setCity(e.target.value)} /></label>
                <div className="flex items-center gap-2 rounded-md bg-[#ECFDF3] p-3 text-sm text-success dark:bg-success/15"><Check size={16} />{city} is open in {i.name.toLowerCase()}</div>
                <Button className="w-full" size="lg" onClick={() => setDone(true)}>Reserve {city.split(",")[0]} <ArrowRight size={18} /></Button>
                <p className="flex items-center gap-2 text-xs text-muted"><Lock size={12} />No card needed. License checked before launch.</p>
              </div>
            </> : <>
              <Badge tone="success">Reserved</Badge><h2 className="mt-3 font-display text-xl font-bold">{city} is yours, founding member.</h2>
              <p className="mt-2 text-sm text-muted">Founding price {money(59)} per month after a 3-month free trial, locked while you hold the seat.</p>
              <div className="mt-5"><ShareCard industry={i} city={city.split(",")[0]!} small /></div>
              <div className="mt-4 flex gap-2"><Button className="flex-1" variant="secondary"><Share2 size={16} />Share on LinkedIn</Button><Button className="flex-1" variant="secondary"><Copy size={16} />Copy invite link</Button></div>
            </>}
          </Card>
        </Container>
      </section>
      <Container className="grid gap-4 py-10 sm:grid-cols-3">{[[Gift, "Founding price, locked", "Pay less than everyone who joins after launch, for as long as you hold your city."], [Trophy, "Founding member badge", "Shown on your profile and city page. It can't be earned after launch day."], [Users, "Move up by inviting", "Waitlisted? Every pro you invite who verifies moves you up the list."]].map(([I, t, d]) => { const Ic = I as typeof Gift; return <Card key={t as string} className="p-6"><Ic className="text-orange-500" /><h3 className="mt-3 font-semibold">{t as string}</h3><p className="mt-1 text-sm text-muted">{d as string}</p></Card>; })}</Container>
      <Container className="grid gap-6 pb-16 lg:grid-cols-[1fr_320px]">
        <Card className="p-5"><div className="flex flex-wrap items-center gap-3"><h2 className="font-semibold">Live reservations · {i.name}</h2><span className="ml-auto text-sm text-muted"><CountUp to={totalClaimed} /> seats reserved nationwide</span></div><div className="mt-3"><NationalMap industry={ind} /></div><MapLegend /></Card>
        <Card className="p-6"><h2 className="flex items-center gap-2 font-semibold"><Trophy size={18} className="text-orange-500" />State race</h2><ol className="mt-4 space-y-3">{leaders.map(([s, p], k) => <li key={s}><div className="flex justify-between text-sm"><span><span className="mr-2 font-display font-bold text-muted">{k + 1}</span>{s}</span><span className="tabular text-muted">{p}%</span></div><div className="mt-1 h-1.5 rounded-full bg-bg"><div className="h-1.5 rounded-full" style={{ width: `${p}%`, background: "linear-gradient(90deg,#2451F5,#FF7A1A)" }} /></div></li>)}</ol></Card>
      </Container>
    </>
  );
}
