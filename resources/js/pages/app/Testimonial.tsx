import { router, useForm, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type T = { id: number; quote: string; status: string; created_at: string };

/** Portal → Share your experience: a quote for the website, with your consent; withdraw any time. */
export default function Testimonial({ testimonials, consent, status, seo }: { testimonials: T[]; consent: string; status?: string; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const f = useForm({ quote: '', consent: false });
    return (
        <PortalLayout title="Share your experience" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-2xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="space-y-3 p-5">
                    <Field label="What has the network done for your business?" error={f.errors.quote}>
                        <textarea
                            rows={4}
                            maxLength={600}
                            className="w-full rounded-md border border-line bg-surface p-2"
                            value={f.data.quote}
                            onChange={(e) => f.setData('quote', e.target.value)}
                        />
                    </Field>
                    <label className="flex items-start gap-2 text-sm">
                        <input type="checkbox" className="mt-1" checked={f.data.consent} onChange={(e) => f.setData('consent', e.target.checked)} />{' '}
                        {consent}
                    </label>
                    {f.errors.consent && <p className="text-sm text-danger">{f.errors.consent}</p>}
                    <Button
                        disabled={f.data.quote.trim().length < 20}
                        loading={f.processing}
                        onClick={() => f.post('/app/testimonial', { preserveScroll: true, onSuccess: () => f.reset() })}
                    >
                        Send
                    </Button>
                </Card>
                {testimonials.map((t) => (
                    <Card key={t.id} className="space-y-2 p-4 text-sm">
                        <p>“{t.quote}”</p>
                        <div className="flex items-center gap-2">
                            <Badge tone={t.status === 'approved' ? 'success' : 'neutral'}>
                                {t.status === 'approved' ? 'Published-ready' : t.status}
                            </Badge>
                            {(t.status === 'pending' || t.status === 'approved') && (
                                <button
                                    className="text-xs text-muted underline"
                                    onClick={() =>
                                        window.confirm('Withdraw this? It will be removed from the website.') &&
                                        router.post(`/app/testimonial/${t.id}/withdraw`, {}, { preserveScroll: true })
                                    }
                                >
                                    Withdraw
                                </button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
