import { useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Provider = {
    key: string;
    label: string;
    enabled: boolean;
    ready: boolean;
    connections: number;
    needsAttention: number;
    ok: number;
    failed: number;
};
type Props = { providers: Provider[]; fub: { system: string | null; key: string | null }; status?: string; seo: Seo };

/** Admin → Integrations: switch each CRM on or off, the Follow Up Boss registration, and how syncing is going. */
export default function AdminIntegrations({ providers, fub, status, seo }: Props) {
    const f = useForm({
        enabled: Object.fromEntries(providers.map((p) => [p.key, p.enabled])) as Record<string, boolean>,
        fub_system: fub.system ?? '',
        fub_system_key: '',
    });
    return (
        <AdminLayout title="Integrations">
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="overflow-x-auto p-5">
                    <h2 className="mb-3 font-semibold">CRM connectors</h2>
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs text-muted">
                            <tr>
                                <th className="py-1 pr-3 font-medium">CRM</th>
                                <th className="py-1 pr-3 font-medium">On</th>
                                <th className="py-1 pr-3 font-medium">Teams connected</th>
                                <th className="py-1 pr-3 font-medium">Need attention</th>
                                <th className="py-1 font-medium">Last 24 hours</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {providers.map((p) => (
                                <tr key={p.key}>
                                    <td className="py-2 pr-3">
                                        {p.label} {!p.ready && <Badge tone="warning">needs setup</Badge>}
                                    </td>
                                    <td className="py-2 pr-3">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            aria-label={`${p.label} on`}
                                            checked={f.data.enabled[p.key] ?? false}
                                            onChange={(e) => f.setData('enabled', { ...f.data.enabled, [p.key]: e.target.checked })}
                                        />
                                    </td>
                                    <td className="py-2 pr-3">{p.connections}</td>
                                    <td className="py-2 pr-3">{p.needsAttention > 0 ? <Badge tone="warning">{p.needsAttention}</Badge> : 0}</td>
                                    <td className="py-2">
                                        {p.ok} synced{p.failed > 0 ? `, ${p.failed} failed` : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="mt-2 text-xs text-muted">
                        Switching a CRM off stops new syncs for every team; their connections and logs are kept.
                    </p>
                </Card>
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Follow Up Boss registration</h2>
                    <p className="text-sm text-muted">
                        Follow Up Boss asks every platform that sends leads to register at apps.followupboss.com/system-registration. Enter the system
                        name and system key it gives you. Until both are saved, members can’t connect Follow Up Boss.
                    </p>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="System name (X-System)" error={f.errors.fub_system}>
                            <Input value={f.data.fub_system} onChange={(e) => f.setData('fub_system', e.target.value)} maxLength={100} />
                        </Field>
                        <Field
                            label="System key (X-System-Key)"
                            hint={fub.key ? `Saved: ${fub.key}. Leave blank to keep it.` : 'Stored encrypted.'}
                            error={f.errors.fub_system_key}
                        >
                            <Input
                                type="password"
                                autoComplete="off"
                                value={f.data.fub_system_key}
                                onChange={(e) => f.setData('fub_system_key', e.target.value)}
                            />
                        </Field>
                    </div>
                </Card>
                <Button
                    loading={f.processing}
                    onClick={() => f.post('/admin/integrations', { preserveScroll: true, onSuccess: () => f.reset('fub_system_key') })}
                >
                    Save
                </Button>
            </div>
        </AdminLayout>
    );
}
