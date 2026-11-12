import { Link, useForm } from '@inertiajs/react';
import {
    Mail,
    MessageSquare,
    Building2,
    Send,
    Gauge,
    CreditCard,
    HardDrive,
    Sparkles,
    ToggleRight,
    Globe,
    Download,
    Upload,
    Wrench,
    CheckCircle2,
    XCircle,
    MinusCircle,
    Clapperboard,
    BarChart3,
} from 'lucide-react';
import { router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { HealthBadge, type Health } from '@/components/admin/HealthBadge';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type FieldDef = { name: string; label: string; secret: boolean; required: boolean; hint: string | null; value: string | null; saved: string | null };
type Driver = { key: string; label: string; fields: FieldDef[] };
type Channel = { driver: string; drivers: Driver[]; health: Health };
type ProviderData = { driver: string; fields: Record<string, string>; from_address: string; from_name: string };

type Check = { ok: boolean | null; message: string; at: string };

type Props = {
    tab: (typeof tabs)[number][0];
    general: { company_name: string | null; support_email: string | null; support_phone: string | null };
    email: Channel & { from_address: string; from_name: string };
    sms: Channel;
    payments: Channel;
    storage: Channel;
    cdn: Channel;
    ai: Channel & { spent: number };
    aiFallback: Channel;
    video: Channel;
    analytics: Channel & {
        health: { ok: boolean; detail: string };
        sources: Record<string, { shipped: number; pending: number; shippedAt: string | null }>;
    };
    messagingSpent: number;
    modules: { key: string; label: string; affects: string; enabled: boolean; available: boolean }[];
    overview: { key: string; label: string; driver: string | null; last: Check | null; health: Health | null }[];
    maintenance: boolean;
    checks: Record<string, Check> | null;
    status?: string;
    seo: Seo;
};

const tabs = [
    ['overview', 'Overview', Gauge],
    ['general', 'General', Building2],
    ['email', 'Email', Mail],
    ['sms', 'Text messages', MessageSquare],
    ['payments', 'Payments', CreditCard],
    ['storage', 'Storage', HardDrive],
    ['cdn', 'CDN', Globe],
    ['ai', 'AI', Sparkles],
    ['video', 'Video', Clapperboard],
    ['analytics', 'Analytics', BarChart3],
    ['modules', 'Modules', ToggleRight],
] as const;

function CheckLine({ check }: { check: Check | null | undefined }) {
    if (!check) return <p className="text-sm text-muted">Not checked yet.</p>;
    const Icon = check.ok === true ? CheckCircle2 : check.ok === false ? XCircle : MinusCircle;
    return (
        <p
            className={cn(
                'flex gap-2 text-sm',
                check.ok === true ? 'text-success' : check.ok === false ? 'text-danger dark:text-[#FDA4AF]' : 'text-muted',
            )}
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>{check.message}</span>
        </p>
    );
}

function ProviderTab({
    title,
    intro,
    channel,
    url,
    provider,
    checks,
    extra,
}: {
    title: string;
    intro: string;
    channel: Channel;
    url: string;
    provider: string;
    checks: Record<string, Check> | null;
    extra?: ReactNode;
}) {
    return (
        <div className="grid max-w-5xl gap-6 lg:grid-cols-[1fr_340px]">
            <Card className="p-6">
                <h2 className="text-lg font-semibold">{title}</h2>
                <p className="mt-1 mb-5 text-sm text-muted">{intro}</p>
                <ProviderForm channel={channel} url={url} />
            </Card>
            <div className="space-y-6">
                <Card className="space-y-3 p-5">
                    <h3 className="font-semibold">Connection</h3>
                    <CheckLine check={checks?.[provider]} />
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={() => router.post(`/admin/system/check/${provider}`, {}, { preserveScroll: true })}
                    >
                        Test connection
                    </Button>
                </Card>
                {extra}
            </div>
        </div>
    );
}

function initialFields(channel: Channel) {
    const out: Record<string, Record<string, string>> = {};
    for (const d of channel.drivers) out[d.key] = Object.fromEntries(d.fields.map((f) => [f.name, f.value ?? '']));
    return out;
}

function ProviderForm({
    channel,
    url,
    extra,
    children,
}: {
    channel: Channel;
    url: string;
    extra?: Record<string, string>;
    children?: (form: { data: Record<string, string>; set: (k: string, v: string) => void; errors: Record<string, string> }) => ReactNode;
}) {
    const all = initialFields(channel);
    const form = useForm<ProviderData>({
        driver: channel.driver,
        fields: all[channel.driver] ?? {},
        from_address: extra?.from_address ?? '',
        from_name: extra?.from_name ?? '',
    });
    const driver = channel.drivers.find((d) => d.key === form.data.driver);
    const errors = form.errors as Record<string, string>;
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(url, { preserveScroll: true });
    };
    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            {children?.({
                data: { from_address: form.data.from_address, from_name: form.data.from_name },
                set: (k, v) => form.setData(k as 'from_address' | 'from_name', v),
                errors,
            })}
            <Field label="Provider">
                <Select
                    value={form.data.driver}
                    onChange={(e) => form.setData({ ...form.data, driver: e.target.value, fields: all[e.target.value] ?? {} })}
                >
                    {channel.drivers.map((d) => (
                        <option key={d.key} value={d.key}>
                            {d.label}
                        </option>
                    ))}
                </Select>
            </Field>
            {driver?.fields.map((f) => (
                <Field
                    key={f.name}
                    label={f.label + (f.required ? '' : ' (optional)')}
                    hint={f.secret && f.saved ? `Saved: ${f.saved}. Leave blank to keep it.` : (f.hint ?? undefined)}
                    error={errors[`fields.${f.name}`]}
                >
                    <Input
                        type={f.secret ? 'password' : 'text'}
                        autoComplete="off"
                        value={form.data.fields[f.name] ?? ''}
                        onChange={(e) => form.setData('fields', { ...form.data.fields, [f.name]: e.target.value })}
                    />
                </Field>
            ))}
            <Button type="submit" loading={form.processing}>
                Save
            </Button>
        </form>
    );
}

function TestSend({ url, label, type, placeholder }: { url: string; label: string; type: string; placeholder: string }) {
    const form = useForm({ to: '' });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(url, { preserveScroll: true });
            }}
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
        >
            <Field label={label} error={form.errors.to} className="flex-1">
                <Input type={type} placeholder={placeholder} value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} />
            </Field>
            <Button type="submit" variant="secondary" loading={form.processing}>
                <Send /> Send test
            </Button>
        </form>
    );
}

export default function SystemSettings({
    tab,
    general,
    email,
    sms,
    payments,
    storage,
    cdn,
    ai,
    aiFallback,
    video,
    analytics,
    messagingSpent,
    modules,
    overview,
    maintenance,
    checks,
    status,
    seo,
}: Props) {
    const mods = useForm({ modules: Object.fromEntries(modules.map((m) => [m.key, m.enabled])) as Record<string, boolean> });
    const gen = useForm({
        company_name: general.company_name ?? '',
        support_email: general.support_email ?? '',
        support_phone: general.support_phone ?? '',
    });
    return (
        <AdminLayout title="System settings">
            <SeoHead seo={seo} />
            <nav className="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Settings sections">
                {tabs.map(([key, label, Icon]) => (
                    <Link
                        key={key}
                        href={`/admin/system/${key}`}
                        preserveScroll
                        aria-current={tab === key ? 'page' : undefined}
                        className={cn(
                            '-mb-px flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap',
                            tab === key ? 'border-primary text-ink' : 'border-transparent text-muted hover:text-ink',
                        )}
                    >
                        <Icon className="size-4" aria-hidden /> {label}
                    </Link>
                ))}
            </nav>
            {status && (
                <Alert tone="success" className="mb-5 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}

            {tab === 'general' && (
                <Card className="max-w-3xl p-6">
                    <h2 className="text-lg font-semibold">Company details</h2>
                    <p className="mt-1 text-sm text-muted">Shown on invoices, emails and legal pages.</p>
                    <form
                        className="mt-5 space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            gen.put('/admin/system/general', { preserveScroll: true });
                        }}
                    >
                        <Field label="Legal company name" error={gen.errors.company_name}>
                            <Input value={gen.data.company_name} onChange={(e) => gen.setData('company_name', e.target.value)} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Support email" error={gen.errors.support_email}>
                                <Input type="email" value={gen.data.support_email} onChange={(e) => gen.setData('support_email', e.target.value)} />
                            </Field>
                            <Field label="Support phone" error={gen.errors.support_phone}>
                                <Input type="tel" value={gen.data.support_phone} onChange={(e) => gen.setData('support_phone', e.target.value)} />
                            </Field>
                        </div>
                        <Button type="submit" loading={gen.processing}>
                            Save
                        </Button>
                    </form>
                </Card>
            )}

            {tab === 'email' && (
                <div className="grid max-w-5xl gap-6 lg:grid-cols-[1fr_340px]">
                    <Card className="p-6">
                        <h2 className="text-lg font-semibold">Email provider</h2>
                        <p className="mt-1 mb-5 text-sm text-muted">
                            Verification links, referral alerts, receipts and digests are sent through this provider.
                        </p>
                        <ProviderForm
                            channel={email}
                            url="/admin/system/email"
                            extra={{ from_address: email.from_address, from_name: email.from_name }}
                        >
                            {({ data, set, errors }) => (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="From name" error={errors.from_name}>
                                        <Input value={data.from_name ?? ''} onChange={(e) => set('from_name', e.target.value)} />
                                    </Field>
                                    <Field label="From address" error={errors.from_address}>
                                        <Input type="email" value={data.from_address ?? ''} onChange={(e) => set('from_address', e.target.value)} />
                                    </Field>
                                </div>
                            )}
                        </ProviderForm>
                    </Card>
                    <div className="space-y-6">
                        <Card className="p-5">
                            <h3 className="font-semibold">Health</h3>
                            <div className="mt-3">
                                <HealthBadge health={email.health} />
                            </div>
                            <Link href="/admin/deliveries?channel=mail" className="mt-3 block text-sm font-medium text-primary">
                                Open delivery log
                            </Link>
                        </Card>
                        <Card className="p-5">
                            <TestSend url="/admin/system/email/test" label="Send a test email to" type="email" placeholder="you@company.com" />
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'sms' && (
                <div className="grid max-w-5xl gap-6 lg:grid-cols-[1fr_340px]">
                    <Card className="p-6">
                        <h2 className="text-lg font-semibold">Text message provider</h2>
                        <p className="mt-1 mb-5 text-sm text-muted">
                            Verification codes and urgent referral alerts. US numbers need A2P 10DLC registration with your provider.
                        </p>
                        <ProviderForm channel={sms} url="/admin/system/sms" />
                    </Card>
                    <div className="space-y-6">
                        <Card className="p-5">
                            <h3 className="font-semibold">Health</h3>
                            <div className="mt-3">
                                <HealthBadge health={sms.health} />
                            </div>
                            <Link href="/admin/deliveries?channel=sms" className="mt-3 block text-sm font-medium text-primary">
                                Open delivery log
                            </Link>
                        </Card>
                        <Card className="p-5">
                            <TestSend url="/admin/system/sms/test" label="Send a test text to" type="tel" placeholder="(435) 555-0119" />
                        </Card>
                    </div>
                </div>
            )}
            {tab === 'sms' && (
                <Card className="mt-6 p-5">
                    <h3 className="font-semibold">Chat messaging this month</h3>
                    <p className="mt-2 font-display text-2xl font-bold tabular">${messagingSpent.toFixed(2)}</p>
                    <p className="text-sm text-muted">
                        Status replies and WhatsApp messages. Status replies pause when the monthly cap above is reached.
                    </p>
                </Card>
            )}
            {tab === 'overview' && (
                <div className="max-w-5xl space-y-6">
                    <div className="flex flex-wrap items-center gap-3">
                        <p className="flex-1 text-muted">
                            Every provider at a glance. Run all checks before launch, or whenever something looks wrong.
                        </p>
                        <Button onClick={() => router.post('/admin/system/check-all', {}, { preserveScroll: true })}>
                            <Gauge /> Test everything
                        </Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {overview.map((p) => (
                            <Card key={p.key} className="p-5">
                                <div className="flex items-center gap-2">
                                    <h3 className="font-semibold">{p.label}</h3>
                                    <span className="ml-auto text-xs text-muted">{p.driver ?? 'not set'}</span>
                                </div>
                                {p.health && (
                                    <div className="mt-2">
                                        <HealthBadge health={p.health} />
                                    </div>
                                )}
                                <div className="mt-3">
                                    <CheckLine check={checks?.[p.key] ?? p.last} />
                                </div>
                            </Card>
                        ))}
                    </div>
                    <div className="grid gap-6 md:grid-cols-2">
                        <Card className="p-6">
                            <Wrench className="text-warning" aria-hidden />
                            <h2 className="mt-3 font-semibold">Maintenance mode</h2>
                            <p className="mt-1 text-sm text-muted">
                                Members see a branded “We’ll be right back” page. Staff can still sign in, and provider webhooks keep arriving so
                                nothing is lost.
                            </p>
                            <Button
                                className="mt-4"
                                variant={maintenance ? 'primary' : 'danger'}
                                onClick={() => router.post('/admin/system/maintenance', { on: !maintenance }, { preserveScroll: true })}
                            >
                                {maintenance ? 'Turn maintenance off' : 'Turn maintenance on'}
                            </Button>
                        </Card>
                        <Card className="p-6">
                            <Download className="text-primary" aria-hidden />
                            <h2 className="mt-3 font-semibold">Copy settings between environments</h2>
                            <p className="mt-1 text-sm text-muted">
                                Export non-secret settings from staging and import them in production. API keys and passwords are never exported;
                                enter them once in each environment.
                            </p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Button variant="secondary" asChild>
                                    <a href="/admin/system/export">
                                        <Download /> Export
                                    </a>
                                </Button>
                                <label className={cn(buttonVariants({ variant: 'secondary' }), 'cursor-pointer')}>
                                    <Upload className="size-4" aria-hidden /> Import
                                    <input
                                        type="file"
                                        accept="application/json"
                                        className="sr-only"
                                        onChange={(e) =>
                                            e.target.files?.[0] &&
                                            router.post(
                                                '/admin/system/import',
                                                { file: e.target.files[0] },
                                                { forceFormData: true, preserveScroll: true },
                                            )
                                        }
                                    />
                                </label>
                            </div>
                        </Card>
                    </div>
                </div>
            )}

            {tab === 'payments' && (
                <ProviderTab
                    title="Payments"
                    intro="Card payments for seat subscriptions. Add this webhook endpoint in Stripe: /webhooks/stripe."
                    channel={payments}
                    url="/admin/system/payments"
                    provider="payments"
                    checks={checks}
                />
            )}
            {tab === 'storage' && (
                <ProviderTab
                    title="File storage"
                    intro="Licenses, W-9s and agreements. Files are private and open only through links that expire after a few minutes."
                    channel={storage}
                    url="/admin/system/storage"
                    provider="storage"
                    checks={checks}
                />
            )}
            {tab === 'cdn' && (
                <ProviderTab
                    title="CDN"
                    intro="Public pages are cached at the edge for signed-out visitors. When a public page changes, it is purged from the CDN automatically."
                    channel={cdn}
                    url="/admin/system/cdn"
                    provider="cdn"
                    checks={checks}
                />
            )}
            {tab === 'ai' && (
                <ProviderTab
                    title="AI provider"
                    intro="Powers lead summaries, nudges and drafts in later sprints. Requests go through the provider’s API, which doesn’t use your data for training."
                    channel={ai}
                    url="/admin/system/ai"
                    provider="ai"
                    checks={checks}
                    extra={
                        <Card className="p-5">
                            <h3 className="font-semibold">Spent this month</h3>
                            <p className="mt-2 font-display text-2xl font-bold tabular">${ai.spent.toFixed(2)}</p>
                        </Card>
                    }
                />
            )}
            {tab === 'ai' && (
                <div className="mt-6">
                    <ProviderTab
                        title="Fallback AI provider"
                        intro="Used automatically when the main provider fails or reaches its monthly cap, so AI features keep working."
                        channel={aiFallback}
                        url="/admin/system/ai_fallback"
                        provider="ai"
                        checks={checks}
                    />
                </div>
            )}

            {tab === 'video' && (
                <ProviderTab
                    title="Video provider"
                    intro="Renders training videos with a stock AI presenter (needs the AI training videos module on). Finished videos are stored on your own storage."
                    channel={video}
                    url="/admin/system/video"
                    provider="video"
                    checks={checks}
                />
            )}
            {tab === 'analytics' && (
                <ProviderTab
                    title="Analytics store"
                    intro="Where reporting data lives, so dashboards stay fast. MySQL works to start (ideally a separate database); switch to ClickHouse for large volumes, then run a backfill."
                    channel={analytics}
                    url="/admin/system/analytics"
                    provider="analytics"
                    checks={checks}
                    extra={
                        <Card className="p-5">
                            <h3 className="font-semibold">{analytics.health.ok ? 'Healthy' : 'Not reachable'}</h3>
                            <p className="text-sm text-muted">{analytics.health.detail}</p>
                            <ul className="mt-3 space-y-1 text-sm">
                                {Object.entries(analytics.sources).map(([k, v]) => (
                                    <li key={k} className="flex justify-between gap-4">
                                        <span>{k.replace('_', ' ')}</span>
                                        <span className="text-muted tabular">
                                            {v.shipped.toLocaleString()} shipped · {v.pending.toLocaleString()} waiting
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    }
                />
            )}
            {tab === 'modules' && (
                <Card className="max-w-3xl">
                    <ul className="divide-y divide-line">
                        {modules.map((m) => (
                            <li key={m.key} className="flex items-start gap-4 p-5">
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">{m.label}</div>
                                    <div className="text-sm text-muted">{m.affects}</div>
                                    {!m.available && (
                                        <div className="mt-1 text-xs font-medium text-muted">
                                            Arrives in a later sprint; the switch does nothing yet.
                                        </div>
                                    )}
                                </div>
                                <input
                                    disabled={!m.available}
                                    type="checkbox"
                                    role="switch"
                                    aria-label={m.label}
                                    className="mt-1 size-5"
                                    checked={!!mods.data.modules[m.key]}
                                    onChange={(e) => mods.setData('modules', { ...mods.data.modules, [m.key]: e.target.checked })}
                                />
                            </li>
                        ))}
                    </ul>
                    <div className="border-t border-line p-5">
                        <Button loading={mods.processing} onClick={() => mods.put('/admin/system/modules', { preserveScroll: true })}>
                            Save modules
                        </Button>
                    </div>
                </Card>
            )}
        </AdminLayout>
    );
}
