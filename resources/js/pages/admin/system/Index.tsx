import { Link, useForm } from '@inertiajs/react';
import { Mail, MessageSquare, Building2, Send } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { HealthBadge, type Health } from '@/components/admin/HealthBadge';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
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

type Props = {
    tab: 'general' | 'email' | 'sms';
    general: { company_name: string | null; support_email: string | null; support_phone: string | null };
    email: Channel & { from_address: string; from_name: string };
    sms: Channel;
    status?: string;
    seo: Seo;
};

const tabs = [
    ['general', 'General', Building2],
    ['email', 'Email', Mail],
    ['sms', 'Text messages', MessageSquare],
] as const;

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

export default function SystemSettings({ tab, general, email, sms, status, seo }: Props) {
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
        </AdminLayout>
    );
}
