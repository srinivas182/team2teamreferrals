import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function TwoFactorChallenge({ seo }: { seo: Seo }) {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '', recovery_code: '', remember_device: true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) =>
            recovery ? { recovery_code: d.recovery_code, remember_device: d.remember_device } : { code: d.code, remember_device: d.remember_device },
        );
        form.post('/two-factor-challenge');
    };
    return (
        <AuthLayout
            title="Two-step sign-in"
            subtitle={recovery ? 'Enter one of your recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}
        >
            <SeoHead seo={seo} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                {recovery ? (
                    <Field label="Recovery code" error={form.errors.recovery_code}>
                        <Input
                            autoComplete="one-time-code"
                            value={form.data.recovery_code}
                            onChange={(e) => form.setData('recovery_code', e.target.value)}
                        />
                    </Field>
                ) : (
                    <Field label="Authentication code" error={form.errors.code}>
                        <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            className="text-center text-xl tracking-[0.5em] tabular"
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                        />
                    </Field>
                )}
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.remember_device} onChange={(e) => form.setData('remember_device', e.target.checked)} />
                    Don’t ask again on this device for 30 days
                </label>
                <Button type="submit" className="w-full" loading={form.processing}>
                    Continue
                </Button>
            </form>
            <Button variant="ghost" className="mt-3 w-full" onClick={() => setRecovery(!recovery)}>
                {recovery ? 'Use my authenticator app' : 'Use a recovery code instead'}
            </Button>
        </AuthLayout>
    );
}
