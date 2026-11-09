import { router, useForm } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type StateRow = { id: number; code: string; name: string; enabled: boolean; cities: number; enabledCities: number };
type CityRow = {
    id: number;
    name: string;
    state: string;
    county: string | null;
    countyId: number | null;
    population: number;
    enabled: boolean;
    aliases: { id: number; alias: string }[];
};
type Props = {
    states: StateRow[];
    cities: CityRow[];
    pagination: { current: number; last: number; total: number };
    filters: { state: string; q: string; show?: string };
    totals: { cities: number; counties: number; metros: number };
    status?: string;
    seo: Seo;
};

const messages: Record<string, string> = { 'location-saved': 'Saved.', 'location-merged': 'Merged. The duplicate’s name now works as an alias.' };

export default function Locations({ states, cities, pagination, filters, totals, status, seo }: Props) {
    const [f, setF] = useState(filters);
    const [merging, setMerging] = useState<CityRow | null>(null);
    const alias = useForm({ alias: '' });
    const go = (e?: FormEvent, page = 1) => {
        e?.preventDefault();
        router.get('/admin/locations', { ...f, page }, { preserveState: true, preserveScroll: true });
    };
    return (
        <AdminLayout title="Locations">
            <SeoHead seo={seo} />
            <p className="mb-6 text-muted">
                {totals.cities.toLocaleString()} cities, {totals.counties.toLocaleString()} counties and {totals.metros.toLocaleString()} metro areas.
                Disabled places can’t hold seats or appear in pickers.
            </p>
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {messages[status] ?? status}
                </Alert>
            )}
            <div className="grid gap-6 xl:grid-cols-[320px_1fr]">
                <Card className="max-h-[70vh] overflow-y-auto">
                    <h2 className="sticky top-0 border-b border-line bg-surface px-4 py-3 text-sm font-semibold">States</h2>
                    <ul className="divide-y divide-line">
                        {states.map((s) => (
                            <li key={s.id} className="flex items-center gap-3 px-4 py-2.5 text-sm">
                                <button
                                    className="min-w-0 flex-1 text-left hover:text-primary"
                                    onClick={() => router.get('/admin/locations', { state: s.code }, { preserveScroll: true })}
                                >
                                    <span className="font-medium">{s.name}</span>
                                    <span className="ml-2 text-xs text-muted tabular">{s.enabledCities.toLocaleString()} cities</span>
                                </button>
                                <input
                                    type="checkbox"
                                    role="switch"
                                    aria-label={`${s.name} enabled`}
                                    className="size-4"
                                    checked={s.enabled}
                                    onChange={() => router.patch(`/admin/locations/states/${s.id}`, {}, { preserveScroll: true })}
                                />
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="min-w-0 space-y-4">
                    <Card className="p-4">
                        <form onSubmit={go} className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                            <Field label="City or alias">
                                <Input value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder="e.g. st george" />
                            </Field>
                            <Field label="State">
                                <Select value={f.state} onChange={(e) => setF({ ...f, state: e.target.value })}>
                                    <option value="">All</option>
                                    {states.map((s) => (
                                        <option key={s.code} value={s.code}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Show">
                                <Select value={f.show ?? ''} onChange={(e) => setF({ ...f, show: e.target.value })}>
                                    <option value="">All</option>
                                    <option value="disabled">Disabled only</option>
                                </Select>
                            </Field>
                            <Button type="submit">
                                <Search /> Find
                            </Button>
                        </form>
                    </Card>
                    <Card className="overflow-x-auto">
                        <table className="w-full min-w-[720px] text-sm">
                            <thead className="text-left text-muted">
                                <tr>
                                    {['City', 'County', 'Population', 'Also known as', 'Open for seats', ''].map((h) => (
                                        <th key={h} scope="col" className="px-4 py-3 font-medium">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {cities.map((c) => (
                                    <tr key={c.id} className="border-t border-line align-top">
                                        <td className="px-4 py-3 font-medium">
                                            {c.name}, {c.state}
                                        </td>
                                        <td className="px-4 py-3 text-muted">{c.county ?? '—'}</td>
                                        <td className="px-4 py-3 tabular">{c.population ? c.population.toLocaleString() : '—'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                {c.aliases.map((a) => (
                                                    <Badge key={a.id}>
                                                        {a.alias}
                                                        <button
                                                            aria-label={`Remove alias ${a.alias}`}
                                                            onClick={() =>
                                                                router.delete(`/admin/locations/aliases/${a.id}`, { preserveScroll: true })
                                                            }
                                                        >
                                                            <X className="size-3" />
                                                        </button>
                                                    </Badge>
                                                ))}
                                                <form
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        alias.post(`/admin/locations/cities/${c.id}/aliases`, {
                                                            preserveScroll: true,
                                                            onSuccess: () => alias.reset(),
                                                        });
                                                    }}
                                                >
                                                    <input
                                                        aria-label={`Add alias for ${c.name}`}
                                                        placeholder="+ alias"
                                                        className="w-20 rounded border border-line bg-transparent px-1.5 text-xs"
                                                        onChange={(e) => alias.setData('alias', e.target.value)}
                                                    />
                                                </form>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                role="switch"
                                                aria-label={`${c.name} open for seats`}
                                                className="size-4"
                                                checked={c.enabled}
                                                onChange={() =>
                                                    router.patch(`/admin/locations/cities/${c.id}`, { enabled: !c.enabled }, { preserveScroll: true })
                                                }
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Button size="sm" variant="ghost" onClick={() => setMerging(c)}>
                                                Merge…
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                    {pagination.last > 1 && (
                        <div className="flex items-center justify-between text-sm">
                            <Button
                                variant="secondary"
                                size="sm"
                                disabled={pagination.current <= 1}
                                onClick={() => go(undefined, pagination.current - 1)}
                            >
                                Previous
                            </Button>
                            <span className="text-muted">
                                Page {pagination.current} of {pagination.last} · {pagination.total.toLocaleString()} cities
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                disabled={pagination.current >= pagination.last}
                                onClick={() => go(undefined, pagination.current + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>
            </div>
            <Dialog open={merging !== null} onOpenChange={(o) => !o && setMerging(null)}>
                <DialogContent
                    title={`Merge ${merging?.name ?? ''} into…`}
                    description="Use this for duplicates. The duplicate is removed and its name keeps working as an alias."
                >
                    {merging && (
                        <CityPicker
                            state={merging.state}
                            onPick={(into) =>
                                router.post(
                                    `/admin/locations/cities/${merging.id}/merge`,
                                    { into: into.id },
                                    { preserveScroll: true, onSuccess: () => setMerging(null) },
                                )
                            }
                        />
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
