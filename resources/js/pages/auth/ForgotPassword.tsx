import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

export default function ForgotPassword({ status, seo }: { status?: string; seo: Seo }) {
    const form = useForm({ email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/forgot-password');
    };
    return (
        <AuthLayout
            title="Reset your password"
            subtitle="We’ll email you a link to choose a new one."
            footer={
                <Link href="/login" className="font-semibold text-primary">
                    Back to log in
                </Link>
            }
        >
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-5">
                    {statusMessage(status)}
                </Alert>
            )}
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Email" error={form.errors.email}>
                    <Input
                        type="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </Field>
                <Button type="submit" className="w-full" loading={form.processing}>
                    Email me a reset link
                </Button>
            </form>
        </AuthLayout>
    );
}
