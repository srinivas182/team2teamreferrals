import { Link, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type City = { id: number; name: string };
type Props = {
    group: { slug: string; name: string };
    basePath: string;
    limited: boolean;
    cities: Record<string, City[]>;
    states: { code: string; name: string }[];
    heldOutside: number;
    status?: string;
    seo: Seo;
};

/**
 * Which cities can join a group: all (the default), or only a list. Pick a state, tick its cities, add more states,
 * then save. Teams already holding seats elsewhere keep them; only new applications are limited.
 */
export default function GroupCitiesPage({ group, basePath, limited, cities, states, heldOutside, status, seo }: Props) {
    const [picked, setPicked] = useState<Record<string, City[]>>(cities);
    const [state, setState] = useState('');
    const [options, setOptions] = useState<City[]>([]);
    const [loading, setLoading] = useState(false);
    const [search, setSearch] = useState('');
    const form = useForm({ limited, cities: [] as number[] });
    const errors = form.errors as Record<string, string>;
    const total = Object.values(picked).reduce((n, l) => n + l.length, 0);

    // Open a state: load its cities (biggest first) to tick.
    const openState = (code: string) => {
        setState(code);
        setSearch('');
        setOptions([]);
        if (!code) return;
        setLoading(true);
        fetch(`${basePath}/cities/state/${code}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((list: City[]) => setOptions(list))
            .finally(() => setLoading(false));
    };

    const chosen = new Set((picked[state] ?? []).map((c) => c.id));
    const shown = useMemo(() => options.filter((c) => c.name.toLowerCase().includes(search.toLowerCase())).slice(0, 300), [options, search]);
    const toggle = (c: City) =>
        setPicked((p) => {
            const list = p[state] ?? [];
            const next = list.some((x) => x.id === c.id)
                ? list.filter((x) => x.id !== c.id)
                : [...list, c].sort((a, b) => a.name.localeCompare(b.name));
            return { ...p, [state]: next };
        });
    const remove = (code: string, id: number) => setPicked((p) => ({ ...p, [code]: (p[code] ?? []).filter((x) => x.id !== id) }));
    const save = () => {
        form.transform((d) => ({ ...d, cities: Object.values(picked).flatMap((l) => l.map((c) => c.id)) }));
        form.put(`${basePath}/cities`, { preserveScroll: true });
    };

    return (
        <AdminLayout title={`Cities · ${group.name}`}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-4">
                <Link
                    href={basePath.startsWith('/admin') ? `/admin/groups/${group.slug}` : `/app/group/${group.slug}`}
                    className="text-sm font-medium text-primary"
                >
                    ← {group.name}
                </Link>
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {heldOutside > 0 && limited && (
                    <Alert tone="info">
                        {heldOutside} {heldOutside === 1 ? 'team holds a seat' : 'teams hold seats'} in cities not on this list. They keep their
                        seats; only new applications are limited.
                    </Alert>
                )}
                <Card className="space-y-4 p-6">
                    <div>
                        <h2 className="font-semibold">Which cities can join</h2>
                        <p className="text-sm text-muted">Teams can only apply for a seat in a city this allows.</p>
                    </div>
                    <fieldset className="grid gap-3 sm:grid-cols-2">
                        <legend className="sr-only">Which cities can join</legend>
                        {(
                            [
                                [false, 'All cities', 'The default. A team in any US city can apply.'],
                                [true, 'Only these cities', 'Choose cities state by state below.'],
                            ] as const
                        ).map(([value, title, tip]) => (
                            <label
                                key={title}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-4 text-sm',
                                    form.data.limited === value ? 'border-primary bg-cobalt-50/60 dark:bg-cobalt-500/10' : 'border-line',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="limited"
                                    className="mt-1"
                                    checked={form.data.limited === value}
                                    onChange={() => form.setData('limited', value)}
                                />
                                <span>
                                    <span className="block font-semibold">{title}</span>
                                    <span className="text-muted">{tip}</span>
                                </span>
                            </label>
                        ))}
                    </fieldset>

                    {form.data.limited && (
                        <div className="space-y-4">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="State">
                                    <Select value={state} onChange={(e) => openState(e.target.value)}>
                                        <option value="">Choose a state…</option>
                                        {states.map((s) => (
                                            <option key={s.code} value={s.code}>
                                                {s.name}
                                                {picked[s.code]?.length ? ` (${picked[s.code]?.length} chosen)` : ''}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                                {state && (
                                    <Field label="Find a city">
                                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Type to filter" />
                                    </Field>
                                )}
                            </div>
                            {state && (
                                <div>
                                    <div className="mb-2 flex flex-wrap gap-3 text-sm">
                                        <button
                                            type="button"
                                            className="font-medium text-primary"
                                            onClick={() =>
                                                setPicked((p) => ({ ...p, [state]: [...shown].sort((a, b) => a.name.localeCompare(b.name)) }))
                                            }
                                        >
                                            Select all shown
                                        </button>
                                        <button
                                            type="button"
                                            className="font-medium text-primary"
                                            onClick={() => setPicked((p) => ({ ...p, [state]: [] }))}
                                        >
                                            Clear this state
                                        </button>
                                    </div>
                                    <div
                                        className="grid max-h-80 gap-x-4 gap-y-1 overflow-y-auto rounded-md border border-line p-3 text-sm sm:grid-cols-3"
                                        aria-busy={loading}
                                    >
                                        {loading && <p className="text-muted">Loading cities…</p>}
                                        {!loading && shown.length === 0 && <p className="text-muted">No cities match.</p>}
                                        {shown.map((c) => (
                                            <label key={c.id} className="flex items-center gap-2 py-0.5">
                                                <input type="checkbox" checked={chosen.has(c.id)} onChange={() => toggle(c)} /> {c.name}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                            <div>
                                <h3 className="text-sm font-semibold">
                                    Chosen: {total} {total === 1 ? 'city' : 'cities'}
                                </h3>
                                {errors.cities && <p className="text-sm text-danger">{errors.cities}</p>}
                                <div className="mt-2 space-y-2">
                                    {Object.entries(picked)
                                        .filter(([, l]) => l.length > 0)
                                        .map(([code, list]) => (
                                            <div key={code} className="text-sm">
                                                <button type="button" className="font-medium hover:text-primary" onClick={() => openState(code)}>
                                                    {states.find((s) => s.code === code)?.name ?? code}
                                                </button>
                                                <ul className="mt-1 flex flex-wrap gap-1.5">
                                                    {list.map((c) => (
                                                        <li
                                                            key={c.id}
                                                            className="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-2.5 py-0.5"
                                                        >
                                                            {c.name}
                                                            <button type="button" aria-label={`Remove ${c.name}`} onClick={() => remove(code, c.id)}>
                                                                <X className="size-3.5" aria-hidden />
                                                            </button>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                </div>
                            </div>
                        </div>
                    )}
                    <Button onClick={save} loading={form.processing}>
                        Save
                    </Button>
                </Card>
            </div>
        </AdminLayout>
    );
}
