import { router, useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Lic = { id: number; number: string; status: string; expires: string | null; note: string | null; state: string; industry: string };
type Props = {
    licenses: Lic[];
    industries: { id: number; name: string }[];
    states: { id: number; code: string; name: string }[];
    canEdit: boolean;
    status?: string;
    seo: Seo;
};

const tone: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
    verified: 'success',
    pending: 'warning',
    rejected: 'danger',
    expired: 'danger',
};

export default function Licenses({ licenses, industries, states, canEdit, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const form = useForm<{ industry_id: string; state_id: string; number: string; expires_on: string; holder_name: string; document: File | null }>({
        industry_id: String(industries[0]?.id ?? ''),
        state_id: String(states[0]?.id ?? ''),
        number: '',
        expires_on: '',
        holder_name: '',
        document: null,
    });
    const e = form.errors as Record<string, string>;
    return (
        <PortalLayout title="Profile and licenses" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.2fr_1fr]">
                <Card className="p-6">
                    <h2 className="font-semibold">Your licenses</h2>
                    <p className="mt-1 text-sm text-muted">Verified licenses move you up waitlists and are shown on your profile.</p>
                    <ul className="mt-4 divide-y divide-line">
                        {licenses.length === 0 && <li className="py-6 text-sm text-muted">No licenses yet.</li>}
                        {licenses.map((l) => (
                            <li key={l.id} className="flex items-start gap-3 py-3 text-sm">
                                <div className="min-w-0 flex-1">
                                    <div className="font-medium">
                                        {l.state} · <span className="font-mono">{l.number}</span>
                                    </div>
                                    <div className="text-muted">
                                        {l.industry}
                                        {l.expires ? ` · expires ${l.expires}` : ''}
                                    </div>
                                    {l.status === 'rejected' && l.note && <div className="mt-1 text-danger">{l.note}</div>}
                                </div>
                                <Badge tone={tone[l.status] ?? 'neutral'}>{l.status}</Badge>
                                {canEdit && l.status !== 'verified' && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove ${l.number}`}
                                        onClick={() => router.delete(`/app/licenses/${l.id}`, { preserveScroll: true })}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
                {canEdit && (
                    <Card className="space-y-4 p-6">
                        <h2 className="font-semibold">Add a license</h2>
                        <Field label="Industry" error={e.industry_id}>
                            <Select value={form.data.industry_id} onChange={(ev) => form.setData('industry_id', ev.target.value)}>
                                {industries.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="State" error={e.state_id}>
                                <Select value={form.data.state_id} onChange={(ev) => form.setData('state_id', ev.target.value)}>
                                    {states.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Expires" error={e.expires_on}>
                                <Input type="date" value={form.data.expires_on} onChange={(ev) => form.setData('expires_on', ev.target.value)} />
                            </Field>
                        </div>
                        <Field label="License number" error={e.number}>
                            <Input value={form.data.number} onChange={(ev) => form.setData('number', ev.target.value)} />
                        </Field>
                        <Field label="Name on the license" hint="If it isn’t your team’s name." error={e.holder_name}>
                            <Input value={form.data.holder_name} onChange={(ev) => form.setData('holder_name', ev.target.value)} />
                        </Field>
                        <Field
                            label="Copy of the license (optional)"
                            hint="PDF or photo, up to 8 MB. Kept private; only our verification team sees it."
                            error={e.document}
                        >
                            <input
                                type="file"
                                accept="application/pdf,image/jpeg,image/png"
                                onChange={(ev) => form.setData('document', ev.target.files?.[0] ?? null)}
                            />
                        </Field>
                        <Button
                            loading={form.processing}
                            onClick={() =>
                                form.post('/app/licenses', {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('number', 'expires_on', 'holder_name', 'document'),
                                })
                            }
                        >
                            Add license
                        </Button>
                    </Card>
                )}
            </div>
        </PortalLayout>
    );
}
