import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Row = {
    industry: { slug: string; name: string; tier: string };
    launch: { slug: string; name: string } | null;
    groups: { slug: string; name: string; region: string | null }[];
    takenRegions: string[];
    waitlisted: number | null;
    metroFill: number | null;
};

/** When a network can't seat everyone who wants in, open the next group: by region or as a second national network. */
export default function Overflow({
    rows,
    regions,
    status,
    seo,
}: {
    rows: Row[];
    regions: { key: string; label: string; states: number }[];
    status?: string;
    seo: Seo;
}) {
    const [open, setOpen] = useState<Row | null>(null);
    const form = useForm({ industry: '', region: 'national', name: '' });
    const start = (r: Row) => {
        form.setData({ industry: r.industry.slug, region: regions.find((x) => !r.takenRegions.includes(x.key))?.key ?? 'national', name: '' });
        setOpen(r);
    };
    const suggested = open?.launch
        ? `${open.launch.name}${form.data.region === 'national' ? ' II' : ` — ${regions.find((x) => x.key === form.data.region)?.label}`}`
        : '';
    return (
        <AdminLayout title="Overflow demand">
            <SeoHead seo={seo} />
            <Link href="/admin/groups" className="text-sm text-muted hover:text-ink">
                All groups
            </Link>
            <p className="mt-2 mb-4 max-w-3xl text-muted">
                When an industry’s network can’t seat everyone who wants in, open the next group. It copies the rules, pricing and customisations of
                the launch group, and (from the next sprint) waitlisted teams in its region are offered seats first. Waitlist and metro-fill numbers
                appear once seats open.
            </p>
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[820px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Industry', 'Groups', 'Waitlisted', 'Top metros filled', ''].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.industry.slug} className="border-t border-line align-top">
                                <td className="px-4 py-3 font-medium">
                                    {r.industry.name} <Badge>Tier {r.industry.tier}</Badge>
                                </td>
                                <td className="px-4 py-3">
                                    {r.groups.map((g) => (
                                        <div key={g.slug}>
                                            <Link href={`/admin/groups/${g.slug}`} className="hover:text-primary">
                                                {g.name}
                                            </Link>
                                        </div>
                                    ))}
                                </td>
                                <td className="px-4 py-3 text-muted">{r.waitlisted ?? '—'}</td>
                                <td className="px-4 py-3 text-muted">{r.metroFill === null ? '—' : `${r.metroFill}%`}</td>
                                <td className="px-4 py-3 text-right">
                                    {r.launch && (
                                        <Button size="sm" variant="secondary" onClick={() => start(r)}>
                                            Open next group
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <Dialog open={open !== null} onOpenChange={(o) => !o && setOpen(null)}>
                <DialogContent
                    title={`Open the next ${open?.industry.name.toLowerCase() ?? ''} group`}
                    description="Copies rules, pricing, form and pipeline from the launch group. A customised agreement comes as a draft that needs approval."
                >
                    <div className="space-y-4">
                        <Field label="Region" error={form.errors.region}>
                            <Select value={form.data.region} onChange={(e) => form.setData('region', e.target.value)}>
                                <option value="national">Nationwide (a second national network)</option>
                                {regions.map((r) => (
                                    <option key={r.key} value={r.key} disabled={open?.takenRegions.includes(r.key)}>
                                        {r.label} ({r.states} states){open?.takenRegions.includes(r.key) ? ' · already has a group' : ''}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Name" hint={`Leave blank to use “${suggested}”.`} error={form.errors.name}>
                            <Input value={form.data.name} placeholder={suggested} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <Button variant="ghost" onClick={() => setOpen(null)}>
                                Cancel
                            </Button>
                            <Button loading={form.processing} onClick={() => form.post('/admin/groups/overflow')}>
                                Open group
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
