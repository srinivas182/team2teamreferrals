import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { ContentTabs } from '@/pages/admin/content/Tabs';
import type { Seo } from '@/types/seo';

type Rows = Record<string, string>[];
type Content = {
    summary: string;
    intro: string;
    how: string;
    request: string;
    state_summary: string;
    city_summary: string;
    facts: { covers: string; cost: string; licensing: string; fees: string };
    cost_note: string;
    lookups: Rows;
    hire_questions: string[];
    faqs: Rows;
    glossary: Rows;
    related: string[];
    sources: Rows;
    checked: string;
};
type Props = {
    industry: { slug: string; name: string; tier: string; licenseRequired: boolean };
    content: Content;
    reviewer: string | null;
    published: boolean;
    reviewedOn: string | null;
    problems: string[];
    disclosure: string | null;
    tierFact: string;
    samples: { state: string; city: string };
    industries: { slug: string; name: string }[];
    status?: string;
    seo: Seo;
};

/** Repeating rows (FAQs, glossary, lookups, sources): add, edit and remove. */
function RowsEditor({ label, rows, keys, onChange }: { label: string; rows: Rows; keys: [string, string, boolean][]; onChange: (r: Rows) => void }) {
    const set = (i: number, k: string, v: string) => onChange(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
    return (
        <fieldset className="space-y-2">
            <legend className="font-semibold">
                {label} <span className="font-normal text-muted">({rows.length})</span>
            </legend>
            {rows.map((r, i) => (
                <div key={i} className="grid gap-2 rounded-md border border-line p-2 sm:grid-cols-[1fr_2fr_auto]">
                    {keys.map(([k, l, long]) =>
                        long ? (
                            <Textarea
                                key={k}
                                aria-label={l}
                                placeholder={l}
                                rows={2}
                                value={r[k] ?? ''}
                                onChange={(e) => set(i, k, e.target.value)}
                            />
                        ) : (
                            <Input key={k} aria-label={l} placeholder={l} value={r[k] ?? ''} onChange={(e) => set(i, k, e.target.value)} />
                        ),
                    )}
                    <Button
                        size="icon"
                        variant="ghost"
                        aria-label={`Remove ${label} row ${i + 1}`}
                        onClick={() => onChange(rows.filter((_, j) => j !== i))}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <Button size="sm" variant="secondary" onClick={() => onChange([...rows, Object.fromEntries(keys.map(([k]) => [k, '']))])}>
                <Plus /> Add
            </Button>
        </fieldset>
    );
}

/** Admin → Website → Industries → one industry: write the hub, check it, name the reviewer, publish. */
export default function IndustryEdit(p: Props) {
    const f = useForm({ content: p.content, reviewer: p.reviewer ?? '' });
    const c = f.data.content;
    const set = <K extends keyof Content>(k: K, v: Content[K]) => f.setData('content', { ...c, [k]: v });
    const errors = f.errors as Record<string, string>;
    const text = (
        k: 'summary' | 'intro' | 'how' | 'request' | 'state_summary' | 'city_summary' | 'cost_note' | 'checked',
        label: string,
        rows = 3,
    ) => (
        <Field label={label}>
            {rows === 1 ? (
                <Input value={c[k]} onChange={(e) => set(k, e.target.value)} />
            ) : (
                <Textarea rows={rows} value={c[k]} onChange={(e) => set(k, e.target.value)} />
            )}
        </Field>
    );
    return (
        <AdminLayout title={`Industry: ${p.industry.name}`}>
            <SeoHead seo={p.seo} />
            <ContentTabs current="/admin/content/industries" />
            <div className="grid max-w-6xl gap-4 lg:grid-cols-[1fr_20rem]">
                <div className="space-y-4">
                    {p.status && <Alert tone="success">{statusMessage(p.status)}</Alert>}
                    {errors.content && <Alert tone="danger">{errors.content}</Alert>}
                    <Card className="space-y-3 p-4">
                        <p className="text-sm text-muted">
                            Words in braces fill in automatically: {'{pro}'}, {'{pros}'}, {'{client}'}, {'{clients}'}, {'{industry}'}, {'{site_name}'}
                            , and on place pages {'{state}'} and {'{city}'}.
                        </p>
                        {text('summary', 'Answer-first summary (40–320 characters)')}
                        {text('intro', 'Introduction', 5)}
                        {text('how', 'How the network works', 4)}
                        {text('request', '“Request a pro” wording', 2)}
                        {text('state_summary', 'State page opening (must include {state})', 2)}
                        {text('city_summary', 'City page opening (must include {city})', 2)}
                    </Card>
                    <Card className="space-y-3 p-4">
                        <h2 className="font-semibold">Facts sheet</h2>
                        {(['covers', 'cost', 'licensing', 'fees'] as const).map((k) => (
                            <Field
                                key={k}
                                label={
                                    {
                                        covers: 'What it covers',
                                        cost: 'Typical cost (leave empty if no reliable source)',
                                        licensing: 'Licensing',
                                        fees: 'Referrals and fees',
                                    }[k]
                                }
                            >
                                <Textarea rows={2} value={c.facts[k]} onChange={(e) => set('facts', { ...c.facts, [k]: e.target.value })} />
                            </Field>
                        ))}
                        <p className="text-sm">
                            <span className="font-semibold">On this network (from the tier, not editable):</span> {p.tierFact}
                        </p>
                        {text('cost_note', 'How the cost figure was found, or why there is none', 2)}
                    </Card>
                    <Card className="space-y-4 p-4">
                        <Field label="Questions to ask before you hire (one per line)">
                            <Textarea
                                rows={6}
                                value={c.hire_questions.join('\n')}
                                onChange={(e) => set('hire_questions', e.target.value.split('\n'))}
                            />
                        </Field>
                        <RowsEditor
                            label="Questions and answers"
                            rows={c.faqs}
                            keys={[
                                ['q', 'Question', false],
                                ['a', 'Answer', true],
                            ]}
                            onChange={(r) => set('faqs', r)}
                        />
                        <RowsEditor
                            label="Glossary"
                            rows={c.glossary}
                            keys={[
                                ['term', 'Term', false],
                                ['definition', 'Definition', true],
                            ]}
                            onChange={(r) => set('glossary', r)}
                        />
                        <RowsEditor
                            label="Official license lookups"
                            rows={c.lookups}
                            keys={[
                                ['name', 'Name', false],
                                ['url', 'https:// address', false],
                            ]}
                            onChange={(r) => set('lookups', r)}
                        />
                        <RowsEditor
                            label="Sources"
                            rows={c.sources}
                            keys={[
                                ['title', 'Title', false],
                                ['url', 'https:// address', false],
                            ]}
                            onChange={(r) => set('sources', r)}
                        />
                        {text('checked', 'Facts checked on (date)', 1)}
                        <fieldset>
                            <legend className="font-semibold">Related industries</legend>
                            <div className="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                                {p.industries.map((i) => (
                                    <label key={i.slug} className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            checked={c.related.includes(i.slug)}
                                            onChange={(e) =>
                                                set('related', e.target.checked ? [...c.related, i.slug] : c.related.filter((s) => s !== i.slug))
                                            }
                                        />
                                        {i.name}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    </Card>
                </div>
                <aside className="space-y-4">
                    <Card className="space-y-3 p-4">
                        <div className="flex items-center gap-2">
                            <Badge tone={p.published ? 'success' : p.reviewer ? 'info' : 'warning'}>
                                {p.published ? 'Published' : p.reviewer ? 'Ready to publish' : 'Review pending'}
                            </Badge>
                            <span className="text-xs text-muted">Tier {p.industry.tier}</span>
                        </div>
                        <Field
                            label="Reviewed by (the expert who checked the facts)"
                            hint="Leave blank if you are a super admin publishing it: you sign as the reviewer."
                            error={errors.reviewer}
                        >
                            <Input placeholder="Review pending" value={f.data.reviewer} onChange={(e) => f.setData('reviewer', e.target.value)} />
                        </Field>
                        {p.reviewedOn && <p className="text-xs text-muted">Last reviewed {p.reviewedOn}</p>}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                loading={f.processing}
                                onClick={() => f.post(`/admin/content/industries/${p.industry.slug}`, { preserveScroll: true })}
                            >
                                Save
                            </Button>
                            <Button size="sm" variant="secondary" asChild>
                                <a href={`/preview/industries/${p.industry.slug}`} target="_blank" rel="noreferrer">
                                    Preview
                                </a>
                            </Button>
                            {p.published ? (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() =>
                                        router.post(`/admin/content/industries/${p.industry.slug}/unpublish`, {}, { preserveScroll: true })
                                    }
                                >
                                    Unpublish
                                </Button>
                            ) : (
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={p.problems.length > 0}
                                    onClick={() => router.post(`/admin/content/industries/${p.industry.slug}/publish`, {}, { preserveScroll: true })}
                                >
                                    Publish
                                </Button>
                            )}
                        </div>
                    </Card>
                    <Card className="space-y-2 p-4 text-sm">
                        <h2 className="font-semibold">Before publishing</h2>
                        {p.problems.length === 0 ? (
                            <p className="text-success">Everything is in place.</p>
                        ) : (
                            <ul className="list-disc space-y-1 pl-5">
                                {p.problems.map((x) => (
                                    <li key={x}>{x}</li>
                                ))}
                            </ul>
                        )}
                        <p className="text-xs text-muted">Checked against the saved version: save first to update.</p>
                    </Card>
                    {p.disclosure && (
                        <Card className="space-y-1 p-4 text-sm">
                            <h2 className="font-semibold">Notice shown on every page</h2>
                            <p>{p.disclosure}</p>
                        </Card>
                    )}
                    <Card className="space-y-1 p-4 text-sm">
                        <h2 className="font-semibold">Place pages (sample)</h2>
                        <p>{p.samples.state}</p>
                        <p>{p.samples.city}</p>
                    </Card>
                </aside>
            </div>
        </AdminLayout>
    );
}
