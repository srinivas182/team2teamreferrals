import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type T = {
    name: string;
    slug: string;
    cities: string[];
    groups: string[];
    specialties: string[];
    languages: string[];
    badges: { key: string; name: string }[];
};
type Props = {
    teams: T[];
    filters: Record<string, string | undefined>;
    groups: { slug: string; name: string }[];
    specialties: string[];
    languages: string[];
    badges: { key: string; name: string }[];
    seo: Seo;
};

const sel = 'h-10 rounded-md border border-line bg-surface px-2 text-sm';

/** Portal → Directory: the members of your networks, by city, specialty, language and badge. */
export default function Directory({ teams, filters, groups, specialties, languages, badges, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [f, setF] = useState<Record<string, string>>({
        city: filters.city ?? '',
        group: filters.group ?? '',
        specialty: filters.specialty ?? '',
        language: filters.language ?? '',
        badge: filters.badge ?? '',
    });
    const search = () => router.get('/app/directory', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
    return (
        <PortalLayout title="Directory" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <Card className="mb-4 grid max-w-5xl gap-2 p-4 sm:grid-cols-3 lg:grid-cols-6">
                <Input aria-label="City" placeholder="City" value={f.city} onChange={(e) => setF({ ...f, city: e.target.value })} />
                <select aria-label="Network" className={sel} value={f.group} onChange={(e) => setF({ ...f, group: e.target.value })}>
                    <option value="">All networks</option>
                    {groups.map((g) => (
                        <option key={g.slug} value={g.slug}>
                            {g.name}
                        </option>
                    ))}
                </select>
                {(
                    [
                        ['specialty', 'Any specialty', specialties.map((s) => [s, s])],
                        ['language', 'Any language', languages.map((s) => [s, s])],
                        ['badge', 'Any badge', badges.map((b) => [b.key, b.name])],
                    ] as [string, string, [string, string][]][]
                ).map(([k, all, opts]) => (
                    <select key={k} aria-label={k} className={sel} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })}>
                        <option value="">{all}</option>
                        {opts.map(([v, l]) => (
                            <option key={v} value={v}>
                                {l}
                            </option>
                        ))}
                    </select>
                ))}
                <Button onClick={search}>Search</Button>
            </Card>
            <div className="grid max-w-5xl gap-3 sm:grid-cols-2">
                {teams.length === 0 && <Card className="p-6 text-muted sm:col-span-2">No members match. Try fewer filters.</Card>}
                {teams.map((t) => (
                    <Card key={t.slug} className="space-y-2 p-4">
                        <a href={`/t/${t.slug}`} className="font-semibold hover:underline">
                            {t.name}
                        </a>
                        <p className="text-sm text-muted">
                            {t.groups.join(' · ')} · {t.cities.join(', ')}
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {t.badges.map((b) => (
                                <Badge key={b.key + b.name} tone="sender">
                                    {b.name}
                                </Badge>
                            ))}
                            {t.specialties.map((s) => (
                                <Badge key={s}>{s}</Badge>
                            ))}
                        </div>
                        {t.languages.length > 0 && <p className="text-xs text-muted">Speaks {t.languages.join(', ')}</p>}
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
