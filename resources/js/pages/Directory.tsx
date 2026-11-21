import { Link, router } from '@inertiajs/react';
import { ArrowRight, BadgeDollarSign, Handshake, MapPinned, Search, X } from 'lucide-react';
import { useState } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { IndustryIcon } from '@/components/directory/IndustryIcon';
import { NetworkCard, type NetworkRow } from '@/components/directory/NetworkCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Props = {
    groups: NetworkRow[];
    answer: string;
    filters: { industry: string; state: string; q: string; city: { id: number; name: string } | null };
    industries: { slug: string; name: string; icon: string }[];
    states: { code: string; name: string }[];
    stats: { networks: number; industries: number; seats: number; places: number };
    claimHref: string;
    updatedOn: string | null;
    seo: Seo;
};

const steps = [
    { icon: MapPinned, title: 'Claim your city', body: 'One verified team holds each seat. Claim yours before someone else in your market does.' },
    {
        icon: Handshake,
        title: 'Swap referrals',
        body: 'Send out-of-area clients to trusted teams, and get the clients they can’t serve in your city.',
    },
    {
        icon: BadgeDollarSign,
        title: 'Get paid when they close',
        body: 'Referral fees are paid broker to broker. We never take a share of your commission.',
    },
];

/** The network directory: search, industry chips, state and city; with a city chosen, each network shows its seat there. */
export default function Directory({ groups, answer, filters, industries, states, stats, claimHref, updatedOn, seo }: Props) {
    const [f, setF] = useState({ industry: filters.industry, state: filters.state, q: filters.q, city: filters.city?.id ?? 0 });
    const [cityName, setCityName] = useState(filters.city?.name ?? '');
    const apply = (next = f) =>
        router.get('/groups', Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== 0)), {
            preserveScroll: true,
            preserveState: true,
        });
    const set = (patch: Partial<typeof f>, now = false) => {
        const next = { ...f, ...patch };
        setF(next);
        if (now) apply(next);
    };
    const filtered = Boolean(filters.industry || filters.state || filters.q || filters.city);
    const clear = () => {
        const empty = { industry: '', state: '', q: '', city: 0 };
        setF(empty);
        setCityName('');
        apply(empty);
    };
    const numbers = [
        [stats.networks, stats.networks === 1 ? 'network' : 'networks'],
        [stats.industries, stats.industries === 1 ? 'industry' : 'industries'],
        [stats.seats, stats.seats === 1 ? 'seat filled' : 'seats filled'],
        [stats.places, stats.places === 1 ? 'city covered' : 'cities covered'],
    ] as const;

    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />

            {/* Opening: what this is, the numbers, and one search */}
            <section className="relative overflow-hidden border-b border-line bg-gradient-to-b from-cobalt-50/70 to-bg dark:from-navy-900/60">
                <div className="mx-auto max-w-6xl px-5 pt-14 pb-10 sm:px-8">
                    <p className="text-sm font-semibold tracking-wide text-primary uppercase">Referral networks</p>
                    <h1 className="mt-2 max-w-3xl font-display text-4xl leading-tight font-bold sm:text-5xl">Find your referral network</h1>
                    <p className="mt-4 max-w-2xl text-lg text-muted">
                        Each network gives one verified team per city a seat. Join the one for your industry and trade out-of-area clients with teams
                        you can trust.
                    </p>
                    <dl className="mt-8 grid max-w-3xl grid-cols-2 gap-4 sm:grid-cols-4">
                        {numbers.map(([n, label]) => (
                            <div key={label} className="flex flex-col-reverse rounded-xl border border-line bg-surface/80 px-4 py-3">
                                <dt className="text-sm text-muted">{label}</dt>
                                <dd className="font-display text-2xl font-bold tabular-nums">{n.toLocaleString('en-US')}</dd>
                            </div>
                        ))}
                    </dl>

                    <form
                        role="search"
                        className="mt-8 grid gap-3 rounded-2xl border border-line bg-surface p-3 shadow-sm md:grid-cols-[1fr_1fr_auto]"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply();
                        }}
                    >
                        <label className="relative block">
                            <span className="sr-only">Search networks</span>
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted" aria-hidden />
                            <Input
                                className="pl-10"
                                value={f.q}
                                placeholder="Search by network or industry"
                                onChange={(e) => set({ q: e.target.value })}
                            />
                        </label>
                        <div>
                            <label htmlFor="directory-city" className="sr-only">
                                City
                            </label>
                            <CityPicker
                                id="directory-city"
                                placeholder={cityName ? `City: ${cityName}` : 'Your city, e.g. Austin'}
                                onPick={(c) => {
                                    setCityName(c.label);
                                    set({ city: c.id }, true);
                                }}
                            />
                        </div>
                        <Button type="submit" className="w-full md:w-auto">
                            Find networks
                        </Button>
                    </form>
                </div>
            </section>

            <section className="mx-auto max-w-6xl px-5 py-10 sm:px-8">
                {/* Industry chips and state */}
                <div className="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        onClick={() => set({ industry: '' }, true)}
                        aria-pressed={!f.industry}
                        className={cn(
                            'inline-flex min-h-10 items-center rounded-full border px-4 text-sm font-medium',
                            !f.industry ? 'border-primary bg-primary text-white' : 'border-line bg-surface hover:border-primary',
                        )}
                    >
                        All industries
                    </button>
                    {industries.map((i) => (
                        <button
                            key={i.slug}
                            type="button"
                            onClick={() => set({ industry: f.industry === i.slug ? '' : i.slug }, true)}
                            aria-pressed={f.industry === i.slug}
                            className={cn(
                                'inline-flex min-h-10 items-center gap-2 rounded-full border px-4 text-sm font-medium',
                                f.industry === i.slug ? 'border-primary bg-primary text-white' : 'border-line bg-surface hover:border-primary',
                            )}
                        >
                            <IndustryIcon name={i.icon} className="size-4" />
                            {i.name}
                        </button>
                    ))}
                    <label className="ml-auto">
                        <span className="sr-only">State</span>
                        <Select value={f.state} onChange={(e) => set({ state: e.target.value }, true)} className="min-h-10">
                            <option value="">All states</option>
                            {states.map((s) => (
                                <option key={s.code} value={s.code}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </label>
                </div>

                <div className="mt-6 flex flex-wrap items-center gap-3 text-sm text-muted" aria-live="polite">
                    <span>
                        {groups.length} {groups.length === 1 ? 'network' : 'networks'}
                        {cityName && (
                            <>
                                {' '}
                                with their seat in <strong className="text-ink">{cityName}</strong>
                            </>
                        )}
                    </span>
                    {filtered && (
                        <button type="button" onClick={clear} className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
                            <X className="size-4" aria-hidden /> Clear filters
                        </button>
                    )}
                </div>

                {groups.length > 0 ? (
                    <ul className="mt-4 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {groups.map((g) => (
                            <NetworkCard key={g.slug} g={g} cityId={filters.city?.id} claimHref={claimHref} />
                        ))}
                    </ul>
                ) : filtered ? (
                    <div className="mt-4 rounded-2xl border border-dashed border-line bg-surface px-6 py-12 text-center">
                        <h2 className="font-display text-xl font-bold">No networks match</h2>
                        <p className="mt-2 text-muted">Try another industry or state, or clear the filters.</p>
                        <Button className="mt-5" variant="secondary" onClick={clear}>
                            Clear filters
                        </Button>
                    </div>
                ) : (
                    <div className="mt-4 rounded-2xl border border-line bg-surface px-6 py-14 text-center">
                        <MapPinned className="mx-auto size-10 text-primary" aria-hidden />
                        <h2 className="mt-3 font-display text-2xl font-bold">Networks are opening</h2>
                        <p className="mx-auto mt-2 max-w-md text-muted">
                            The first networks open soon. Sign up now to be first in line for the seat in your city.
                        </p>
                        <Button asChild className="mt-6">
                            <Link href={claimHref}>Claim your city</Link>
                        </Button>
                    </div>
                )}
            </section>

            {/* How a seat works */}
            <section className="border-y border-line bg-surface">
                <div className="mx-auto max-w-6xl px-5 py-14 sm:px-8">
                    <h2 className="font-display text-3xl font-bold">How a seat works</h2>
                    <ol className="mt-8 grid gap-6 md:grid-cols-3">
                        {steps.map((s, i) => (
                            <li key={s.title} className="rounded-2xl border border-line bg-bg p-6">
                                <div className="flex items-center gap-3">
                                    <span className="inline-flex size-10 items-center justify-center rounded-full bg-cobalt-50 text-primary dark:bg-cobalt-500/20">
                                        <s.icon className="size-5" aria-hidden />
                                    </span>
                                    <span className="text-sm font-semibold text-muted">Step {i + 1}</span>
                                </div>
                                <h3 className="mt-4 text-lg font-semibold">{s.title}</h3>
                                <p className="mt-1 text-muted">{s.body}</p>
                            </li>
                        ))}
                    </ol>
                    <div className="mt-10 flex flex-col items-start justify-between gap-4 rounded-2xl bg-navy-900 p-6 text-white sm:flex-row sm:items-center">
                        <div>
                            <p className="font-display text-xl font-bold">Don’t see your industry?</p>
                            <p className="text-white/80">Tell us which network you’d join, and we’ll let you know when it opens.</p>
                        </div>
                        <Button asChild variant="secondary">
                            <Link href="/contact">
                                Request a network <ArrowRight aria-hidden />
                            </Link>
                        </Button>
                    </div>
                </div>
            </section>

            {/* The plain-language summary search engines and answer engines quote */}
            <section className="mx-auto max-w-6xl px-5 py-10 sm:px-8">
                <h2 className="text-sm font-semibold">About this directory</h2>
                <p className="mt-2 max-w-3xl text-sm text-muted">{answer}</p>
                {updatedOn && <p className="mt-1 text-xs text-muted">Updated {updatedOn}</p>}
            </section>
        </PublicLayout>
    );
}
