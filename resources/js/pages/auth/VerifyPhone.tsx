import { useForm } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

export default function VerifyPhone({ phone, status, seo }: { phone: string | null; status?: string; seo: Seo }) {
    const form = useForm({ code: '' });
    const resend = useForm<{ code: string }>({ code: '' });
    const change = useForm({ phone: '' });
    const [editing, setEditing] = useState(phone === null);
    const emailCode = useForm<{ code: string }>({ code: '' });
    // If the text hasn't arrived after 30 seconds, offer the code by email instead.
    const [canEmail, setCanEmail] = useState(false);
    useEffect(() => {
        const t = window.setTimeout(() => setCanEmail(true), 30_000);
        return () => window.clearTimeout(t);
    }, []);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/verify-phone');
    };

    return (
        <AuthLayout
            title="Verify your mobile"
            subtitle={
                phone && !editing ? (
                    <>We texted a 6-digit code to {phone}.</>
                ) : (
                    'Referral alerts come by text, so we need a mobile number we can reach.'
                )
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-5">
                    {statusMessage(status)}
                </Alert>
            )}
            {!editing ? (
                <>
                    <form onSubmit={submit} className="space-y-4" noValidate>
                        <Field label="Verification code" error={form.errors.code}>
                            <Input
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                className="text-center text-xl tracking-[0.5em] tabular"
                                value={form.data.code}
                                onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                            />
                        </Field>
                        <Button type="submit" className="w-full" loading={form.processing}>
                            Verify
                        </Button>
                    </form>
                    <div className="mt-4 flex justify-between text-sm">
                        <button className="font-medium text-primary" onClick={() => resend.post('/verify-phone/send')} disabled={resend.processing}>
                            Send a new code
                        </button>
                        <button className="text-muted hover:text-ink" onClick={() => setEditing(true)}>
                            Use a different number
                        </button>
                    </div>
                    {canEmail && (
                        <Button
                            variant="ghost"
                            className="mt-3 w-full"
                            loading={emailCode.processing}
                            onClick={() => emailCode.post('/verify-phone/email')}
                        >
                            Text not arriving? Email me the code instead
                        </Button>
                    )}
                    {(resend.errors.code ?? emailCode.errors.code) && (
                        <Alert tone="danger" className="mt-3">
                            {resend.errors.code ?? emailCode.errors.code}
                        </Alert>
                    )}
                </>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        change.put('/verify-phone/number', { onSuccess: () => setEditing(false) });
                    }}
                    className="space-y-4"
                    noValidate
                >
                    <Field label="Mobile number" error={change.errors.phone}>
                        <Input
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel-national"
                            placeholder="(435) 555-0119"
                            value={change.data.phone}
                            onChange={(e) => change.setData('phone', e.target.value)}
                        />
                    </Field>
                    <Button type="submit" className="w-full" loading={change.processing}>
                        Text me a code
                    </Button>
                </form>
            )}
        </AuthLayout>
    );
}
