import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function ConfirmPassword({ seo }: { seo: Seo }) {
    const form = useForm({ password: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/user/confirm-password', { onFinish: () => form.reset() });
    };
    return (
        <AuthLayout title="Confirm your password" subtitle="This is a protected action. Confirm it’s you to continue.">
            <SeoHead seo={seo} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <Field label="Password" error={form.errors.password}>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        autoFocus
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </Field>
                <Button type="submit" className="w-full" loading={form.processing}>
                    Confirm
                </Button>
            </form>
        </AuthLayout>
    );
}
