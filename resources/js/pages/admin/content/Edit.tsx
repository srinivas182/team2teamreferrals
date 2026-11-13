import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type BlockItem = Record<string, string | string[]>;
type B = { type: string; [field: string]: string | boolean | BlockItem[] };
type Props = {
    page: {
        id: string;
        slug: string;
        template: string;
        status: string;
        publishAt: string | null;
        publishedVersion: number | null;
        draftVersion: number | null;
        preview: string;
    } | null;
    draft: { title: string; answer_summary: string | null; seo: Record<string, string | boolean>; blocks: B[] } | null;
    versions: { id: number; number: number; title: string; at: string; by: string; preview: string }[];
    blockTypes: Record<string, string>;
    media?: { url: string; alt: string }[];
    approved?: { id: number; label: string }[];
    sections?: { key: string; name: string; group: string; blocks: B[] }[];
    summary: { min: number; max: number };
    status?: string;
    seo: Seo;
};

/** Each block type's fields: [key, label, kind]; "list" fields edit several items with their own sub-fields. */
const FIELDS: Record<string, [string, string, string][]> = {
    hero: [
        ['heading', 'Heading', 'text'],
        ['subheading', 'Subheading', 'area'],
        ['cta_label', 'Button text', 'text'],
        ['cta_url', 'Button link', 'text'],
        ['image', 'Image address', 'text'],
        ['image_alt', 'Image description (alt text)', 'text'],
    ],
    features: [
        ['heading', 'Heading', 'text'],
        ['items', 'Features', 'list:title,text'],
    ],
    steps: [
        ['heading', 'Heading', 'text'],
        ['items', 'Steps', 'list:title,text'],
    ],
    stats: [['items', 'Stats', 'list:value,label']],
    testimonials: [['items', 'Quotes', 'list:quote,author,business']],
    faq: [
        ['heading', 'Heading', 'text'],
        ['items', 'Questions', 'list:q,a'],
    ],
    pricing: [
        ['heading', 'Heading', 'text'],
        ['plans', 'Plans (features one per line)', 'list:name,price,period,features,cta_label,cta_url'],
    ],
    cta: [
        ['heading', 'Heading', 'text'],
        ['text', 'Text', 'area'],
        ['label', 'Button text', 'text'],
        ['url', 'Button link', 'text'],
    ],
    rich_text: [['html', 'Text (headings, lists, links and emphasis are kept; anything else is removed)', 'area']],
    media: [
        ['image', 'Image address', 'text'],
        ['alt', 'Image description (alt text)', 'text'],
        ['caption', 'Caption', 'text'],
        ['video', 'Or a YouTube or Vimeo link', 'text'],
    ],
};
const LABEL: Record<string, string> = {
    q: 'Question',
    a: 'Answer',
    title: 'Title',
    text: 'Text',
    value: 'Value',
    label: 'Label',
    quote: 'Quote',
    author: 'Name',
    business: 'Business',
    name: 'Plan',
    price: 'Price',
    period: 'Per',
    features: 'Features',
    cta_label: 'Button text',
    cta_url: 'Button link',
};

const str = (v: unknown) => (typeof v === 'string' ? v : Array.isArray(v) ? v.join('\n') : '');

function BlockEditor({ b, set, approved = [] }: { b: B; set: (b: B) => void; approved?: { id: number; label: string }[] }) {
    return (
        <div className="space-y-2">
            {(FIELDS[b.type] ?? []).map(([key, label, kind]) => {
                if (kind.startsWith('list:')) {
                    const sub = kind.slice(5).split(',');
                    const list = (Array.isArray(b[key]) ? b[key] : []) as BlockItem[];
                    return (
                        <fieldset key={key} className="space-y-2 rounded-md border border-line p-3">
                            <legend className="px-1 text-sm font-medium">{label}</legend>
                            {list.map((item, i) => (
                                <div key={i} className="flex flex-wrap items-start gap-2">
                                    {b.type === 'testimonials' && (
                                        <select
                                            aria-label="Approved testimonial"
                                            className="h-9 rounded-md border border-line bg-surface px-2 text-sm"
                                            value={str(item.consent_id)}
                                            onChange={(e) =>
                                                set({ ...b, [key]: list.map((x, j) => (j === i ? { ...x, consent_id: e.target.value } : x)) })
                                            }
                                        >
                                            <option value="">Typed quote (no review markup)</option>
                                            {approved.map((t) => (
                                                <option key={t.id} value={String(t.id)}>
                                                    {t.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {sub.map((f) => (
                                        <textarea
                                            key={f}
                                            aria-label={LABEL[f] ?? f}
                                            placeholder={LABEL[f] ?? f}
                                            rows={f === 'a' || f === 'text' || f === 'quote' || f === 'features' ? 2 : 1}
                                            className="min-w-[8rem] flex-1 rounded-md border border-line bg-surface p-2 text-sm"
                                            value={str(item[f])}
                                            onChange={(e) => set({ ...b, [key]: list.map((x, j) => (j === i ? { ...x, [f]: e.target.value } : x)) })}
                                        />
                                    ))}
                                    <button
                                        type="button"
                                        aria-label="Remove item"
                                        className="mt-2 text-muted"
                                        onClick={() => set({ ...b, [key]: list.filter((_, j) => j !== i) })}
                                    >
                                        <Trash2 className="size-4" aria-hidden />
                                    </button>
                                </div>
                            ))}
                            <Button size="sm" variant="secondary" onClick={() => set({ ...b, [key]: [...list, {}] })}>
                                Add
                            </Button>
                        </fieldset>
                    );
                }
                return (
                    <Field key={key} label={label}>
                        {kind === 'area' ? (
                            <textarea
                                rows={key === 'html' ? 8 : 3}
                                className="w-full rounded-md border border-line bg-surface p-2 text-sm"
                                value={str(b[key])}
                                onChange={(e) => set({ ...b, [key]: e.target.value })}
                            />
                        ) : (
                            <Input
                                value={str(b[key])}
                                list={key === 'image' ? 'media-library' : undefined}
                                onChange={(e) => set({ ...b, [key]: e.target.value })}
                            />
                        )}
                    </Field>
                );
            })}
        </div>
    );
}

/** Admin → Website → a page: build it from blocks, preview, publish (now or later), and roll back. */
export default function ContentEdit({ page, draft, versions, blockTypes, media, approved, sections = [], summary, status, seo }: Props) {
    const f = useForm({
        title: draft?.title ?? '',
        slug: page?.slug ?? '',
        template: page?.template ?? 'landing',
        answer_summary: draft?.answer_summary ?? '',
        seo: {
            title: str(draft?.seo.title),
            description: str(draft?.seo.description),
            image: str(draft?.seo.image),
            noindex: Boolean(draft?.seo.noindex),
        },
        blocks: (draft?.blocks ?? []) as B[],
    });
    const [adding, setAdding] = useState('hero');
    const [section, setSection] = useState('');
    const [at, setAt] = useState('');
    const blocks = f.data.blocks;
    const move = (i: number, d: number) => {
        const next = [...blocks];
        const [x] = next.splice(i, 1);
        next.splice(i + d, 0, x!);
        f.setData('blocks', next);
    };
    const len = f.data.answer_summary.trim().length;
    const errors = f.errors as Record<string, string>;
    const act = (action: string, data: Record<string, string | number> = {}) =>
        page && router.post(`/admin/content/${page.id}/${action}`, data, { preserveScroll: true });
    return (
        <AdminLayout title={draft?.title || 'New page'}>
            <SeoHead seo={seo} />
            <div className="grid max-w-6xl gap-4 lg:grid-cols-[1fr_18rem]">
                <div className="space-y-4">
                    {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                    {Object.entries(errors)
                        .filter(([k]) => k.startsWith('blocks') || k === 'answer_summary')
                        .map(([k, m]) => (
                            <Alert key={k} tone="danger">
                                {m}
                            </Alert>
                        ))}
                    <Card className="grid gap-3 p-4 sm:grid-cols-2">
                        <Field label="Title" error={errors.title}>
                            <Input value={f.data.title} onChange={(e) => f.setData('title', e.target.value)} />
                        </Field>
                        <Field label="Address (after the domain)" error={errors.slug}>
                            <Input placeholder="how-it-works" value={f.data.slug} onChange={(e) => f.setData('slug', e.target.value)} />
                        </Field>
                        <Field label="Template">
                            <select
                                className="h-10 w-full rounded-md border border-line bg-surface px-2"
                                value={f.data.template}
                                onChange={(e) => f.setData('template', e.target.value)}
                            >
                                <option value="landing">Landing page</option>
                                <option value="page">Standard page</option>
                                <option value="legal">Legal page (shows its last-updated date)</option>
                            </select>
                        </Field>
                        <div className="sm:col-span-2">
                            <Field label={`Answer summary (${len} of ${summary.min}–${summary.max} characters, required to publish)`}>
                                <textarea
                                    rows={2}
                                    className="w-full rounded-md border border-line bg-surface p-2 text-sm"
                                    placeholder="Who it’s for, what it is, where, cost or how, in one or two sentences. Shown first on the page and quoted by answer engines."
                                    value={f.data.answer_summary}
                                    onChange={(e) => f.setData('answer_summary', e.target.value)}
                                />
                            </Field>
                        </div>
                    </Card>
                    {blocks.map((b, i) => (
                        <Card key={i} className="space-y-3 p-4">
                            <div className="flex items-center gap-2">
                                <Badge tone="info">{blockTypes[b.type]}</Badge>
                                <span className="ml-auto flex gap-1">
                                    <button
                                        type="button"
                                        aria-label="Move up"
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                        className="disabled:opacity-30"
                                    >
                                        <ArrowUp className="size-4" aria-hidden />
                                    </button>
                                    <button
                                        type="button"
                                        aria-label="Move down"
                                        disabled={i === blocks.length - 1}
                                        onClick={() => move(i, 1)}
                                        className="disabled:opacity-30"
                                    >
                                        <ArrowDown className="size-4" aria-hidden />
                                    </button>
                                    <button
                                        type="button"
                                        aria-label="Remove block"
                                        onClick={() =>
                                            f.setData(
                                                'blocks',
                                                blocks.filter((_, j) => j !== i),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" aria-hidden />
                                    </button>
                                </span>
                            </div>
                            <BlockEditor
                                b={b}
                                approved={approved}
                                set={(nb) =>
                                    f.setData(
                                        'blocks',
                                        blocks.map((x, j) => (j === i ? nb : x)),
                                    )
                                }
                            />
                        </Card>
                    ))}
                    <Card className="flex flex-wrap items-center gap-2 p-3">
                        <select
                            aria-label="Block type"
                            className="h-9 rounded-md border border-line bg-surface px-2 text-sm"
                            value={adding}
                            onChange={(e) => setAdding(e.target.value)}
                        >
                            {Object.entries(blockTypes).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                        <Button size="sm" variant="secondary" onClick={() => f.setData('blocks', [...blocks, { type: adding }])}>
                            Add block
                        </Button>
                        <span className="mx-2 text-muted">or</span>
                        <select
                            aria-label="Section"
                            className="h-9 rounded-md border border-line bg-surface px-2 text-sm"
                            value={section}
                            onChange={(e) => setSection(e.target.value)}
                        >
                            <option value="">Insert a ready-made section…</option>
                            {Array.from(new Set(sections.map((x) => x.group))).map((g) => (
                                <optgroup key={g} label={g}>
                                    {sections
                                        .filter((x) => x.group === g)
                                        .map((x) => (
                                            <option key={x.key} value={x.key}>
                                                {x.name}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="secondary"
                            disabled={!section}
                            onClick={() => {
                                const x = sections.find((y) => y.key === section);
                                if (x) f.setData('blocks', [...blocks, ...x.blocks]);
                                setSection('');
                            }}
                        >
                            Insert section
                        </Button>
                    </Card>
                    <Card className="grid gap-3 p-4 sm:grid-cols-2">
                        <h2 className="font-semibold sm:col-span-2">Search and sharing</h2>
                        <Field label="Search title (optional)">
                            <Input value={f.data.seo.title} onChange={(e) => f.setData('seo', { ...f.data.seo, title: e.target.value })} />
                        </Field>
                        <Field label="Search description (optional; the answer summary is used otherwise)">
                            <Input
                                value={f.data.seo.description}
                                onChange={(e) => f.setData('seo', { ...f.data.seo, description: e.target.value })}
                            />
                        </Field>
                        <Field label="Share image address (optional)">
                            <Input value={f.data.seo.image} onChange={(e) => f.setData('seo', { ...f.data.seo, image: e.target.value })} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={f.data.seo.noindex}
                                onChange={(e) => f.setData('seo', { ...f.data.seo, noindex: e.target.checked })}
                            />{' '}
                            Keep out of search engines
                        </label>
                    </Card>
                </div>
                {/* Images from the media library, offered in every image field. */}
                <datalist id="media-library">
                    {(media ?? []).map((m) => (
                        <option key={m.url} value={m.url}>
                            {m.alt}
                        </option>
                    ))}
                </datalist>
                <aside className="space-y-3">
                    <Card className="space-y-2 p-4">
                        <Button
                            className="w-full"
                            loading={f.processing}
                            onClick={() => f.post(page ? `/admin/content/${page.id}` : '/admin/content', { preserveScroll: true })}
                        >
                            Save draft
                        </Button>
                        {page && (
                            <>
                                <a
                                    href={page.preview}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="block text-center text-sm font-medium text-primary"
                                >
                                    Preview the draft
                                </a>
                                <p className="text-xs text-muted">
                                    Status: {page.status}
                                    {page.publishAt ? ` (goes live ${page.publishAt})` : ''}
                                </p>
                                <Button
                                    className="w-full"
                                    variant="secondary"
                                    onClick={() => window.confirm('Publish the saved draft now?') && act('publish')}
                                >
                                    Publish now
                                </Button>
                                <div className="flex gap-1">
                                    <Input
                                        type="datetime-local"
                                        aria-label="Publish at"
                                        className="h-9"
                                        value={at}
                                        onChange={(e) => setAt(e.target.value)}
                                    />
                                    <Button size="sm" variant="secondary" disabled={!at} onClick={() => act('publish', { at })}>
                                        Schedule
                                    </Button>
                                </div>
                                {page.status === 'published' && (
                                    <button
                                        className="w-full text-sm text-muted underline"
                                        onClick={() => window.confirm('Take this page offline?') && act('unpublish')}
                                    >
                                        Unpublish
                                    </button>
                                )}
                            </>
                        )}
                    </Card>
                    {versions.length > 0 && (
                        <Card className="space-y-2 p-4">
                            <h2 className="text-sm font-semibold">Versions</h2>
                            <ul className="space-y-1.5 text-xs">
                                {versions.map((v) => (
                                    <li key={v.id}>
                                        <span className="font-medium">v{v.number}</span> {v.at} {v.by && `· ${v.by}`}
                                        {page?.publishedVersion === v.id && (
                                            <Badge tone="success" className="ml-1">
                                                live
                                            </Badge>
                                        )}
                                        <span className="ml-1">
                                            <a href={v.preview} target="_blank" rel="noreferrer" className="text-primary">
                                                preview
                                            </a>
                                            {page?.publishedVersion !== v.id && (
                                                <button
                                                    className="ml-2 text-primary"
                                                    onClick={() =>
                                                        window.confirm(`Make version ${v.number} live?`) && act('rollback', { version: v.id })
                                                    }
                                                >
                                                    make this live
                                                </button>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    )}
                </aside>
            </div>
        </AdminLayout>
    );
}
