import { Link, router, useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Macro = { id: number; title: string; body: string };
type Sla = { priority: string; label: string; first: number; resolve: number };

function MacroForm({ m }: { m: Macro | null }) {
    const f = useForm({ title: m?.title ?? '', body: m?.body ?? '' });
    return (
        <Card className="space-y-3 p-4">
            <Field label="Title" error={f.errors.title}>
                <Input value={f.data.title} onChange={(e) => f.setData('title', e.target.value)} />
            </Field>
            <Field label="Reply" hint="{first_name} and {ticket} are filled in when used." error={f.errors.body}>
                <Textarea rows={4} value={f.data.body} onChange={(e) => f.setData('body', e.target.value)} />
            </Field>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    loading={f.processing}
                    onClick={() =>
                        m
                            ? f.put(`/admin/support/macros/${m.id}`, { preserveScroll: true })
                            : f.post('/admin/support/macros', { preserveScroll: true, onSuccess: () => f.reset() })
                    }
                >
                    {m ? 'Save' : 'Add saved reply'}
                </Button>
                {m && (
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/admin/support/macros/${m.id}`, { preserveScroll: true })}>
                        Delete
                    </Button>
                )}
            </div>
        </Card>
    );
}

/** Admin → Support → Settings: saved replies, response-time targets and the support email address. */
export default function SupportSettings({
    macros,
    sla,
    supportAddress,
    flash,
    seo,
}: {
    macros: Macro[];
    sla: Sla[];
    supportAddress: string | null;
    flash?: string;
    seo: Seo;
}) {
    const f = useForm({
        sla: Object.fromEntries(sla.map((s) => [s.priority, { first: s.first, resolve: s.resolve }])) as Record<
            string,
            { first: number; resolve: number }
        >,
    });
    const e = f.errors as Record<string, string | undefined>;
    return (
        <AdminLayout
            title="Support settings"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/support">Back to inbox</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="max-w-4xl space-y-6">
                <Card className="p-5">
                    <h2 className="font-semibold">Email</h2>
                    <p className="mt-1 text-sm">
                        {supportAddress
                            ? `Emails to ${supportAddress} become tickets, and customers can reply to any support email.`
                            : 'Set up reply-by-email (the inbound email domain) so customers can email support and reply to tickets by email. Until then they reply from the ticket page.'}
                    </p>
                </Card>
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Response-time targets (hours)</h2>
                    <div className="grid gap-3 sm:grid-cols-4">
                        {sla.map((s) => (
                            <div key={s.priority} className="space-y-2">
                                <div className="text-sm font-medium">{s.label}</div>
                                <Field label="First reply" error={e[`sla.${s.priority}.first`]}>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={f.data.sla[s.priority]?.first ?? s.first}
                                        onChange={(ev) =>
                                            f.setData('sla', {
                                                ...f.data.sla,
                                                [s.priority]: { ...f.data.sla[s.priority]!, first: Number(ev.target.value) },
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="Solved" error={e[`sla.${s.priority}.resolve`]}>
                                    <Input
                                        type="number"
                                        min={1}
                                        value={f.data.sla[s.priority]?.resolve ?? s.resolve}
                                        onChange={(ev) =>
                                            f.setData('sla', {
                                                ...f.data.sla,
                                                [s.priority]: { ...f.data.sla[s.priority]!, resolve: Number(ev.target.value) },
                                            })
                                        }
                                    />
                                </Field>
                            </div>
                        ))}
                    </div>
                    <Button size="sm" loading={f.processing} onClick={() => f.post('/admin/support/settings/sla', { preserveScroll: true })}>
                        Save targets
                    </Button>
                </Card>
                <section className="space-y-3">
                    <h2 className="font-semibold">Saved replies</h2>
                    {macros.map((m) => (
                        <MacroForm key={m.id} m={m} />
                    ))}
                    <MacroForm m={null} />
                </section>
            </div>
        </AdminLayout>
    );
}
