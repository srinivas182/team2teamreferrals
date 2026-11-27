import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, MapPin, PlayCircle, Search, Send, ShieldCheck, Sparkles, Timer } from 'lucide-react';
import { useMemo, useState } from 'react';
import { NetworkMap } from '@/components/map/NetworkMap';
import type { MapData } from '@/components/map/types';
import { Faq } from '@/components/seo/Faq';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Industry = { slug: string; name: string; family: string; pro: string; client: string; href: string; live: boolean };
type Props = {
    industries: Industry[];
    stats: { seats: number; networks: number; industries: number; states: number; acceptMinutes: number | null };
    map: { groups: { slug: string; name: string }[]; slug: string; initial: MapData } | null;
    joinHref: string;
    demoUrl: string | null;
    faq?: { q: string; a: string }[];
    seo: Seo;
};

const article = (w: string) => (/^[aeiou]/i.test(w) ? 'an' : 'a');

/** Hero visual: how a referral travels between two cities. An example, labelled as one. */
function RelayCard() {
    return (
        <Card className="relative overflow-hidden p-5 shadow-lg sm:p-6">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-muted">Example referral</span>
                <Badge tone="warning">Hot lead</Badge>
            </div>
            <svg viewBox="0 0 320 96" className="mt-4 h-24 w-full" aria-hidden>
                <defs>
                    <linearGradient id="relay-grad" x1="0" x2="1">
                        <stop offset="0" stopColor="#2451F5" />
                        <stop offset="1" stopColor="#FF7A1A" />
                    </linearGradient>
                </defs>
                <path d="M30 70 C 110 -10, 210 -10, 290 62" fill="none" stroke="var(--t-border)" strokeWidth="2" strokeDasharray="2 6" />
                <path
                    className="relay-path"
                    pathLength={1}
                    d="M30 70 C 110 -10, 210 -10, 290 62"
                    fill="none"
                    stroke="url(#relay-grad)"
                    strokeWidth="3"
                    strokeLinecap="round"
                />
                <circle cx="30" cy="70" r="7" fill="#2451F5" />
                <circle cx="290" cy="62" r="7" fill="#FF7A1A" />
            </svg>
            <div className="-mt-2 flex justify-between text-sm">
                <div>
                    <div className="font-semibold">St. George, UT</div>
                    <div className="text-muted">The sending team</div>
                </div>
                <div className="text-right">
                    <div className="font-semibold">Houston, TX</div>
                    <div className="text-muted">The verified team there</div>
                </div>
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 rounded-md bg-bg p-4 text-sm">
                <div>
                    <div className="text-muted">Client</div>
                    <div className="font-medium">Buyer, pre-approved</div>
                </div>
                <div>
                    <div className="text-muted">Timing</div>
                    <div className="font-medium">Ready now</div>
                </div>
                <div>
                    <div className="text-muted">Referral fee</div>
                    <div className="font-medium tabular">Agreed up front</div>
                </div>
                <div>
                    <div className="text-muted">Answer by</div>
                    <div className="font-medium tabular">Set by the network</div>
                </div>
            </div>
            <ol className="mt-5 space-y-3 text-sm">
                {(
                    [
                        ['Sent', '#2451F5'],
                        ['Accepted by the Houston team', '#FF7A1A'],
                        ['First call with the buyer', '#FF7A1A'],
                    ] as const
                ).map(([a, c]) => (
                    <li key={a} className="flex items-center gap-3">
                        <span className="size-2.5 rounded-full" style={{ background: c }} aria-hidden />
                        <span className="font-medium">{a}</span>
                    </li>
                ))}
            </ol>
        </Card>
    );
}

/** "What do you do?": find your industry's network as you type. */
function IndustryPicker({ industries }: { industries: Industry[] }) {
    const [q, setQ] = useState('');
    const hits = q.trim() ? industries.filter((i) => `${i.name} ${i.family}`.toLowerCase().includes(q.toLowerCase())).slice(0, 6) : [];
    const popular = industries.filter((i) => i.live).slice(0, 8);
    return (
        <div className="relative mt-8 max-w-xl">
            <form
                role="search"
                className="flex flex-wrap items-center gap-2 rounded-xl border border-line bg-surface p-2 shadow-md focus-within:border-primary sm:flex-nowrap"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.visit(hits[0]?.href ?? `/groups?q=${encodeURIComponent(q)}`);
                }}
            >
                <Search className="ml-2 size-5 text-subtle" aria-hidden />
                <label className="sr-only" htmlFor="ind-search">
                    What do you do?
                </label>
                <input
                    id="ind-search"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="What do you do? Solar, mortgage, roofing…"
                    className="h-11 min-w-0 flex-1 rounded-sm bg-transparent text-base outline-none placeholder:text-subtle focus-visible:ring-2 focus-visible:ring-primary"
                    autoComplete="off"
                />
                <Button type="submit" className="w-full sm:w-auto">
                    Find my network
                </Button>
            </form>
            {hits.length > 0 && (
                <ul className="absolute inset-x-0 z-10 mt-2 overflow-hidden rounded-xl border border-line bg-surface shadow-lg">
                    {hits.map((i) => (
                        <li key={i.slug}>
                            <Link href={i.href} className="flex items-center gap-3 px-4 py-3 hover:bg-bg">
                                <span className="font-medium">{i.name}</span>
                                <span className="ml-auto text-sm text-muted">{i.family}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {popular.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {popular.map((i) => (
                        <Link
                            key={i.slug}
                            href={i.href}
                            className="inline-flex items-center rounded-full border border-line bg-surface px-3 py-1 text-sm font-medium hover:border-primary"
                        >
                            {i.name}
                        </Link>
                    ))}
                    <a href="#industries" className="inline-flex items-center gap-1 px-2 text-sm font-semibold text-primary">
                        All {industries.length} industries <ArrowRight className="size-3.5" aria-hidden />
                    </a>
                </div>
            )}
        </div>
    );
}

const STEPS = ['Sent', 'Seen by the verified pro', 'Accepted', 'First call with the client'];

/** A no-sign-up simulation of sending a referral. Nothing is sent; it shows what members see. */
function SendDemo({ industries, joinHref, demoUrl }: { industries: Industry[]; joinHref: string; demoUrl: string | null }) {
    const choices = industries.filter((i) => i.live).length > 0 ? industries.filter((i) => i.live) : industries;
    const [slug, setSlug] = useState(choices[0]?.slug ?? '');
    const [temp, setTemp] = useState<'hot' | 'warm' | 'future'>('hot');
    const [sent, setSent] = useState(false);
    const i = choices.find((x) => x.slug === slug) ?? choices[0];
    if (!i) return null;
    return (
        <Card className="grid overflow-hidden lg:grid-cols-2">
            <div className="p-6 sm:p-8">
                <Badge tone="warning">Try it, no sign-up</Badge>
                <h2 className="mt-3 font-display text-2xl font-bold display">Send a referral in three taps</h2>
                <p className="mt-1 text-sm text-muted">A simulation: nothing is sent.</p>
                <div className="mt-6 space-y-4">
                    <label className="block">
                        <span className="mb-1.5 block text-sm font-medium">Industry</span>
                        <Select
                            value={slug}
                            onChange={(e) => {
                                setSlug(e.target.value);
                                setSent(false);
                            }}
                        >
                            {choices.map((x) => (
                                <option key={x.slug} value={x.slug}>
                                    {x.name}
                                </option>
                            ))}
                        </Select>
                    </label>
                    <div>
                        <span className="mb-1.5 block text-sm font-medium">City</span>
                        <div className="flex h-11 items-center gap-2 rounded-md border border-line bg-bg px-3 text-[15px]">
                            <MapPin className="size-4 text-muted" aria-hidden />
                            Austin, TX <span className="ml-auto text-sm text-muted">example</span>
                        </div>
                    </div>
                    <div>
                        <span className="mb-1.5 block text-sm font-medium">How soon is the {i.client} ready?</span>
                        <div className="grid grid-cols-3 gap-2" role="radiogroup" aria-label="How soon">
                            {(
                                [
                                    ['hot', 'Now'],
                                    ['warm', 'Soon'],
                                    ['future', 'Later'],
                                ] as const
                            ).map(([t, label]) => (
                                <button
                                    key={t}
                                    type="button"
                                    role="radio"
                                    aria-checked={temp === t}
                                    onClick={() => setTemp(t)}
                                    className={cn(
                                        'rounded-md border p-2 text-sm font-medium',
                                        temp === t ? 'border-primary ring-2 ring-primary/20' : 'border-line',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    <Button className="w-full" size="lg" onClick={() => setSent(true)}>
                        <Send /> Send to Austin
                    </Button>
                </div>
            </div>
            <div className="border-t border-line bg-bg p-6 sm:p-8 lg:border-t-0 lg:border-l" aria-live="polite">
                {!sent ? (
                    <div className="flex h-full flex-col items-center justify-center text-center text-muted">
                        <Sparkles className="text-primary" aria-hidden />
                        <p className="mt-3 max-w-xs">
                            Press send to see a referral travel to {article(i.pro)} {i.pro} in Austin, with every step tracked.
                        </p>
                    </div>
                ) : (
                    <div>
                        <ol className="space-y-3">
                            {STEPS.map((s, k) => (
                                <li key={s} className="pop flex items-center gap-3 text-sm" style={{ animationDelay: `${0.2 + k * 0.4}s` }}>
                                    <span className="flex size-6 items-center justify-center rounded-full bg-success text-white">
                                        <Check className="size-3.5" aria-hidden />
                                    </span>
                                    <span className="font-medium">{s}</span>
                                </li>
                            ))}
                        </ol>
                        <p className="mt-5 rounded-md bg-surface p-3 text-sm text-muted">
                            Members see every status change live, message the {i.pro}, and get a closing report. Each network sets how fast its
                            members must answer.
                        </p>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button variant="secondary" asChild>
                                <Link href={joinHref}>Claim my city to send real referrals</Link>
                            </Button>
                            {demoUrl && (
                                <Button variant="ghost" asChild>
                                    <a href={demoUrl}>
                                        <PlayCircle /> Explore the live demo
                                    </a>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
}

/** The home page (Sprint 52a): the approved design, with real figures. */
export default function Home({ industries, stats, map, joinHref, demoUrl, faq = [], seo }: Props) {
    const families = useMemo(() => [...new Set(industries.map((i) => i.family))], [industries]);
    const [mapSlug, setMapSlug] = useState(map?.slug ?? '');
    const figures = [
        [stats.seats.toLocaleString('en-US'), stats.seats === 1 ? 'seat filled by a verified pro' : 'seats filled by verified pros'],
        [String(stats.industries), 'industries, one network each'],
        ...(stats.acceptMinutes !== null
            ? [[`${stats.acceptMinutes} min`, 'typical time to accept a referral']]
            : [[String(stats.networks), stats.networks === 1 ? 'network taking members' : 'networks taking members']]),
        [String(stats.states), stats.states === 1 ? 'state with members' : 'states with members'],
    ];
    return (
        <PublicLayout current="/">
            <SeoHead seo={seo} />
            <section className="relative overflow-hidden border-b border-line bg-surface">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-40 -right-40 size-[520px] rounded-full opacity-10"
                    style={{ background: 'radial-gradient(circle,#2451F5,transparent 65%)' }}
                />
                <div className="mx-auto grid max-w-content gap-12 px-5 py-14 sm:px-8 sm:py-20 lg:grid-cols-[1.15fr_1fr] lg:items-center">
                    <div className="min-w-0">
                        <span className="inline-flex flex-wrap items-center gap-2 rounded-2xl bg-cobalt-50 px-3 py-1 text-sm font-semibold text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200">
                            {stats.industries} industries · every US city · one verified pro per city
                        </span>
                        <h1 className="mt-5 font-display text-[38px] leading-[1.05] font-extrabold display break-words sm:text-[56px] lg:text-[64px]">
                            Every out-of-area client, handed to a pro you trust.
                        </h1>
                        <p className="mt-6 max-w-xl text-lg text-muted">
                            Licensed and verified professionals hold one seat per city in their industry’s network. Send a client anywhere in the
                            country in seconds, track every step, and get the credit you’re owed.
                        </p>
                        <IndustryPicker industries={industries} />
                        <div className="mt-6 flex flex-wrap gap-3">
                            <Button size="lg" asChild>
                                <Link href={joinHref}>
                                    Claim your city <ArrowRight />
                                </Link>
                            </Button>
                            {demoUrl ? (
                                <Button size="lg" variant="secondary" asChild>
                                    <a href={demoUrl}>
                                        <PlayCircle /> Try the live demo
                                    </a>
                                </Button>
                            ) : (
                                <Button size="lg" variant="secondary" asChild>
                                    <a href="#try-it">See it in action</a>
                                </Button>
                            )}
                        </div>
                    </div>
                    <RelayCard />
                </div>
            </section>

            <section aria-label="The network today" className="border-b border-line">
                <div className="mx-auto grid max-w-content grid-cols-2 gap-6 px-5 py-10 sm:grid-cols-4 sm:px-8">
                    {figures.map(([v, l]) => (
                        <div key={l}>
                            <div className="font-display text-3xl font-extrabold tabular sm:text-4xl">{v}</div>
                            <div className="mt-1 text-sm text-muted">{l}</div>
                        </div>
                    ))}
                </div>
            </section>

            {map && (
                <section className="py-20">
                    <div className="mx-auto max-w-content px-5 sm:px-8">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="max-w-2xl">
                                <h2 className="font-display text-3xl font-bold display sm:text-4xl">Cities are being claimed right now</h2>
                                <p className="mt-3 text-muted">
                                    Each city has one seat per industry. When it’s taken, the next pro joins the waitlist.
                                </p>
                            </div>
                            {map.groups.length > 1 && (
                                <div className="ml-auto w-full sm:w-64">
                                    <label className="text-sm font-medium" htmlFor="map-ind">
                                        Industry
                                    </label>
                                    <Select id="map-ind" value={mapSlug} onChange={(e) => setMapSlug(e.target.value)}>
                                        {map.groups.map((g) => (
                                            <option key={g.slug} value={g.slug}>
                                                {g.name}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                            )}
                        </div>
                        <Card className="mt-8 p-4 sm:p-6">
                            <NetworkMap
                                key={mapSlug}
                                initial={mapSlug === map.slug ? map.initial : null}
                                src={`/map/group/${mapSlug}.json`}
                                base=""
                                list={false}
                            />
                        </Card>
                        <Button className="mt-6 h-auto min-h-11 py-2 text-center whitespace-normal" variant="secondary" asChild>
                            <Link href={`/groups/${mapSlug}`}>
                                See open cities <ArrowRight />
                            </Link>
                        </Button>
                    </div>
                </section>
            )}

            <section id="industries" className="border-y border-line bg-surface py-20">
                <div className="mx-auto max-w-content px-5 sm:px-8">
                    <h2 className="max-w-2xl font-display text-3xl font-bold display sm:text-4xl">
                        One trusted network for every licensed, local profession
                    </h2>
                    <div className="mt-10 grid gap-8 md:grid-cols-2 xl:grid-cols-4">
                        {families.map((f) => (
                            <div key={f}>
                                <h3 className="text-sm font-semibold tracking-wide text-muted uppercase">{f}</h3>
                                <ul className="mt-3 space-y-1">
                                    {industries
                                        .filter((i) => i.family === f)
                                        .map((i) => (
                                            <li key={i.slug}>
                                                <Link
                                                    href={i.href}
                                                    className="flex items-center gap-2 rounded-md p-1.5 text-sm font-medium hover:bg-bg"
                                                >
                                                    {i.name}
                                                    {i.live && <span className="size-1.5 rounded-full bg-success" aria-label="taking members" />}
                                                </Link>
                                            </li>
                                        ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section id="try-it" className="scroll-mt-20 py-20">
                <div className="mx-auto max-w-content px-5 sm:px-8">
                    <SendDemo industries={industries} joinHref={joinHref} demoUrl={demoUrl} />
                </div>
            </section>

            <section className="pb-20">
                <div className="mx-auto grid max-w-content gap-6 px-5 sm:px-8 lg:grid-cols-2">
                    <Card className="min-w-0 p-6 sm:p-8">
                        <ShieldCheck className="size-7 text-success" aria-hidden />
                        <h2 className="mt-4 font-display text-2xl font-bold display">Pay for a network that works for you</h2>
                        <p className="mt-3 text-muted">
                            Every seat starts with a free trial. In networks with a free extension, any month in which no referral reaches you is
                            free, automatically.
                        </p>
                        <Button className="mt-6 h-auto min-h-11 py-2 text-center whitespace-normal" variant="secondary" asChild>
                            <Link href="/groups">See the networks and their prices</Link>
                        </Button>
                    </Card>
                    <Card className="min-w-0 p-6 sm:p-8">
                        <Timer className="size-7 text-primary" aria-hidden />
                        <h2 className="mt-4 font-display text-2xl font-bold display">Seats are earned, not owned</h2>
                        <p className="mt-3 text-muted">
                            Members see their response times, conversions and ratings every month. Fast, reliable pros keep their city; the waitlist
                            is ready when someone doesn’t.
                        </p>
                        <Button className="mt-6 h-auto min-h-11 py-2 text-center whitespace-normal" variant="secondary" asChild>
                            <Link href="/how-it-works">How it works</Link>
                        </Button>
                    </Card>
                </div>
            </section>

            <section className="pb-8">
                <div className="mx-auto max-w-content px-5 sm:px-8">
                    <div
                        className="overflow-hidden rounded-2xl bg-navy-900 p-10 text-white sm:p-14"
                        style={{
                            backgroundImage:
                                'radial-gradient(circle at 85% 20%,rgba(255,122,26,.35),transparent 45%),radial-gradient(circle at 10% 90%,rgba(36,81,245,.45),transparent 50%)',
                        }}
                    >
                        <h2 className="max-w-2xl font-display text-3xl font-extrabold display sm:text-5xl">
                            Your city is open today. It may not be tomorrow.
                        </h2>
                        <p className="mt-4 max-w-xl text-[#C9D2EA]">Claim your seat now: one verified pro per city, in every industry.</p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <Button size="lg" variant="accent" asChild>
                                <Link href={joinHref}>
                                    Claim my city <ArrowRight />
                                </Link>
                            </Button>
                            <Button size="lg" variant="secondary" asChild>
                                <Link href="/how-it-works">How it works</Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>
            {faq.length > 0 && <Faq items={faq} />}
        </PublicLayout>
    );
}
