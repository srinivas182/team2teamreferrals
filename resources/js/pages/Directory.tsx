import { Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { PublicTeam } from '@/components/directory/TeamCard';
import type { Seo } from '@/types/seo';

type Row = {
    slug: string;
    name: string;
    industry: string;
    industrySlug: string;
    tier: string;
    description: string;
    seatsFilled: number;
    unit: string;
    citySeat?: { territory: string; status: string; team: (PublicTeam & { since: string }) | null; waitlist: number } | null;
};
type Props = {
    groups: Row[];
    answer: string;
    filters: { industry: string; state: string; q: string; city: { id: number; name: string } | null };
    industries: { slug: string; name: string }[];
    states: { code: string; name: string }[];
    updatedOn: string | null;
    seo: Seo;
};

/** The group directory: filter by industry, state, city and search; with a city chosen, each network shows its seat there. */
export default function Directory({ groups, answer, filters, industries, states, updatedOn, seo }: Props) {
    const [f, setF] = useState({ industry: filters.industry, state: filters.state, q: filters.q, city: filters.city?.id ?? 0 });
    const [cityName, setCityName] = useState(filters.city?.name ?? '');
    const apply = (next = f) =>
        router.get('/groups', Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v !== 0)), {
            preserveScroll: true,
            preserveState: true,
        });
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-5xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Referral network directory</h1>
                <p className="mt-4 text-lg">{answer}</p>
                {updatedOn && <p className="mt-2 text-sm text-muted">Updated {updatedOn}</p>}
                <form
                    role="search"
                    className="mt-6 grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply();
                    }}
                >
                    <Field label="Search">
                        <Input value={f.q} placeholder="Network or industry" onChange={(e) => setF({ ...f, q: e.target.value })} />
                    </Field>
                    <Field label="Industry">
                        <Select value={f.industry} onChange={(e) => setF({ ...f, industry: e.target.value })}>
                            <option value="">All industries</option>
                            {industries.map((i) => (
                                <option key={i.slug} value={i.slug}>
                                    {i.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="State">
                        <Select value={f.state} onChange={(e) => setF({ ...f, state: e.target.value })}>
                            <option value="">All states</option>
                            {states.map((s) => (
                                <option key={s.code} value={s.code}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label={cityName ? `City: ${cityName}` : 'City'}>
                        <CityPicker
                            onPick={(c) => {
                                setCityName(c.label);
                                setF({ ...f, city: c.id });
                            }}
                        />
                    </Field>
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-4">
                        <Button type="submit">
                            <Search /> Show networks
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => {
                                const empty = { industry: '', state: '', q: '', city: 0 };
                                setF(empty);
                                setCityName('');
                                apply(empty);
                            }}
                        >
                            Clear
                        </Button>
                    </div>
                </form>
                <p className="mt-6 text-sm text-muted" aria-live="polite">
                    {groups.length} {groups.length === 1 ? 'network' : 'networks'}
                </p>
                <ul className="mt-3 grid gap-3 sm:grid-cols-2">
                    {groups.map((g) => (
                        <li key={g.slug} className="rounded-lg border border-line p-4">
                            <Link href={`/groups/${g.slug}`} className="font-semibold text-primary">
                                {g.name}
                            </Link>
                            <p className="text-sm text-muted">
                                {g.industry} · {g.seatsFilled} {g.seatsFilled === 1 ? 'seat' : 'seats'} filled · one team per {g.unit}
                            </p>
                            {g.description && <p className="mt-2 line-clamp-2 text-sm">{g.description}</p>}
                            {g.citySeat && (
                                <p className="mt-2 text-sm">
                                    {g.citySeat.team ? (
                                        <>
                                            {g.citySeat.territory}:{' '}
                                            <Link href={`/t/${g.citySeat.team.slug}`} className="text-primary">
                                                {g.citySeat.team.name}
                                            </Link>
                                        </>
                                    ) : g.citySeat.status === 'open' ? (
                                        <>{g.citySeat.territory}: seat open</>
                                    ) : (
                                        <>{g.citySeat.territory}: seat filled</>
                                    )}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
        </PublicLayout>
    );
}
