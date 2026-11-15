import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import { GrowthTabs } from '@/components/growth/GrowthTabs';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';
import { pct, STATUS_TONE } from './Campaigns';
import type { Stats } from './Campaigns';

type Step = {
    id: number;
    position: number;
    waitHours: number;
    subject: string;
    subjectB: string | null;
    winner: 'a' | 'b' | null;
    body: string;
    ctaLabel: string | null;
    ctaUrl: string | null;
};
type Props = {
    campaign: {
        id: string;
        name: string;
        kind: 'drip' | 'abandoned';
        status: 'draft' | 'active' | 'paused';
        audience: Partial<Record<'stage' | 'source' | 'industry' | 'state' | 'temp', string>>;
        goal: 'applied' | 'joined';
        windowStart: number;
        windowEnd: number;
        weekdaysOnly: boolean;
        dailyCap: number;
    };
    steps: Step[];
    stats: Stats;
    stepStats: { step: number; variant: string; subject: string; sent: number; opened: number; clicked: number }[];
    people: { id: string; name: string; status: string; reason: string | null; step: number; next: string | null }[];
    stages: Record<string, string>;
    sources: Record<string, string>;
    industries: { id: number; name: string }[];
    sending: boolean;
    status?: string;
    seo: Seo;
};

/** One campaign: who it's for, when it sends, its emails (with A/B subject lines), tests and results. */
export default function CampaignPage({ campaign: c, steps, stats, stepStats, people, stages, sources, industries, sending, status, seo }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const base = `/admin/growth/campaigns/${c.id}`;
    const s = useForm({
        name: c.name,
        goal: c.goal,
        window_start: c.windowStart,
        window_end: c.windowEnd,
        weekdays_only: c.weekdaysOnly,
        daily_cap: c.dailyCap,
        audience: { stage: '', source: '', industry: '', state: '', temp: '', ...c.audience },
    });
    const aud = (k: keyof typeof s.data.audience, v: string) => s.setData('audience', { ...s.data.audience, [k]: v });
    return (
        <AdminLayout title="Growth">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-5">
                <GrowthTabs current="/admin/growth/campaigns" />
                <Link href="/admin/growth/campaigns" className="text-sm text-primary">
                    ← All campaigns
                </Link>
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {Object.values(errors).map((e) => (
                    <Alert key={e} tone="danger">
                        {e}
                    </Alert>
                ))}
                {!sending && c.status === 'active' && (
                    <Alert tone="info">Prospect email is switched off: this campaign waits and sends nothing until it’s on.</Alert>
                )}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-xl font-semibold">
                        {c.name} <Badge tone={STATUS_TONE[c.status]}>{c.status}</Badge>
                    </h1>
                    <Button
                        variant={c.status === 'active' ? 'secondary' : 'primary'}
                        onClick={() =>
                            router.post(`${base}/status`, { status: c.status === 'active' ? 'paused' : 'active' }, { preserveScroll: true })
                        }
                    >
                        {c.status === 'active' ? 'Pause' : 'Start'}
                    </Button>
                </div>
                <Card className="grid gap-3 p-4 text-sm sm:grid-cols-4">
                    {[
                        ['People', `${stats.enrolled}`, `${stats.active} in progress`],
                        ['Clicked', pct(stats.clicked, stats.sent), `${stats.clicked} of ${stats.sent} sent`],
                        ['Replied', pct(stats.replied, stats.sent), `${stats.unsubscribed} unsubscribed, ${stats.bounced} bounced`],
                        [c.goal === 'applied' ? 'Applied' : 'Joined', `${stats.converted}`, `$${stats.revenue.toLocaleString()} paid since`],
                    ].map(([label, value, note]) => (
                        <div key={label}>
                            <p className="text-xs text-muted">{label}</p>
                            <p className="text-2xl font-semibold">{value}</p>
                            <p className="text-xs text-muted">{note}</p>
                        </div>
                    ))}
                </Card>
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Settings</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Name" error={s.errors.name}>
                            <Input value={s.data.name} onChange={(e) => s.setData('name', e.target.value)} />
                        </Field>
                        <Field label="Leaves the campaign once they’ve">
                            <Select value={s.data.goal} onChange={(e) => s.setData('goal', e.target.value as 'applied' | 'joined')}>
                                <option value="applied">Applied for a seat</option>
                                <option value="joined">Joined (hold or back up a seat)</option>
                            </Select>
                        </Field>
                        <Field label="Send between (their time)" error={s.errors.window_end}>
                            <div className="flex items-center gap-2">
                                <Input
                                    type="number"
                                    min={0}
                                    max={23}
                                    value={s.data.window_start}
                                    onChange={(e) => s.setData('window_start', Number(e.target.value))}
                                />
                                <span>and</span>
                                <Input
                                    type="number"
                                    min={1}
                                    max={24}
                                    value={s.data.window_end}
                                    onChange={(e) => s.setData('window_end', Number(e.target.value))}
                                />
                            </div>
                        </Field>
                        <Field label="Most emails a day" error={s.errors.daily_cap}>
                            <Input type="number" min={1} value={s.data.daily_cap} onChange={(e) => s.setData('daily_cap', Number(e.target.value))} />
                        </Field>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4"
                            checked={s.data.weekdays_only}
                            onChange={(e) => s.setData('weekdays_only', e.target.checked)}
                        />{' '}
                        Weekdays only
                    </label>
                    {c.kind === 'drip' ? (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                Add automatically: prospects who match (leave all empty to add people by hand from the prospect list)
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-5">
                                <Select aria-label="Stage" value={s.data.audience.stage} onChange={(e) => aud('stage', e.target.value)}>
                                    <option value="">Any stage</option>
                                    {Object.entries(stages).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                                <Select aria-label="Source" value={s.data.audience.source} onChange={(e) => aud('source', e.target.value)}>
                                    <option value="">Any source</option>
                                    {Object.entries(sources).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                                <Select aria-label="Industry" value={s.data.audience.industry} onChange={(e) => aud('industry', e.target.value)}>
                                    <option value="">Any industry</option>
                                    {industries.map((i) => (
                                        <option key={i.id} value={String(i.id)}>
                                            {i.name}
                                        </option>
                                    ))}
                                </Select>
                                <Input
                                    aria-label="State"
                                    placeholder="State, e.g. TX"
                                    maxLength={2}
                                    value={s.data.audience.state}
                                    onChange={(e) => aud('state', e.target.value.toUpperCase())}
                                />
                                <Select aria-label="Lead score" value={s.data.audience.temp} onChange={(e) => aud('temp', e.target.value)}>
                                    <option value="">Any score</option>
                                    <option value="hot">Hot</option>
                                    <option value="warm">Warm</option>
                                    <option value="cold">Cold</option>
                                </Select>
                            </div>
                        </fieldset>
                    ) : (
                        <p className="text-sm text-muted">
                            Ready-made: everyone who signs up and doesn’t apply for a seat gets these emails, starting an hour later.
                        </p>
                    )}
                    <Button loading={s.processing} onClick={() => s.patch(base, { preserveScroll: true })}>
                        Save settings
                    </Button>
                </Card>
                {steps.map((step) => (
                    <StepCard key={step.id} step={step} base={base} stats={stepStats.filter((x) => x.step === step.position)} />
                ))}
                <NewStep base={base} next={steps.length + 1} />
                <Card className="space-y-2 p-5 text-sm">
                    <h2 className="font-semibold">People (latest 50)</h2>
                    <ul className="divide-y divide-line">
                        {people.map((p) => (
                            <li key={p.id} className="flex flex-wrap justify-between gap-2 py-1.5">
                                <Link href={`/admin/growth/prospects/${p.id}`} className="text-primary">
                                    {p.name}
                                </Link>
                                <span className="text-muted">
                                    {p.status}
                                    {p.reason ? ` (${p.reason})` : ''}
                                    {p.status === 'active' && p.next ? ` · email ${p.step} ${new Date(p.next).toLocaleString()}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {people.length === 0 && <p className="text-muted">Nobody yet. Add prospects from the prospect list, or set an audience above.</p>}
                </Card>
            </div>
        </AdminLayout>
    );
}

function StepCard({ step, base, stats }: { step: Step; base: string; stats: Props['stepStats'] }) {
    const f = useForm({
        wait_hours: step.waitHours,
        subject: step.subject,
        subject_b: step.subjectB ?? '',
        body: step.body,
        cta_label: step.ctaLabel ?? '',
        cta_url: step.ctaUrl ?? '',
    });
    const [preview, setPreview] = useState(false);
    return (
        <Card className="space-y-3 p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold">Email {step.position}</h2>
                <div className="flex gap-1">
                    <Button size="sm" variant="ghost" onClick={() => setPreview(!preview)}>
                        {preview ? 'Hide preview' : 'Preview'}
                    </Button>
                    <Button size="sm" variant="secondary" onClick={() => router.post(`${base}/steps/${step.id}/test`, {}, { preserveScroll: true })}>
                        <Send /> Send me a test
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => window.confirm('Delete this email?') && router.delete(`${base}/steps/${step.id}`, { preserveScroll: true })}
                    >
                        Delete
                    </Button>
                </div>
            </div>
            {stats.length > 0 && (
                <div className="flex flex-wrap gap-4 text-xs">
                    {stats.map((x) => (
                        <span key={x.variant}>
                            {stats.length > 1 ? `${x.variant.toUpperCase()}${step.winner === x.variant ? ' (winner)' : ''}: ` : ''}
                            {x.sent} sent · {pct(x.clicked, x.sent)} clicked
                        </span>
                    ))}
                </div>
            )}
            {preview && (
                <iframe
                    title={`Preview of email ${step.position}`}
                    src={`${base}/steps/${step.id}/preview`}
                    className="h-96 w-full rounded-md border border-line bg-white"
                />
            )}
            <StepFields f={f} first={step.position === 1} />
            <Button size="sm" loading={f.processing} onClick={() => f.patch(`${base}/steps/${step.id}`, { preserveScroll: true })}>
                Save email
            </Button>
        </Card>
    );
}

function NewStep({ base, next }: { base: string; next: number }) {
    const [open, setOpen] = useState(false);
    const f = useForm({ wait_hours: 72, subject: '', subject_b: '', body: 'Hi {first_name},\n\n', cta_label: '', cta_url: '' });
    return open ? (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">Email {next}</h2>
            <StepFields f={f} first={next === 1} />
            <Button
                size="sm"
                loading={f.processing}
                onClick={() => f.post(`${base}/steps`, { preserveScroll: true, onSuccess: () => setOpen(false) })}
            >
                Add email
            </Button>
        </Card>
    ) : (
        <Button variant="secondary" onClick={() => setOpen(true)}>
            Add another email
        </Button>
    );
}

type StepForm = ReturnType<
    typeof useForm<{ wait_hours: number; subject: string; subject_b: string; body: string; cta_label: string; cta_url: string }>
>;

function StepFields({ f, first }: { f: StepForm; first: boolean }) {
    return (
        <div className="grid gap-3">
            <Field label={first ? 'Send after enrolling (hours)' : 'Send after the previous email (hours)'} error={f.errors.wait_hours}>
                <Input
                    type="number"
                    min={0}
                    className="w-32"
                    value={f.data.wait_hours}
                    onChange={(e) => f.setData('wait_hours', Number(e.target.value))}
                />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Subject" hint="{first_name} and {city} are filled in." error={f.errors.subject}>
                    <Input value={f.data.subject} maxLength={150} onChange={(e) => f.setData('subject', e.target.value)} />
                </Field>
                <Field
                    label="Second subject to test (optional)"
                    hint="Half get each; after 50 sends each, the one with more clicks wins."
                    error={f.errors.subject_b}
                >
                    <Input value={f.data.subject_b} maxLength={150} onChange={(e) => f.setData('subject_b', e.target.value)} />
                </Field>
            </div>
            <Field label="Message" hint="Blank lines start a new paragraph. The postal address and unsubscribe link are added." error={f.errors.body}>
                <Textarea rows={7} value={f.data.body} onChange={(e) => f.setData('body', e.target.value)} />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Button text" error={f.errors.cta_label}>
                    <Input value={f.data.cta_label} maxLength={60} onChange={(e) => f.setData('cta_label', e.target.value)} />
                </Field>
                <Field label="Button link" hint="A page here (/groups) or a full https:// address." error={f.errors.cta_url}>
                    <Input value={f.data.cta_url} onChange={(e) => f.setData('cta_url', e.target.value)} />
                </Field>
            </div>
        </div>
    );
}
