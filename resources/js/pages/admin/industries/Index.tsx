import { Link, useForm } from '@inertiajs/react';
import { Download, Plus, Upload } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Row = {
    slug: string;
    name: string;
    family: string;
    tier: 'A' | 'B' | 'C';
    territory: string;
    status: string;
    approved: boolean;
    coverage: { done: number; of: number } | null;
};
export const tierTone: Record<string, BadgeTone> = { A: 'success', B: 'warning', C: 'danger' };

export default function Industries({
    industries,
    families,
    territories,
    status,
    seo,
}: {
    industries: Row[];
    families: Record<string, string>;
    territories: Record<string, string>;
    status?: string;
    seo: Seo;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: '',
        family: 'home',
        tier: 'A',
        territory_unit: 'city',
        terminology: { pro: 'pro', pros: 'pros', client: 'client', clients: 'clients' },
        icon: 'Briefcase',
        description: '',
        license_required: true,
    });
    const create = (e: FormEvent) => {
        e.preventDefault();
        form.post('/admin/industries');
    };
    return (
        <AdminLayout
            title="Industries"
            actions={
                <>
                    <Button variant="secondary" asChild>
                        <Link href="/admin/industries/launch">Launch readiness</Link>
                    </Button>
                    <Button variant="secondary" asChild>
                        <a href="/admin/industries/licensing.csv">
                            <Download /> Licensing CSV
                        </a>
                    </Button>
                    <label className={cn(buttonVariants({ variant: 'secondary' }), 'cursor-pointer')}>
                        <Upload className="size-4" aria-hidden /> Import CSV
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            className="sr-only"
                            onChange={(e) =>
                                e.target.files?.[0] &&
                                router.post('/admin/industries/licensing/import', { file: e.target.files[0] }, { forceFormData: true })
                            }
                        />
                    </label>
                    <Button onClick={() => setOpen(true)}>
                        <Plus /> New industry
                    </Button>
                </>
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Industry', 'Tier', 'Territory', 'Licensing coverage', 'Status'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {industries.map((i) => (
                            <tr key={i.slug} className="border-t border-line">
                                <td className="px-4 py-3">
                                    <Link href={`/admin/industries/${i.slug}`} className="font-medium hover:text-primary">
                                        {i.name}
                                    </Link>
                                    <div className="text-xs text-muted">{i.family}</div>
                                </td>
                                <td className="px-4 py-3">
                                    <Badge tone={tierTone[i.tier]}>Tier {i.tier}</Badge>
                                </td>
                                <td className="px-4 py-3">{i.territory}</td>
                                <td className="px-4 py-3">
                                    {i.coverage ? (
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 w-24 rounded-full bg-bg">
                                                <div
                                                    className="h-2 rounded-full bg-primary"
                                                    style={{ width: `${Math.round((i.coverage.done / Math.max(1, i.coverage.of)) * 100)}%` }}
                                                />
                                            </div>
                                            <span className="text-muted tabular">
                                                {i.coverage.done}/{i.coverage.of} states
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-muted">No license needed</span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {i.status === 'live' ? (
                                        <Badge tone="success">Live</Badge>
                                    ) : i.approved ? (
                                        <Badge tone="info">Approved · not live</Badge>
                                    ) : (
                                        <Badge>Draft · needs approval</Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {industries.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-muted">
                                    No industries yet. The 46 launch industries are loaded in Sprint 9; you can create one now to try the builder.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent
                    title="New industry"
                    description="It starts as a draft. Nothing is public until it has compliance approval and you take it live."
                >
                    <form onSubmit={create} className="space-y-4" noValidate>
                        <Field label="Name" error={form.errors.name}>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Solar" />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Family">
                                <Select value={form.data.family} onChange={(e) => form.setData('family', e.target.value)}>
                                    {Object.entries(families).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Territory">
                                <Select value={form.data.territory_unit} onChange={(e) => form.setData('territory_unit', e.target.value)}>
                                    {Object.entries(territories).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <Field label="Compliance tier" hint="You can change this later; changing it removes any approval.">
                            <Select value={form.data.tier} onChange={(e) => form.setData('tier', e.target.value)}>
                                <option value="A">A · Open</option>
                                <option value="B">B · Restricted</option>
                                <option value="C">C · Directory only</option>
                            </Select>
                        </Field>
                        <div className="flex justify-end gap-2 border-t border-line pt-4">
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                Create draft
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
