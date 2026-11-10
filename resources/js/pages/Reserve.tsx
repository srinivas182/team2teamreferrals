import { useForm } from '@inertiajs/react';
import { ArrowRight, Check, Gift, Lock, Trophy, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    launchAt: string;
    counters: { reservations: number; cities: number; states: number };
    wall: { name: string; city: string; industry: string }[];
    points: { lat: number; lon: number; industry: string }[];
    race: { state: string; name: string; count: number }[];
    industries: { slug: string; name: string; territory: string }[];
    status?: string;
    seo: Seo;
};

function Countdown({ to }: { to: string }) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(t);
    }, []);
    const s = Math.max(0, Math.floor((new Date(to).getTime() - now) / 1000));
    const parts: [number, string][] = [
        [Math.floor(s / 86400), 'days'],
        [Math.floor(s / 3600) % 24, 'hours'],
        [Math.floor(s / 60) % 60, 'min'],
        [s % 60, 'sec'],
    ];
    return (
        <div className="flex gap-3" role="timer" aria-label="Time until launch">
            {parts.map(([v, l]) => (
                <div key={l} className="min-w-16 rounded-lg bg-white/10 px-3 py-2 text-center">
                    <div className="font-display text-2xl font-extrabold tabular">{String(v).padStart(2, '0')}</div>
                    <div className="text-xs text-[#C9D2EA]">{l}</div>
                </div>
            ))}
        </div>
    );
}

const inFrame = (p: { lat: number; lon: number }) => p.lon > -125 && p.lon < -66 && p.lat > 24 && p.lat < 50;

export default function Reserve({ launchAt, counters, wall, points, race, industries, status, seo }: Props) {
    const [mapIndustry, setMapIndustry] = useState('all');
    const ref = typeof window === 'undefined' ? '' : (new URLSearchParams(window.location.search).get('ref') ?? '');
    const [city, setCity] = useState<CityOption | null>(null);
    const [avail, setAvail] = useState<{ open: boolean; territory: string; reason: string | null } | null>(null);
    const form = useForm({ industry: industries[0]?.slug ?? '', city_id: 0, name: '', business: '', email: '', on_wall: true, website: '', ref });
    const e = form.errors as Record<string, string>;
    useEffect(() => {
        if (!city || !form.data.industry) return;
        fetch(`/reserve/check?industry=${form.data.industry}&city_id=${city.id}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then(setAvail)
            .catch(() => setAvail(null));
    }, [city, form.data.industry]);
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section
                className="bg-navy-900 text-white"
                style={{
                    backgroundImage:
                        'radial-gradient(circle at 85% 15%,rgba(255,122,26,.35),transparent 45%),radial-gradient(circle at 5% 95%,rgba(36,81,245,.5),transparent 50%)',
                }}
            >
                <div className="mx-auto grid max-w-6xl gap-10 px-5 py-14 sm:py-20 lg:grid-cols-[1.1fr_1fr] lg:items-center">
                    <div>
                        <span className="rounded-full bg-orange-500/20 px-3 py-1 text-sm font-medium text-orange-200">
                            Founding member reservations are open
                        </span>
                        <h1 className="mt-5 font-display text-[38px] leading-[1.05] font-extrabold display sm:text-6xl">
                            Reserve your city before launch day.
                        </h1>
                        <p className="mt-5 max-w-xl text-lg text-[#C9D2EA]">
                            One verified pro per city, per industry. Founding members keep their price for as long as they hold the seat, and get a
                            badge no one can earn after launch.
                        </p>
                        <div className="mt-8">
                            <p className="mb-2 text-sm text-[#C9D2EA]">National launch in</p>
                            <Countdown to={launchAt} />
                        </div>
                        <p className="mt-6 text-sm text-[#C9D2EA] tabular">
                            {counters.reservations} reservations · {counters.cities} cities · {counters.states} states
                        </p>
                    </div>
                    <Card className="p-6 text-ink">
                        {status === 'reservation-sent' ? (
                            <div className="space-y-3">
                                <h2 className="font-display text-xl font-bold">Check your email</h2>
                                <p className="text-muted">We sent a link to confirm your reservation. It takes one click; no card needed.</p>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <h2 className="font-display text-xl font-bold">Reserve in under two minutes</h2>
                                <Field label="Your industry" error={e.industry}>
                                    <Select
                                        value={form.data.industry}
                                        onChange={(ev) => {
                                            setAvail(null);
                                            form.setData('industry', ev.target.value);
                                        }}
                                    >
                                        {industries.map((i) => (
                                            <option key={i.slug} value={i.slug}>
                                                {i.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                <Field label="Your city" error={e.city_id}>
                                    <CityPicker
                                        onPick={(c) => {
                                            setAvail(null);
                                            setCity(c);
                                            form.setData('city_id', c.id);
                                        }}
                                    />
                                </Field>
                                {avail && (
                                    <p
                                        className={
                                            avail.open
                                                ? 'flex items-center gap-2 rounded-md bg-[#ECFDF3] p-3 text-sm text-success dark:bg-success/15'
                                                : 'rounded-md bg-[#FEF9E7] p-3 text-sm text-warning dark:bg-warning/15'
                                        }
                                    >
                                        {avail.open ? (
                                            <>
                                                <Check className="size-4" aria-hidden /> {avail.territory} is open
                                            </>
                                        ) : (
                                            avail.reason
                                        )}
                                    </p>
                                )}
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Your name" error={e.name}>
                                        <Input value={form.data.name} onChange={(ev) => form.setData('name', ev.target.value)} autoComplete="name" />
                                    </Field>
                                    <Field label="Business (optional)" error={e.business}>
                                        <Input
                                            value={form.data.business}
                                            onChange={(ev) => form.setData('business', ev.target.value)}
                                            autoComplete="organization"
                                        />
                                    </Field>
                                </div>
                                <Field label="Email" error={e.email}>
                                    <Input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(ev) => form.setData('email', ev.target.value)}
                                        autoComplete="email"
                                    />
                                </Field>
                                <input
                                    type="text"
                                    name="website"
                                    tabIndex={-1}
                                    autoComplete="off"
                                    className="hidden"
                                    aria-hidden
                                    value={form.data.website}
                                    onChange={(ev) => form.setData('website', ev.target.value)}
                                />
                                <label className="flex items-center gap-2 text-sm text-muted">
                                    <input
                                        type="checkbox"
                                        checked={form.data.on_wall}
                                        onChange={(ev) => form.setData('on_wall', ev.target.checked)}
                                    />{' '}
                                    Show my business on the founding member wall
                                </label>
                                <Button
                                    size="lg"
                                    className="w-full"
                                    disabled={!city}
                                    loading={form.processing}
                                    onClick={() => form.post('/reserve', { preserveScroll: true })}
                                >
                                    Reserve {city ? city.name : 'my city'} <ArrowRight />
                                </Button>
                                <p className="flex items-center gap-2 text-xs text-muted">
                                    <Lock className="size-3" aria-hidden /> No card needed. Your license is checked before launch.
                                </p>
                            </div>
                        )}
                    </Card>
                </div>
            </section>
            <section className="mx-auto grid max-w-6xl gap-4 px-5 py-10 sm:grid-cols-3">
                {[
                    [Gift, 'Founding price, locked', 'Keep your price for as long as you hold your city.'],
                    [Trophy, 'Founding member badge', 'Shown on your profile. It can’t be earned after launch day.'],
                    [Users, 'Taken? Still worth it', 'Reserve anyway to back the seat up or join the waitlist; founders are first in line.'],
                ].map(([I, t, d]) => {
                    const Icon = I as typeof Gift;
                    return (
                        <Card key={t as string} className="p-6">
                            <Icon className="text-orange-500" aria-hidden />
                            <h3 className="mt-3 font-semibold">{t as string}</h3>
                            <p className="mt-1 text-sm text-muted">{d as string}</p>
                        </Card>
                    );
                })}
            </section>
            <section className="mx-auto grid max-w-6xl gap-6 px-5 pb-10 lg:grid-cols-[1fr_320px]">
                <Card className="p-5">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 className="font-semibold">Live reservations</h2>
                        <Select
                            aria-label="Industry on the map"
                            className="ml-auto h-9 w-56"
                            value={mapIndustry}
                            onChange={(ev) => setMapIndustry(ev.target.value)}
                        >
                            <option value="all">All industries</option>
                            {industries.map((i) => (
                                <option key={i.slug} value={i.slug}>
                                    {i.name}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <svg viewBox="0 0 960 520" className="mt-3 h-auto w-full" role="img" aria-label={`${points.length} reservations on the map`}>
                        <rect width="960" height="520" rx="14" className="fill-bg" />
                        {points
                            .filter((p) => inFrame(p) && (mapIndustry === 'all' || p.industry === mapIndustry))
                            .map((p, i) => (
                                <circle
                                    key={i}
                                    cx={((p.lon + 125) / 59) * 960}
                                    cy={((50 - p.lat) / 26) * 520}
                                    r="7"
                                    fill="#FF7A1A"
                                    fillOpacity="0.8"
                                    stroke="#0E1B3D"
                                    strokeOpacity="0.3"
                                />
                            ))}
                    </svg>
                </Card>
                <Card className="p-6">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Trophy className="size-5 text-orange-500" aria-hidden /> State race
                    </h2>
                    {race.length === 0 ? (
                        <p className="mt-3 text-sm text-muted">Be the first in your state.</p>
                    ) : (
                        <ol className="mt-4 space-y-3">
                            {race.map((r, i) => (
                                <li key={r.state}>
                                    <div className="flex justify-between text-sm">
                                        <span>
                                            <span className="mr-2 font-display font-bold text-muted">{i + 1}</span>
                                            {r.name}
                                        </span>
                                        <span className="text-muted tabular">{r.count}</span>
                                    </div>
                                    <div className="mt-1 h-1.5 rounded-full bg-bg">
                                        <div
                                            className="h-1.5 rounded-full"
                                            style={{
                                                width: `${(r.count / race[0]!.count) * 100}%`,
                                                background: 'linear-gradient(90deg,#2451F5,#FF7A1A)',
                                            }}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ol>
                    )}
                </Card>
            </section>
            {wall.length > 0 && (
                <section className="mx-auto max-w-6xl px-5 pb-16">
                    <h2 className="font-display text-2xl font-bold">Founding members</h2>
                    <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {wall.map((w, i) => (
                            <li key={i} className="rounded-lg border border-line bg-surface p-4">
                                <div className="font-medium">{w.name}</div>
                                <div className="text-sm text-muted">
                                    {w.industry} · {w.city}
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </PublicLayout>
    );
}
