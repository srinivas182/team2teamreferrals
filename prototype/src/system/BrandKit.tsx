import { useState } from "react";
import { Container, Card, Badge, Select, Input, Logo } from "../ui/kit";
import { families, industries, bySlug, type FamilyKey, type Industry } from "../data/industries";
import { IndustryIcon } from "../ui/brand";

/** Custom family emblems: one geometric glyph per family on the t2t relay arc. Drawn for this brand, 48px grid, 2px strokes. */
const glyphs: Record<FamilyKey, JSX.Element> = {
  property: <path d="M14 26 L24 17 L34 26 V34 H14 Z M21 34 V28 H27 V34" />,
  home: <><circle cx="24" cy="25" r="5" /><path d="M24 14v3M24 33v3M13 25h3M32 25h3M16.5 17.5l2 2M29.5 30.5l2 2M31.5 17.5l-2 2M18.5 30.5l-2 2" /></>,
  money: <path d="M24 14 L33 18 V25 C33 30 29 33.5 24 35 C19 33.5 15 30 15 25 V18 Z M20 25 l3 3 l6 -6" />,
  legal: <path d="M24 14 V34 M17 34 H31 M15 20 H33 M15 20 l-3 7 h6 Z M33 20 l-3 7 h6 Z" />,
  care: <path d="M24 33 C17 28 14 24.5 14 21 A5 5 0 0 1 24 18.5 A5 5 0 0 1 34 21 C34 24.5 31 28 24 33 Z" />,
  vehicles: <><circle cx="18" cy="30" r="3.5" /><circle cx="30" cy="30" r="3.5" /><path d="M13 30 V24 L17 18 H29 L35 24 V30 M21.5 30 H26.5 M17 24 H35" /></>,
  business: <path d="M14 20 H34 V33 H14 Z M20 20 V16 H28 V20 M14 26 H34" />,
  life: <path d="M24 13 L26.8 20.5 L34.5 21 L28.5 26 L30.5 33.5 L24 29.3 L17.5 33.5 L19.5 26 L13.5 21 L21.2 20.5 Z" />,
};
export function FamilyEmblem({ family, size = 72 }: { family: FamilyKey; size?: number }) {
  const f = families[family];
  return (
    <svg width={size} height={size} viewBox="0 0 48 48" role="img" aria-label={`${f.name} emblem`}>
      <rect x="1" y="1" width="46" height="46" rx="12" fill={f.soft} />
      <path d="M8 40 C 16 30, 32 30, 40 40" fill="none" stroke="url(#arc)" strokeWidth="2.5" strokeLinecap="round" opacity=".9" />
      <defs><linearGradient id="arc" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs>
      <g fill="none" stroke={f.accent} strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">{glyphs[family]}</g>
    </svg>
  );
}

/** 1200×630 share card members post when they claim a city. */
export function ShareCard({ industry, city, small }: { industry: Industry; city: string; small?: boolean }) {
  const f = families[industry.family];
  return (
    <svg viewBox="0 0 1200 630" className="h-auto w-full rounded-lg shadow-lg" role="img" aria-label={`Share card: we claimed ${city}`}>
      <defs><linearGradient id="sc" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stopColor="#0E1B3D" /><stop offset="1" stopColor="#1B2A5C" /></linearGradient><linearGradient id="rl" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs>
      <rect width="1200" height="630" fill="url(#sc)" />
      <circle cx="1050" cy="120" r="260" fill="#FF7A1A" opacity=".18" /><circle cx="120" cy="560" r="300" fill="#2451F5" opacity=".28" />
      <path d="M140 470 C 420 250, 780 250, 1060 430" fill="none" stroke="url(#rl)" strokeWidth="10" strokeLinecap="round" />
      <circle cx="140" cy="470" r="18" fill="#2451F5" /><circle cx="1060" cy="430" r="18" fill="#FF7A1A" />
      <text x="80" y="130" fill="#AAB4CE" fontFamily="Inter, sans-serif" fontSize="34" fontWeight="600">{industry.group.toUpperCase()}</text>
      <text x="80" y="230" fill="#fff" fontFamily="Inter, sans-serif" fontSize={city.length > 14 ? 64 : city.length > 9 ? 80 : 96} fontWeight="800">We claimed {city}.</text>
      <text x="80" y="300" fill="#C9D2EA" fontFamily="Inter, sans-serif" fontSize="34">Send us your {industry.client}s in {city}.</text>
      <text x="80" y="346" fill="#C9D2EA" fontFamily="Inter, sans-serif" fontSize="34">Verified, tracked, trusted.</text>
      <rect x="80" y="520" width="300" height="56" rx="28" fill={f.accent} /><text x="230" y="557" textAnchor="middle" fill="#fff" fontFamily="Inter, sans-serif" fontSize="26" fontWeight="700">Founding member</text>
      <text x="1120" y="566" textAnchor="end" fill="#fff" fontFamily="Inter, sans-serif" fontSize="30" fontWeight="700">teamtoteamreferrals.com</text>
    </svg>
  );
}

const motion = [["Relay", "Every handoff animates as a cobalt-to-orange arc: sender to receiver. 1.4 s, ease-out, once."], ["Map fill", "Cities light up in order on load, so a network feels alive. Skipped with reduced motion."], ["Count up", "Key numbers count up once on first view (1.2 s). Never loops."], ["Pop", "New items scale from 60% in 320 ms. Used for confirmations, not decoration."]];
const photo = ["Real pros at work in their own city: on a roof, at a kitchen table, in a showroom. Never stock handshakes.", "Natural light, warm and optimistic; the city recognisable in the background where possible.", "Diverse ages and backgrounds that reflect each industry's real workforce.", "One subject, plenty of space for text; crops work at 16:9, 1:1 and 4:5.", "Licensed images only; model releases stored with each image."];

export function BrandKit() {
  const [ind, setInd] = useState("roofing"); const [city, setCity] = useState("Houston"); const fams = Object.keys(families) as FamilyKey[];
  return (
    <Container className="py-10">
      <Logo className="h-9 w-auto" />
      <h1 className="display mt-6 font-display text-4xl font-extrabold">Brand kit at scale</h1>
      <p className="mt-2 max-w-2xl text-muted">One national brand across 46 industries. Every industry gets the same tile, the same relay arc and one of eight family accents, all inside the cobalt-and-orange brand.</p>

      <h2 className="mt-12 font-display text-2xl font-bold">Family emblems</h2><p className="mt-1 text-sm text-muted">Custom-drawn for t2t: 48 px grid, 2.2 px rounded strokes, relay arc base.</p>
      <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">{fams.map(f => <Card key={f} className="flex items-center gap-4 p-4"><FamilyEmblem family={f} size={64} /><div><div className="font-semibold">{families[f].name}</div><div className="text-xs text-muted">{families[f].accent}</div></div></Card>)}</div>

      <h2 className="mt-12 font-display text-2xl font-bold">Industry tiles · all 46</h2><p className="mt-1 text-sm text-muted">Family accent background, single-weight glyph, relay dot. Used in pickers, maps, cards and app icons.</p>
      <div className="mt-5 grid grid-cols-3 gap-3 sm:grid-cols-6 lg:grid-cols-8">{industries.map(i => <div key={i.slug} className="flex flex-col items-center gap-2 rounded-lg border border-line bg-surface p-3 text-center"><IndustryIcon industry={i} size={48} /><span className="text-xs font-medium leading-tight">{i.name}</span></div>)}</div>

      <h2 className="mt-12 font-display text-2xl font-bold">Share cards</h2><p className="mt-1 text-sm text-muted">Generated for every claimed city, sized for LinkedIn, Facebook and X (1200×630). Square and story sizes use the same layout.</p>
      <div className="mt-5 grid gap-6 lg:grid-cols-[280px_1fr]">
        <Card className="space-y-4 p-5"><label className="block"><span className="mb-1.5 block text-sm font-medium">Industry</span><Select value={ind} onChange={e => setInd(e.target.value)}>{industries.map(x => <option key={x.slug} value={x.slug}>{x.name}</option>)}</Select></label><label className="block"><span className="mb-1.5 block text-sm font-medium">City</span><Input value={city} onChange={e => setCity(e.target.value)} /></label></Card>
        <ShareCard industry={bySlug(ind)} city={city || "Houston"} />
      </div>

      <div className="mt-12 grid gap-6 lg:grid-cols-2">
        <Card className="p-6"><h2 className="font-display text-xl font-bold">Motion principles</h2><ul className="mt-4 space-y-3 text-sm">{motion.map(([t, d]) => <li key={t}><span className="font-semibold">{t}.</span> <span className="text-muted">{d}</span></li>)}</ul>
          <svg viewBox="0 0 320 80" className="mt-4 h-16 w-full" aria-hidden><path className="relay-path" d="M20 64 C 110 -4, 210 -4, 300 56" fill="none" stroke="url(#arc2)" strokeWidth="3" strokeLinecap="round" /><defs><linearGradient id="arc2" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs><circle cx="20" cy="64" r="6" fill="#2451F5" /><circle cx="300" cy="56" r="6" fill="#FF7A1A" /></svg></Card>
        <Card className="p-6"><h2 className="font-display text-xl font-bold">Photography direction</h2><ul className="mt-4 list-disc space-y-2 pl-5 text-sm text-muted">{photo.map(p => <li key={p}>{p}</li>)}</ul><div className="mt-4 flex flex-wrap gap-2"><Badge tone="cobalt">16:9 hero</Badge><Badge tone="cobalt">1:1 card</Badge><Badge tone="cobalt">4:5 social</Badge></div></Card>
      </div>

      <Card className="mt-6 p-6"><h2 className="font-display text-xl font-bold">Illustration style</h2><p className="mt-1 text-sm text-muted">Flat geometric scenes on the relay arc: two places, one handoff. Navy line, family accent fills, no gradients except the arc.</p>
        <svg viewBox="0 0 900 220" className="mt-4 h-auto w-full" aria-hidden>
          <path d="M120 170 C 330 20, 570 20, 780 160" fill="none" stroke="url(#arc3)" strokeWidth="6" strokeLinecap="round" strokeDasharray="1 14" /><defs><linearGradient id="arc3" x1="0" x2="1"><stop offset="0" stopColor="#2451F5" /><stop offset="1" stopColor="#FF7A1A" /></linearGradient></defs>
          <g stroke="rgb(var(--ink))" strokeWidth="3" fill="none" strokeLinejoin="round"><path d="M60 190 V140 L120 100 L180 140 V190 Z" fill="rgba(36,81,245,.15)" /><path d="M105 190 V160 H135 V190" /><path d="M720 190 V120 H840 V190 Z" fill="rgba(255,122,26,.18)" /><path d="M740 120 V100 H820 V120 M740 150 H820" /></g>
          <circle cx="450" cy="62" r="26" fill="#2451F5" /><path d="M440 62 l7 7 l14 -14" stroke="#fff" strokeWidth="4" fill="none" strokeLinecap="round" />
        </svg></Card>
      <p className="mt-6 text-sm text-muted">Glyphs inside industry tiles use the open-source Lucide set (ISC licence) for a consistent single weight. The family emblems, share cards and illustrations above are drawn for this brand.</p>
    </Container>
  );
}
