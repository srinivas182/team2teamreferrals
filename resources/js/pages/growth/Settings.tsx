import { useForm, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = { sending: boolean; fromAddress: string; fromName: string; postalAddress: string; status?: string; seo: Seo };

/** Admin → Growth → Email settings: the prospect sending address, and the switch that lets prospect email go out. */
export default function GrowthSettings({ sending, fromAddress, fromName, postalAddress, status, seo }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const f = useForm({ sending, from_address: fromAddress, from_name: fromName });
    return (
        <AdminLayout title="Growth: email settings">
            <SeoHead seo={seo} />
            <div className="max-w-2xl space-y-5">
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {errors.sending && <Alert tone="danger">{errors.sending}</Alert>}
                <Card className="space-y-3 p-5 text-sm">
                    <p>
                        Email to prospects (seat alerts now, campaigns in the next sprint) goes from a separate subdomain, so that member and referral
                        email keeps its good reputation. Before switching sending on, set up the subdomain with your email provider: its SPF, DKIM and
                        DMARC records.
                    </p>
                    <p className="text-muted">
                        Every email shows the company’s postal address and a one-click unsubscribe. Postal address now:{' '}
                        {postalAddress || 'not set (Admin → System → General)'}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="From address" error={f.errors.from_address}>
                            <Input type="email" value={f.data.from_address} onChange={(e) => f.setData('from_address', e.target.value)} />
                        </Field>
                        <Field label="From name" error={f.errors.from_name}>
                            <Input value={f.data.from_name} onChange={(e) => f.setData('from_name', e.target.value)} />
                        </Field>
                    </div>
                    <label className="flex items-center gap-2">
                        <input type="checkbox" className="size-4" checked={f.data.sending} onChange={(e) => f.setData('sending', e.target.checked)} />{' '}
                        Send prospect email (the subdomain’s DNS records are in place)
                    </label>
                    <Button loading={f.processing} onClick={() => f.post('/admin/growth/settings', { preserveScroll: true })}>
                        Save
                    </Button>
                </Card>
            </div>
        </AdminLayout>
    );
}
