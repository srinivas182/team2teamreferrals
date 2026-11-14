import { router, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

/** Enter the text code that confirms the requester's phone. */
export default function RequestVerify({ id, phone, status, seo }: { id: string; phone: string; status?: string; seo: Seo }) {
    const form = useForm({ code: '' });
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-md px-4 py-16">
                <h1 className="font-display text-3xl font-bold">Check your phone</h1>
                <p className="mt-3">We texted a 6-digit code to {phone}. Enter it to send your request.</p>
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <form
                    className="mt-6 space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/request/${id}/verify`);
                    }}
                >
                    <Field label="Code" error={form.errors.code}>
                        <Input
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={form.data.code}
                            onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))}
                            required
                        />
                    </Field>
                    <div className="flex gap-2">
                        <Button type="submit" loading={form.processing} disabled={form.data.code.length !== 6}>
                            Send my request
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => router.post(`/request/${id}/resend`)}>
                            Send a new code
                        </Button>
                    </div>
                </form>
            </section>
        </PublicLayout>
    );
}
