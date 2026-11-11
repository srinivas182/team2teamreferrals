import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function Register({ seo, invitedBy = null }: { seo: Seo; invitedBy?: string | null }) {
    const form = useForm({ first_name: '', last_name: '', email: '', phone: '', password: '', password_confirmation: '', terms: false });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };
    const set = (k: keyof typeof form.data) => (e: React.ChangeEvent<HTMLInputElement>) =>
        form.setData(k, e.target.type === 'checkbox' ? e.target.checked : e.target.value);

    return (
        <AuthLayout
            title="Claim your city"
            subtitle={
                invitedBy
                    ? `${invitedBy} invited you. Free during the pilot; takes about five minutes.`
                    : 'Free during the pilot. Takes about five minutes.'
            }
            footer={
                <>
                    Already a member?{' '}
                    <Link href="/login" className="font-semibold text-primary">
                        Log in
                    </Link>
                </>
            }
        >
            <SeoHead seo={seo} />
            <form onSubmit={submit} className="space-y-4" noValidate>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="First name" error={form.errors.first_name}>
                        <Input autoComplete="given-name" required value={form.data.first_name} onChange={set('first_name')} />
                    </Field>
                    <Field label="Last name" error={form.errors.last_name}>
                        <Input autoComplete="family-name" required value={form.data.last_name} onChange={set('last_name')} />
                    </Field>
                </div>
                <Field label="Work email" error={form.errors.email}>
                    <Input type="email" autoComplete="email" required value={form.data.email} onChange={set('email')} />
                </Field>
                <Field label="Mobile" hint="Referral alerts come by text. We’ll send a code to confirm it." error={form.errors.phone}>
                    <Input
                        type="tel"
                        inputMode="tel"
                        autoComplete="tel-national"
                        placeholder="(435) 555-0119"
                        required
                        value={form.data.phone}
                        onChange={set('phone')}
                    />
                </Field>
                <Field label="Password" hint="At least 12 characters. We check it against known data breaches." error={form.errors.password}>
                    <Input type="password" autoComplete="new-password" required value={form.data.password} onChange={set('password')} />
                </Field>
                <Field label="Confirm password" error={form.errors.password_confirmation}>
                    <Input
                        type="password"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={set('password_confirmation')}
                    />
                </Field>
                <div>
                    <label className="flex gap-2 text-sm text-muted">
                        <input type="checkbox" className="mt-1" checked={form.data.terms} onChange={set('terms')} />
                        <span>
                            I agree to the{' '}
                            <a href="/legal/terms" className="text-primary underline">
                                terms
                            </a>{' '}
                            and{' '}
                            <a href="/legal/privacy" className="text-primary underline">
                                privacy policy
                            </a>
                            , and to receive referral alerts by text. Reply STOP to opt out.
                        </span>
                    </label>
                    {form.errors.terms && <p className="mt-1.5 text-sm text-danger dark:text-[#FDA4AF]">{form.errors.terms}</p>}
                </div>
                <Button type="submit" className="w-full" loading={form.processing}>
                    Create account
                </Button>
            </form>
        </AuthLayout>
    );
}
