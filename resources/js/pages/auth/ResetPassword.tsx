import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function ResetPassword({ email, token, seo }: { email: string; token: string; seo: Seo }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };
    return (
        <AuthLayout title="Choose a new password">
            <SeoHead seo={seo} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Email" error={form.errors.email}>
                    <Input type="email" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </Field>
                <Field label="New password" hint="At least 12 characters." error={form.errors.password}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </Field>
                <Field label="Confirm new password" error={form.errors.password_confirmation}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </Field>
                <Button type="submit" className="w-full" loading={form.processing}>
                    Save password and log in
                </Button>
            </form>
        </AuthLayout>
    );
}
