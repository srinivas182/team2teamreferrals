import { useForm } from '@inertiajs/react';
import { Mail, UserRound } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { SettingsTabs } from '@/components/SettingsTabs';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, PasswordInput, PhoneInput, Select } from '@/components/ui/field';
import { AccountLayout } from '@/layouts/AccountLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = {
    account: { first_name: string; last_name: string; email: string; phone: string | null; phoneVerified: boolean; timezone: string };
    timezones: { id: string; label: string }[];
    texting: boolean;
    status?: string;
    seo: Seo;
};

function Section({ icon: Icon, title, description, children }: { icon: typeof Mail; title: string; description: string; children: ReactNode }) {
    return (
        <Card className="p-6">
            <div className="flex items-start gap-3">
                <Icon className="mt-0.5 size-5 text-primary" aria-hidden />
                <div>
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <p className="text-sm text-muted">{description}</p>
                </div>
            </div>
            <div className="mt-5">{children}</div>
        </Card>
    );
}

export default function Account({ account, timezones, texting, status, seo }: Props) {
    const details = useForm({
        first_name: account.first_name,
        last_name: account.last_name,
        phone: (account.phone ?? '').replace(/^\+1/, ''),
        timezone: account.timezone,
    });
    const email = useForm({ email: '', password: '' });

    const saveDetails = (e: FormEvent) => {
        e.preventDefault();
        details.put('/app/settings/account', { preserveScroll: true });
    };
    const changeEmail = (e: FormEvent) => {
        e.preventDefault();
        email.post('/app/settings/account/email', { preserveScroll: true, onSuccess: () => email.reset('password') });
    };

    return (
        <AccountLayout title="My account">
            <SeoHead seo={seo} />
            <div className="max-w-3xl">
                <SettingsTabs />
                <div className="space-y-6">
                    {status && <Alert tone="success">{statusMessage(status)}</Alert>}

                    <Section icon={UserRound} title="Your details" description="Your name is shown to other teams on introductions and referrals.">
                        <form onSubmit={saveDetails} className="grid gap-4 sm:grid-cols-2" noValidate>
                            <Field label="First name" error={details.errors.first_name}>
                                <Input
                                    autoComplete="given-name"
                                    value={details.data.first_name}
                                    onChange={(e) => details.setData('first_name', e.target.value)}
                                />
                            </Field>
                            <Field label="Last name" error={details.errors.last_name}>
                                <Input
                                    autoComplete="family-name"
                                    value={details.data.last_name}
                                    onChange={(e) => details.setData('last_name', e.target.value)}
                                />
                            </Field>
                            <Field
                                label="Mobile"
                                error={details.errors.phone}
                                hint={
                                    texting
                                        ? 'If you change it, we’ll text a code to the new number before it’s used.'
                                        : account.phone
                                          ? undefined
                                          : 'Optional while text messages are off.'
                                }
                            >
                                <PhoneInput
                                    autoComplete="tel-national"
                                    value={details.data.phone}
                                    onChange={(e) => details.setData('phone', e.target.value)}
                                />
                            </Field>
                            <Field label="Time zone" error={details.errors.timezone} hint="Used for reminders, quiet hours and digests.">
                                <Select value={details.data.timezone} onChange={(e) => details.setData('timezone', e.target.value)}>
                                    {timezones.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.label}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <div className="sm:col-span-2">
                                <Button type="submit" disabled={details.processing}>
                                    Save details
                                </Button>
                            </div>
                        </form>
                    </Section>

                    <Section
                        icon={Mail}
                        title="Email"
                        description={`You sign in with ${account.email}. To change it, we send a link to the new address; it changes when you click it, and we let the old address know.`}
                    >
                        <form onSubmit={changeEmail} className="grid gap-4 sm:grid-cols-2" noValidate>
                            <Field label="New email" error={email.errors.email}>
                                <Input
                                    type="email"
                                    autoComplete="email"
                                    value={email.data.email}
                                    onChange={(e) => email.setData('email', e.target.value)}
                                />
                            </Field>
                            <Field label="Your password" error={email.errors.password} hint="To confirm it’s you.">
                                <PasswordInput
                                    autoComplete="current-password"
                                    value={email.data.password}
                                    onChange={(e) => email.setData('password', e.target.value)}
                                />
                            </Field>
                            <div className="sm:col-span-2">
                                <Button type="submit" variant="secondary" disabled={email.processing}>
                                    Send confirmation link
                                </Button>
                            </div>
                        </form>
                    </Section>
                </div>
            </div>
        </AccountLayout>
    );
}
