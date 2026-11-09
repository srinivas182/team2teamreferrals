import { router, useForm, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { Fingerprint, KeyRound, Laptop, ShieldCheck, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    twoFactor: { enabled: boolean; pending: boolean; qrSvg: string | null; setupKey: string | null; recoveryCodes: string[] | null };
    passkeys: { id: number; name: string; lastUsed: string | null; added: string | null }[];
    devices: { id: number; label: string; ip: string | null; lastSeen: string; current: boolean; trusted: boolean }[];
    status?: string;
    seo: Seo;
};

function Section({ icon: Icon, title, description, children }: { icon: typeof KeyRound; title: string; description: string; children: ReactNode }) {
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

export default function Security({ twoFactor, passkeys, devices, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });
    const confirm2fa = useForm({ code: '' });
    const others = useForm({ password: '' });
    const [othersOpen, setOthersOpen] = useState(false);
    const [passkeyName, setPasskeyName] = useState('');
    const passkey = usePasskeyRegister({ onSuccess: () => router.reload({ only: ['passkeys'] }) });

    const savePassword = (e: FormEvent) => {
        e.preventDefault();
        password.put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onSuccess: () => password.reset() });
    };

    return (
        <PortalLayout title="Security" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-6">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}

                <Section
                    icon={Fingerprint}
                    title="Passkeys"
                    description="Sign in with Face ID, Touch ID, Windows Hello or a security key. Faster than a password and can’t be phished."
                >
                    {passkeys.length > 0 && (
                        <ul className="mb-4 divide-y divide-line rounded-md border border-line">
                            {passkeys.map((p) => (
                                <li key={p.id} className="flex items-center gap-3 p-3 text-sm">
                                    <KeyRound className="size-4 text-muted" aria-hidden />
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">{p.name}</div>
                                        <div className="text-muted">
                                            Added {p.added}
                                            {p.lastUsed ? ` · last used ${p.lastUsed}` : ''}
                                        </div>
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove passkey ${p.name}`}
                                        onClick={() => router.delete(`/user/passkeys/${p.id}`, { preserveScroll: true })}
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {passkey.isSupported ? (
                        <form
                            className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                passkey.register(passkeyName || 'My device');
                            }}
                        >
                            <Field label="Name this passkey" className="flex-1">
                                <Input
                                    placeholder="e.g. iPhone 16, Work MacBook"
                                    value={passkeyName}
                                    onChange={(e) => setPasskeyName(e.target.value)}
                                />
                            </Field>
                            <Button type="submit" loading={passkey.isLoading}>
                                Add a passkey
                            </Button>
                        </form>
                    ) : (
                        <p className="text-sm text-muted">This browser doesn’t support passkeys. Try the latest Chrome, Safari, Edge or Firefox.</p>
                    )}
                    {passkey.error && (
                        <Alert tone="danger" className="mt-3">
                            {passkey.error}
                        </Alert>
                    )}
                </Section>

                <Section
                    icon={ShieldCheck}
                    title="Two-step sign-in"
                    description="After your password, enter a code from an authenticator app such as Google Authenticator, 1Password or Authy."
                >
                    {twoFactor.enabled ? (
                        <div className="space-y-4">
                            <Badge tone="success">On</Badge>
                            {twoFactor.recoveryCodes && (
                                <div>
                                    <p className="text-sm font-medium">
                                        Recovery codes — each works once if you lose your phone. Store them somewhere safe.
                                    </p>
                                    <ul className="mt-2 grid grid-cols-2 gap-1 rounded-md bg-bg p-4 font-mono text-sm tabular">
                                        {twoFactor.recoveryCodes.map((c) => (
                                            <li key={c}>{c}</li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    variant="secondary"
                                    onClick={() => router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true })}
                                >
                                    New recovery codes
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="text-danger"
                                    onClick={() => router.delete('/user/two-factor-authentication', { preserveScroll: true })}
                                >
                                    Turn off
                                </Button>
                            </div>
                        </div>
                    ) : twoFactor.pending && twoFactor.qrSvg ? (
                        <div className="grid gap-6 sm:grid-cols-[200px_1fr]">
                            <div
                                className="rounded-md bg-white p-3"
                                role="img"
                                aria-label="QR code for your authenticator app"
                                dangerouslySetInnerHTML={{ __html: twoFactor.qrSvg }}
                            />
                            <form
                                className="space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    confirm2fa.post('/user/confirmed-two-factor-authentication', {
                                        errorBag: 'confirmTwoFactorAuthentication',
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <p className="text-sm text-muted">
                                    Scan the code, or enter this key: <span className="font-mono break-all text-ink">{twoFactor.setupKey}</span>
                                </p>
                                <Field label="6-digit code from the app" error={confirm2fa.errors.code}>
                                    <Input
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={6}
                                        value={confirm2fa.data.code}
                                        onChange={(e) => confirm2fa.setData('code', e.target.value.replace(/\D/g, ''))}
                                    />
                                </Field>
                                <Button type="submit" loading={confirm2fa.processing}>
                                    Turn on
                                </Button>
                            </form>
                        </div>
                    ) : (
                        <Button onClick={() => router.post('/user/two-factor-authentication', {}, { preserveScroll: true })}>
                            Set up two-step sign-in
                        </Button>
                    )}
                </Section>

                <Section icon={KeyRound} title="Password" description="At least 12 characters. We check new passwords against known data breaches.">
                    <form onSubmit={savePassword} className="grid gap-4 sm:grid-cols-2" noValidate>
                        <Field label="Current password" error={password.errors.current_password} className="sm:col-span-2">
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={password.data.current_password}
                                onChange={(e) => password.setData('current_password', e.target.value)}
                            />
                        </Field>
                        <Field label="New password" error={password.errors.password}>
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password}
                                onChange={(e) => password.setData('password', e.target.value)}
                            />
                        </Field>
                        <Field label="Confirm new password" error={password.errors.password_confirmation}>
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password_confirmation}
                                onChange={(e) => password.setData('password_confirmation', e.target.value)}
                            />
                        </Field>
                        <div>
                            <Button type="submit" loading={password.processing}>
                                Change password
                            </Button>
                        </div>
                    </form>
                </Section>

                <Section icon={Laptop} title="Signed-in devices" description="Remove any device you don’t recognise, then change your password.">
                    <ul className="divide-y divide-line rounded-md border border-line">
                        {devices.map((d) => (
                            <li key={d.id} className="flex flex-wrap items-center gap-3 p-3 text-sm">
                                <Laptop className="size-4 text-muted" aria-hidden />
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {d.label} {d.current && <Badge tone="sender">This device</Badge>}{' '}
                                        {d.trusted && <Badge>Skips two-step for 30 days</Badge>}
                                    </div>
                                    <div className="text-muted">
                                        {d.ip ?? 'Unknown location'} · active {d.lastSeen}
                                    </div>
                                </div>
                                {!d.current && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => router.delete(`/app/settings/security/devices/${d.id}`, { preserveScroll: true })}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                    <Button variant="secondary" className="mt-4" onClick={() => setOthersOpen(true)}>
                        Sign out of all other devices
                    </Button>
                    <Dialog open={othersOpen} onOpenChange={setOthersOpen}>
                        <DialogContent title="Sign out everywhere else?" description="Every other browser and phone will need to sign in again.">
                            <form
                                className="space-y-4"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    others.post('/app/settings/security/other-devices', {
                                        preserveScroll: true,
                                        onSuccess: () => setOthersOpen(false),
                                    });
                                }}
                            >
                                <Field label="Your password" error={others.errors.password}>
                                    <Input
                                        type="password"
                                        autoComplete="current-password"
                                        value={others.data.password}
                                        onChange={(e) => others.setData('password', e.target.value)}
                                    />
                                </Field>
                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="ghost" onClick={() => setOthersOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" loading={others.processing}>
                                        Sign out other devices
                                    </Button>
                                </div>
                            </form>
                        </DialogContent>
                    </Dialog>
                </Section>
            </div>
        </PortalLayout>
    );
}
