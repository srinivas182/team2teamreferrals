import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo as SeoMeta } from '@/types/seo';

type Summary = {
    pages: number;
    failed: number;
    broken_links?: number;
    duplicate_titles?: number;
    duplicate_descriptions?: number;
    near_duplicates?: number;
};
type Audit = Summary & {
    at: string;
    results: { path: string; type: string; problems: string[] }[];
    broken?: Record<string, { status: number; from: string[] }>;
    duplicates?: { titles: Record<string, string[]>; descriptions: Record<string, string[]>; near: { a: string; b: string; similarity: number }[] };
};
type Engine = { key: string; name: string; hint: string; defaultModel: string; enabled: boolean; model: string; saved: string | null };
type Props = {
    crawlers: { bot: string; purpose: string; allowed: boolean }[];
    indexable: boolean;
    audit: Audit | null;
    history: (Summary & { at: string; trigger: string })[];
    searchConsole: {
        enabled: boolean;
        site: string;
        credentials: string | null;
        last: { ok: boolean; message: string; at: string } | null;
        indexNow: boolean;
    };
    engines: Engine[];
    maxQuestions: number;
    questions: { id: number; question: string; source: string; active: boolean }[];
    visibility: {
        months: string[];
        engines: { engine: string; name: string; months: Record<string, { asked: number; platform: number; member: number }> }[];
        latest: {
            question: string;
            engine: string;
            month: string;
            platform: boolean;
            member: boolean;
            citations: string[];
            excerpt: string | null;
            error: string | null;
        }[];
    };
    aiVisits: {
        crawlers: { source: string; visits: number; search: boolean }[];
        referrals: { source: string; visits: number }[];
        pages: { path: string; crawls: number; referrals: number }[];
    };
    gone: { path: string; reason: string; gone_at: string }[];
    status?: string;
    seo: SeoMeta;
};

const TABS = [
    ['health', 'Health'],
    ['answers', 'Answer engines'],
    ['ai', 'AI traffic'],
    ['google', 'Search Console'],
    ['crawlers', 'AI crawlers'],
] as const;
const pct = (n: number, of: number) => (of > 0 ? `${Math.round((n / of) * 100)}%` : '–');

function Table({ head, rows, empty }: { head: string[]; rows: (string | number)[][]; empty: string }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[420px] text-sm">
                <thead className="text-left text-muted">
                    <tr>
                        {head.map((h) => (
                            <th key={h} scope="col" className="py-2 pr-4 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={head.length} className="py-3 text-muted">
                                {empty}
                            </td>
                        </tr>
                    )}
                    {rows.map((r, i) => (
                        <tr key={i} className="border-t border-line">
                            {r.map((c, j) => (
                                <td key={j} className="py-2 pr-4 tabular">
                                    {c}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Super admin → SEO and AEO: the health report, answer-engine visibility, AI traffic, Search Console and AI crawler rules. */
export default function Seo(p: Props) {
    const [tab, setTab] = useState<(typeof TABS)[number][0]>('health');
    return (
        <AdminLayout title="SEO and AEO">
            <SeoHead seo={p.seo} />
            <div className="max-w-5xl space-y-6">
                {statusMessage(p.status) && <Alert tone={p.status === 'sitemap-failed' ? 'danger' : 'success'}>{statusMessage(p.status)}</Alert>}
                {!p.indexable && (
                    <Alert tone="info">
                        This site is not open to search engines yet (pre-launch): robots.txt blocks every crawler and the nightly check is paused.
                    </Alert>
                )}
                <nav className="flex gap-1 overflow-x-auto border-b border-line" aria-label="SEO sections">
                    {TABS.map(([k, label]) => (
                        <button
                            key={k}
                            type="button"
                            aria-current={tab === k ? 'page' : undefined}
                            onClick={() => setTab(k)}
                            className={`-mb-px border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap ${tab === k ? 'border-primary text-ink' : 'border-transparent text-muted hover:text-ink'}`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
                {tab === 'health' && <Health {...p} />}
                {tab === 'answers' && <Answers {...p} />}
                {tab === 'ai' && <AiTraffic {...p} />}
                {tab === 'google' && <Google {...p} />}
                {tab === 'crawlers' && <Crawlers {...p} />}
            </div>
        </AdminLayout>
    );
}

function Health({ audit, history, gone }: Props) {
    const failing = audit?.results.filter((r) => r.problems.length > 0) ?? [];
    const dupes = audit?.duplicates;
    return (
        <>
            <Card className="space-y-3 p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-semibold">Latest check</h2>
                        <p className="text-sm text-muted">Every public page, its links and duplicates, checked every night and on every release.</p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => router.post('/admin/seo/check', {}, { preserveScroll: true })}>
                        Run the check now
                    </Button>
                </div>
                {audit === null ? (
                    <p className="text-sm text-muted">No check has run on this server yet.</p>
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                            {[
                                ['Pages checked', audit.pages],
                                ['With problems', audit.failed],
                                ['Broken links', audit.broken_links ?? '–'],
                                ['Duplicate titles or descriptions', (audit.duplicate_titles ?? 0) + (audit.duplicate_descriptions ?? 0)],
                                ['Near-duplicate pages', audit.near_duplicates ?? '–'],
                            ].map(([label, n]) => (
                                <div key={label as string} className="rounded-md border border-line p-3">
                                    <div className="text-2xl font-bold tabular">{n}</div>
                                    <div className="text-xs text-muted">{label}</div>
                                </div>
                            ))}
                        </div>
                        <p className="text-xs text-muted">Checked {new Date(audit.at).toLocaleString()}</p>
                        {failing.length > 0 && (
                            <ul className="space-y-2 text-sm">
                                {failing.map((r) => (
                                    <li key={r.path}>
                                        <a href={r.path} target="_blank" rel="noopener" className="font-medium text-primary">
                                            {r.path}
                                        </a>
                                        <ul className="ml-4 list-disc text-muted">
                                            {r.problems.map((x) => (
                                                <li key={x}>{x}</li>
                                            ))}
                                        </ul>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {audit.broken && Object.keys(audit.broken).length > 0 && (
                            <div className="text-sm">
                                <h3 className="font-semibold">Broken links</h3>
                                <ul className="mt-1 space-y-1">
                                    {Object.entries(audit.broken).map(([to, b]) => (
                                        <li key={to}>
                                            {to} <Badge tone="danger">HTTP {b.status}</Badge>{' '}
                                            <span className="text-muted">linked from {b.from.join(', ')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {dupes && (Object.keys(dupes.titles).length > 0 || Object.keys(dupes.descriptions).length > 0 || dupes.near.length > 0) && (
                            <div className="text-sm">
                                <h3 className="font-semibold">Duplicates</h3>
                                <ul className="mt-1 space-y-1">
                                    {Object.entries(dupes.titles).map(([t, ps]) => (
                                        <li key={`t-${t}`}>
                                            Same title on {ps.join(', ')}: <span className="text-muted">{t}</span>
                                        </li>
                                    ))}
                                    {Object.entries(dupes.descriptions).map(([t, ps]) => (
                                        <li key={`d-${t}`}>Same description on {ps.join(', ')}</li>
                                    ))}
                                    {dupes.near.map((n) => (
                                        <li key={`${n.a}-${n.b}`} className="text-muted">
                                            Nearly identical wording ({Math.round(n.similarity * 100)}%): {n.a} and {n.b}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">History</h2>
                <Table
                    head={['When', 'Run by', 'Pages', 'Problems', 'Broken links', 'Duplicates']}
                    empty="No checks yet."
                    rows={history.map((h) => [
                        new Date(h.at).toLocaleString(),
                        h.trigger,
                        h.pages,
                        h.failed,
                        h.broken_links ?? '–',
                        (h.duplicate_titles ?? 0) + (h.duplicate_descriptions ?? 0),
                    ])}
                />
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">Removed pages</h2>
                <p className="text-sm text-muted">
                    These answer “gone” (410), so search engines drop them quickly. A page that comes back leaves this list.
                </p>
                <Table
                    head={['Address', 'Why', 'When']}
                    empty="No pages removed."
                    rows={gone.map((g) => [g.path, g.reason, new Date(g.gone_at).toLocaleDateString()])}
                />
            </Card>
        </>
    );
}

function Answers({ engines, maxQuestions, questions, visibility }: Props) {
    const f = useForm<{ engines: Record<string, { key: string; enabled: boolean; model: string }>; max_questions: number }>({
        engines: Object.fromEntries(engines.map((e) => [e.key, { key: '', enabled: e.enabled, model: e.model }])),
        max_questions: maxQuestions,
    });
    const q = useForm({ question: '' });
    const errors = f.errors as Record<string, string>;
    const on = engines.some((e) => e.enabled);
    return (
        <>
            <Card className="space-y-4 p-6">
                <div>
                    <h2 className="font-semibold">Answer engines</h2>
                    <p className="text-sm text-muted">
                        Once a month each question below is asked of every engine that is on, with web search, and we record whether the answer cites
                        this site or a member. Each engine needs its own API key and is billed by that provider.
                    </p>
                </div>
                {engines.map((e) => (
                    <div key={e.key} className="grid gap-3 border-t border-line pt-4 sm:grid-cols-[1fr_1fr_160px]">
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1 size-4"
                                checked={f.data.engines[e.key]?.enabled ?? false}
                                onChange={(ev) =>
                                    f.setData('engines', { ...f.data.engines, [e.key]: { ...f.data.engines[e.key]!, enabled: ev.target.checked } })
                                }
                            />
                            <span>
                                <span className="font-medium">{e.name}</span>
                                <span className="block text-muted">{e.hint}</span>
                            </span>
                        </label>
                        <Field label={e.saved ? `API key (saved: ${e.saved})` : 'API key'} error={errors[`engines.${e.key}.key`]}>
                            <Input
                                type="password"
                                autoComplete="off"
                                placeholder={e.saved ? 'Leave blank to keep' : ''}
                                value={f.data.engines[e.key]?.key ?? ''}
                                onChange={(ev) =>
                                    f.setData('engines', { ...f.data.engines, [e.key]: { ...f.data.engines[e.key]!, key: ev.target.value } })
                                }
                            />
                        </Field>
                        {e.defaultModel !== '' ? (
                            <Field label="Model" error={errors[`engines.${e.key}.model`]}>
                                <Input
                                    placeholder={e.defaultModel}
                                    value={f.data.engines[e.key]?.model ?? ''}
                                    onChange={(ev) =>
                                        f.setData('engines', { ...f.data.engines, [e.key]: { ...f.data.engines[e.key]!, model: ev.target.value } })
                                    }
                                />
                            </Field>
                        ) : (
                            <div />
                        )}
                    </div>
                ))}
                <div className="flex flex-wrap items-end gap-3 border-t border-line pt-4">
                    <Field label="Questions per month (cost cap)" error={f.errors.max_questions}>
                        <Input
                            type="number"
                            min={1}
                            max={500}
                            value={f.data.max_questions}
                            onChange={(e) => f.setData('max_questions', Number(e.target.value))}
                        />
                    </Field>
                    <Button loading={f.processing} onClick={() => f.post('/admin/seo/engines', { preserveScroll: true, onSuccess: () => f.reset() })}>
                        Save
                    </Button>
                    {on && (
                        <Button variant="secondary" onClick={() => router.post('/admin/seo/visibility', {}, { preserveScroll: true })}>
                            Run this month’s check now
                        </Button>
                    )}
                </div>
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">How often we’re cited</h2>
                <p className="text-sm text-muted">
                    Share of answers that cite this site (and, in brackets, a member’s website or profile), by month.
                </p>
                <Table
                    head={['Engine', ...visibility.months]}
                    empty="No engines."
                    rows={visibility.engines.map((e) => [
                        e.name,
                        ...visibility.months.map((m) => {
                            const r = e.months[m];
                            return r ? `${pct(r.platform, r.asked)} (${pct(r.member, r.asked)}) of ${r.asked}` : '–';
                        }),
                    ])}
                />
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">Questions</h2>
                <p className="text-sm text-muted">
                    Made automatically from published industries and the cities where they have members; add your own too. Your own questions are
                    asked first.
                </p>
                <div className="flex flex-wrap items-end gap-2">
                    <div className="min-w-0 flex-1">
                        <Field label="Add a question" error={q.errors.question}>
                            <Input
                                value={q.data.question}
                                placeholder="e.g. What’s the best way to find a trusted roofer in Tampa?"
                                onChange={(e) => q.setData('question', e.target.value)}
                            />
                        </Field>
                    </div>
                    <Button
                        variant="secondary"
                        loading={q.processing}
                        onClick={() => q.post('/admin/seo/questions', { preserveScroll: true, onSuccess: () => q.reset() })}
                    >
                        Add
                    </Button>
                </div>
                <ul className="divide-y divide-line text-sm">
                    {questions.length === 0 && <li className="py-2 text-muted">None yet: they are made when the first check runs.</li>}
                    {questions.map((x) => (
                        <li key={x.id} className="flex items-center justify-between gap-3 py-2">
                            <span className={x.active ? '' : 'text-muted line-through'}>
                                {x.question} {x.source === 'admin' && <Badge tone="info">yours</Badge>}
                            </span>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post(`/admin/seo/questions/${x.id}/toggle`, {}, { preserveScroll: true })}
                            >
                                {x.active ? 'Pause' : 'Ask again'}
                            </Button>
                        </li>
                    ))}
                </ul>
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">Latest answers</h2>
                <ul className="space-y-3 text-sm">
                    {visibility.latest.length === 0 && <li className="text-muted">No answers yet.</li>}
                    {visibility.latest.map((a, i) => (
                        <li key={i} className="border-t border-line pt-3">
                            <p className="font-medium">{a.question}</p>
                            <p className="text-muted">
                                {engines.find((e) => e.key === a.engine)?.name ?? a.engine} · {a.month} ·{' '}
                                {a.error ? (
                                    <span className="text-danger">failed: {a.error}</span>
                                ) : (
                                    <>
                                        {a.platform ? <Badge tone="success">cites us</Badge> : <Badge>not cited</Badge>}{' '}
                                        {a.member && <Badge tone="success">cites a member</Badge>}
                                    </>
                                )}
                            </p>
                            {a.excerpt && <p className="mt-1 line-clamp-3 text-muted">{a.excerpt}</p>}
                            {a.citations.length > 0 && <p className="mt-1 text-xs break-all text-muted">Sources: {a.citations.join(' · ')}</p>}
                        </li>
                    ))}
                </ul>
            </Card>
        </>
    );
}

function AiTraffic({ aiVisits }: Props) {
    return (
        <>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">People arriving from AI answers (last 30 days)</h2>
                <Table head={['Assistant', 'Visits']} empty="None yet." rows={aiVisits.referrals.map((r) => [r.source, r.visits])} />
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">Crawler visits (last 30 days)</h2>
                <p className="text-sm text-muted">
                    Pages served straight from the CDN cache aren’t counted here; arrivals from AI answers above are.
                </p>
                <Table
                    head={['Crawler', 'Kind', 'Pages read']}
                    empty="None yet."
                    rows={aiVisits.crawlers.map((c) => [c.source, c.search ? 'Search engine' : 'AI', c.visits])}
                />
            </Card>
            <Card className="space-y-3 p-6">
                <h2 className="font-semibold">Pages AI reads and sends people to</h2>
                <Table
                    head={['Page', 'AI crawler visits', 'Arrivals from AI answers']}
                    empty="None yet."
                    rows={aiVisits.pages.map((r) => [r.path, r.crawls, r.referrals])}
                />
            </Card>
        </>
    );
}

function Google({ searchConsole: g }: Props) {
    const f = useForm({ enabled: g.enabled, site: g.site, credentials: '' });
    return (
        <Card className="space-y-4 p-6">
            <div>
                <h2 className="font-semibold">Google Search Console</h2>
                <p className="text-sm text-muted">
                    When on, the sitemap is submitted to Google every night and within ten minutes of pages changing (at most hourly). Bing and others
                    are told through IndexNow ({g.indexNow ? 'on' : 'off'}). Until this is set up, Google still finds the sitemap through robots.txt.
                </p>
            </div>
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="size-4" checked={f.data.enabled} onChange={(e) => f.setData('enabled', e.target.checked)} /> Submit
                to Search Console
            </label>
            <Field
                label="Property"
                hint='Exactly as Search Console shows it: "sc-domain:teamtoteamreferrals.com" or "https://teamtoteamreferrals.com/".'
                error={f.errors.site}
            >
                <Input value={f.data.site} onChange={(e) => f.setData('site', e.target.value)} />
            </Field>
            <Field
                label={g.credentials ? `Service account key (saved: ${g.credentials})` : 'Service account key (JSON)'}
                hint="Create a service account in Google Cloud, add its email as an Owner of the property in Search Console, then paste its JSON key here."
                error={f.errors.credentials}
            >
                <Textarea
                    rows={4}
                    placeholder={g.credentials ? 'Leave blank to keep the saved key' : '{ "type": "service_account", … }'}
                    value={f.data.credentials}
                    onChange={(e) => f.setData('credentials', e.target.value)}
                />
            </Field>
            <div className="flex flex-wrap gap-3">
                <Button
                    loading={f.processing}
                    onClick={() => f.post('/admin/seo/search-console', { preserveScroll: true, onSuccess: () => f.setData('credentials', '') })}
                >
                    Save
                </Button>
                {g.enabled && (
                    <Button variant="secondary" onClick={() => router.post('/admin/seo/search-console/submit', {}, { preserveScroll: true })}>
                        Submit now
                    </Button>
                )}
            </div>
            {g.last && (
                <p className={`text-sm ${g.last.ok ? 'text-success' : 'text-danger'}`}>
                    {new Date(g.last.at).toLocaleString()}: {g.last.message}
                </p>
            )}
        </Card>
    );
}

function Crawlers({ crawlers }: Props) {
    const f = useForm<{ allowed: string[] }>({ allowed: crawlers.filter((c) => c.allowed).map((c) => c.bot) });
    const toggle = (bot: string) =>
        f.setData('allowed', f.data.allowed.includes(bot) ? f.data.allowed.filter((b) => b !== bot) : [...f.data.allowed, bot]);
    return (
        <Card className="space-y-4 p-6">
            <div>
                <h2 className="font-semibold">AI crawlers</h2>
                <p className="text-sm text-muted">
                    Allowed crawlers can read public pages so AI assistants can quote and link to them. Blocking one only stops future visits; portal,
                    admin and client pages are always blocked.
                </p>
            </div>
            <ul className="divide-y divide-line">
                {crawlers.map((c) => (
                    <li key={c.bot} className="flex items-start gap-3 py-2">
                        <input
                            id={`bot-${c.bot}`}
                            type="checkbox"
                            className="mt-1 size-4"
                            checked={f.data.allowed.includes(c.bot)}
                            onChange={() => toggle(c.bot)}
                        />
                        <label htmlFor={`bot-${c.bot}`} className="text-sm">
                            <span className="font-medium">{c.bot}</span>
                            <span className="block text-muted">{c.purpose}</span>
                        </label>
                    </li>
                ))}
            </ul>
            <div className="flex flex-wrap items-center gap-3">
                <Button loading={f.processing} onClick={() => f.post('/admin/seo/crawlers', { preserveScroll: true })}>
                    Save crawler rules
                </Button>
                <a href="/robots.txt" target="_blank" rel="noopener" className="text-sm font-medium text-primary">
                    View robots.txt
                </a>
            </div>
        </Card>
    );
}
