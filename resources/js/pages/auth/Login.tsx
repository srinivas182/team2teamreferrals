import { Link, useForm } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { Fingerprint, Mail } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function Login({ status, seo }: { status?: string; seo: Seo }) {
    const form = useForm({ email: '', password: '', remember: true });
    const magic = useForm({ email: '' });
    const [mode, setMode] = useState<'password' | 'link'>('password');
    // Autofill runs silently on load; only show passkey errors after the member asks for a passkey.
    const [triedPasskey, setTriedPasskey] = useState(false);
    const passkey = usePasskeyVerify({ autofill: true, onSuccess: () => (window.location.href = '/app') });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (mode === 'password') form.post('/login', { onFinish: () => form.reset('password') });
        else {
            magic.transform(() => ({ email: form.data.email }));
            magic.post('/login/magic');
        }
    };

    return (
        <AuthLayout
            title="Welcome back"
            subtitle="Log in to see referrals waiting for you."
            footer={
                <>
                    New here?{' '}
                    <Link href="/register" className="font-semibold text-primary">
                        Claim your city
                    </Link>
                </>
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-5">
                    {status}
                </Alert>
            )}
            {passkey.isSupported && (
                <>
                    <Button
                        type="button"
                        variant="secondary"
                        className="w-full"
                        loading={passkey.isLoading}
                        onClick={() => {
                            setTriedPasskey(true);
                            passkey.verify();
                        }}
                    >
                        <Fingerprint />
                        Sign in with a passkey
                    </Button>
                    {triedPasskey && passkey.error && (
                        <Alert tone="danger" className="mt-3">
                            {passkey.error}
                        </Alert>
                    )}
                    <div className="my-6 flex items-center gap-3 text-xs text-subtle">
                        <span className="h-px flex-1 bg-line" />
                        or with your email
                        <span className="h-px flex-1 bg-line" />
                    </div>
                </>
            )}
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Email" error={form.errors.email ?? magic.errors.email}>
                    <Input
                        type="email"
                        autoComplete="username webauthn"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Field>
                {mode === 'password' && (
                    <>
                        <Field label="Password" error={form.errors.password}>
                            <Input
                                type="password"
                                autoComplete="current-password"
                                required
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                        </Field>
                        <div className="flex items-center justify-between text-sm">
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                                Keep me signed in
                            </label>
                            <Link href="/forgot-password" className="font-medium text-primary">
                                Forgot password?
                            </Link>
                        </div>
                    </>
                )}
                <Button type="submit" className="w-full" loading={form.processing || magic.processing}>
                    {mode === 'password' ? 'Log in' : 'Email me a sign-in link'}
                </Button>
            </form>
            <Button variant="ghost" className="mt-3 w-full" onClick={() => setMode(mode === 'password' ? 'link' : 'password')}>
                {mode === 'password' ? (
                    <>
                        <Mail /> Email me a sign-in link instead
                    </>
                ) : (
                    'Use my password instead'
                )}
            </Button>
        </AuthLayout>
    );
}
