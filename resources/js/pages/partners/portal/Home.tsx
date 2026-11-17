import { Link, router, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = { signedIn: boolean; partners: { id: string; name: string; category: string; status: string }[]; status?: string; seo: Seo };

/** Partner account: sign in with an emailed link, then choose a business when one address has several. */
export default function PartnerPortalHome({ signedIn, partners, status, seo }: Props) {
    const form = useForm({ email: '' });
    return (
        <PublicLayout current="/partners">
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-lg px-4 py-12">
                <h1 className="font-display text-3xl font-bold">Partner account</h1>
                {status && (
                    <Alert tone="success" className="mt-4">
                        {statusMessage(status)}
                    </Alert>
                )}
                {signedIn ? (
                    <div className="mt-6 space-y-3">
                        {partners.length === 0 && <p>There’s no listing for this email address.</p>}
                        {partners.map((p) => (
                            <Card key={p.id} className="p-4">
                                <Link href={`/partner-portal/${p.id}`} className="font-semibold text-primary">
                                    {p.name}
                                </Link>
                                <p className="text-sm text-muted">
                                    {p.category} · {p.status}
                                </p>
                            </Card>
                        ))}
                        <Button variant="secondary" onClick={() => router.post('/partner-portal/logout')}>
                            Sign out
                        </Button>
                    </div>
                ) : (
                    <form
                        className="mt-6 space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/partner-portal/link', { preserveScroll: true });
                        }}
                    >
                        <p>Enter the email address on your listing. We’ll send you a link to sign in; it works for 30 minutes.</p>
                        <Field label="Email" error={form.errors.email}>
                            <Input
                                type="email"
                                autoComplete="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                required
                            />
                        </Field>
                        <Button type="submit" loading={form.processing}>
                            Email me a sign-in link
                        </Button>
                        <p className="text-sm text-muted">
                            Not listed yet?{' '}
                            <Link href="/partners/advertise" className="text-primary">
                                Advertise with us
                            </Link>
                        </p>
                    </form>
                )}
            </div>
        </PublicLayout>
    );
}
