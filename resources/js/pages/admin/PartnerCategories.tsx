import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Category = {
    id: number | null;
    slug: string;
    name: string;
    singular: string;
    licensed: boolean;
    licenseLabel: string | null;
    licenseLookupUrl: string | null;
    schemaType: string;
    faq: { q: string; a: string }[];
    sort: number;
    active: boolean;
    partners: number;
};

const blank: Category = {
    id: null,
    slug: '',
    name: '',
    singular: '',
    licensed: false,
    licenseLabel: null,
    licenseLookupUrl: null,
    schemaType: 'ProfessionalService',
    faq: [],
    sort: 0,
    active: true,
    partners: 0,
};

/** One category: name, license rule, regulator lookup, Schema.org type, and the FAQ shown on its city pages. */
function CategoryForm({ c, schemaTypes }: { c: Category; schemaTypes: string[] }) {
    const f = useForm({
        name: c.name,
        singular: c.singular,
        licensed: c.licensed,
        license_label: c.licenseLabel ?? '',
        license_lookup_url: c.licenseLookupUrl ?? '',
        schema_type: c.schemaType,
        faq: c.faq,
        sort: c.sort,
        active: c.active,
    });
    const e = f.errors as Record<string, string | undefined>;
    const save = () =>
        c.id
            ? f.put(`/admin/partners/categories/${c.id}`, { preserveScroll: true })
            : f.post('/admin/partners/categories', { preserveScroll: true, onSuccess: () => f.reset() });
    return (
        <Card className="p-5">
            <h2 className="font-semibold">
                {c.id ? c.name : 'New category'}
                {c.id && (
                    <span className="ml-2 text-sm font-normal text-muted">
                        /{c.slug} · {c.partners} partners
                    </span>
                )}
            </h2>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <Field label="Name (plural)" error={e.name}>
                    <Input value={f.data.name} onChange={(ev) => f.setData('name', ev.target.value)} />
                </Field>
                <Field label="One business is a…" hint="e.g. mortgage lender" error={e.singular}>
                    <Input value={f.data.singular} onChange={(ev) => f.setData('singular', ev.target.value)} />
                </Field>
                <Field label="License label" hint="e.g. NMLS ID" error={e.license_label}>
                    <Input value={f.data.license_label} onChange={(ev) => f.setData('license_label', ev.target.value)} />
                </Field>
                <Field label="Regulator lookup address" hint="https://… with {number} where the license number goes" error={e.license_lookup_url}>
                    <Input value={f.data.license_lookup_url} onChange={(ev) => f.setData('license_lookup_url', ev.target.value)} />
                </Field>
                <Field label="Schema.org type" error={e.schema_type}>
                    <Select value={f.data.schema_type} onChange={(ev) => f.setData('schema_type', ev.target.value)}>
                        {schemaTypes.map((t) => (
                            <option key={t}>{t}</option>
                        ))}
                    </Select>
                </Field>
                <Field label="Order" error={e.sort}>
                    <Input type="number" value={f.data.sort} onChange={(ev) => f.setData('sort', Number(ev.target.value))} />
                </Field>
            </div>
            <div className="mt-3 flex flex-wrap gap-6 text-sm">
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={f.data.licensed} onChange={(ev) => f.setData('licensed', ev.target.checked)} /> License required
                    before approval
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={f.data.active} onChange={(ev) => f.setData('active', ev.target.checked)} /> Shown on the site
                </label>
            </div>
            <div className="mt-4 space-y-3">
                <h3 className="text-sm font-medium">Questions answered on its city pages</h3>
                {f.data.faq.map((q, i) => (
                    <div key={i} className="grid gap-2 rounded-md border border-line p-3">
                        <Input
                            aria-label="Question"
                            value={q.q}
                            onChange={(ev) =>
                                f.setData(
                                    'faq',
                                    f.data.faq.map((x, j) => (j === i ? { ...x, q: ev.target.value } : x)),
                                )
                            }
                        />
                        <Textarea
                            aria-label="Answer"
                            rows={2}
                            value={q.a}
                            onChange={(ev) =>
                                f.setData(
                                    'faq',
                                    f.data.faq.map((x, j) => (j === i ? { ...x, a: ev.target.value } : x)),
                                )
                            }
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            className="justify-self-start"
                            onClick={() =>
                                f.setData(
                                    'faq',
                                    f.data.faq.filter((_, j) => j !== i),
                                )
                            }
                        >
                            <Trash2 /> Remove
                        </Button>
                    </div>
                ))}
                {f.data.faq.length < 8 && (
                    <Button variant="secondary" size="sm" onClick={() => f.setData('faq', [...f.data.faq, { q: '', a: '' }])}>
                        <Plus /> Add a question
                    </Button>
                )}
            </div>
            <Button className="mt-4" loading={f.processing} onClick={save}>
                {c.id ? 'Save' : 'Add category'}
            </Button>
        </Card>
    );
}

export default function PartnerCategories({
    categories,
    schemaTypes,
    flash,
    seo,
}: {
    categories: Category[];
    schemaTypes: string[];
    flash?: string;
    seo: Seo;
}) {
    return (
        <AdminLayout
            title="Partner categories"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/partners">Back to partners</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="max-w-4xl space-y-4">
                {categories.map((c) => (
                    <CategoryForm key={c.id} c={c} schemaTypes={schemaTypes} />
                ))}
                <CategoryForm c={blank} schemaTypes={schemaTypes} />
            </div>
        </AdminLayout>
    );
}
