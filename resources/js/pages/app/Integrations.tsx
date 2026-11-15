import { router, useForm, usePage } from '@inertiajs/react';
import { Plug, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { CalendarSection } from '@/components/integrations/CalendarSection';
import type { CalendarProps } from '@/components/integrations/CalendarSection';
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

type Provider = { key: string; label: string; keyHelp: string; available: boolean };
type Connection = {
    id: string;
    provider: string;
    label: string;
    status: 'active' | 'paused' | 'error';
    account: string | null;
    lastError: string | null;
    lastSynced: string | null;
    connectedAt: string;
    settings: { sync_sent: boolean; sync_notes: boolean; assign_user_id: string | null };
    users: { id: string; name: string }[];
    stages: string[];
    map: Record<string, string>;
    lastDay: Record<string, number>;
};
type LocalStage = { key: string; label: string; kind: 'open' | 'won' | 'lost' };
type Log = {
    id: number;
    provider: string;
    direction: 'in' | 'out';
    action: string;
    status: string;
    attempts: number;
    message: string | null;
    nextAttempt: string | null;
    at: string;
    referral: string | null;
    client: string | null;
};
type Props = {
    providers: Provider[];
    connections: Connection[];
    localStages: LocalStage[];
    logs: Log[];
    canManage: boolean;
    calendar: CalendarProps;
    status?: string;
    seo: Seo;
};

const STATUS: Record<Connection['status'], { tone: 'success' | 'warning' | 'danger'; text: string }> = {
    active: { tone: 'success', text: 'connected' },
    paused: { tone: 'warning', text: 'paused' },
    error: { tone: 'danger', text: 'needs a new key' },
};
const logTone = (s: string) => (s === 'ok' ? 'success' : s === 'failed' ? 'danger' : s === 'retrying' ? 'warning' : 'neutral');

/** Portal → Integrations: connect Follow Up Boss, BoldTrail or Lofty; map stages; see what was synced. */
export default function Integrations({ providers, connections, localStages, logs, canManage, calendar, status, seo }: Props) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    const connected = new Set(connections.map((c) => c.provider));
    return (
        <PortalLayout title="Integrations" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {errors.integration && <Alert tone="danger">{errors.integration}</Alert>}
                {errors.calendar && <Alert tone="danger">{errors.calendar}</Alert>}
                <Card className="space-y-1 p-5 text-sm">
                    <p>
                        Connect your CRM and every referral your team accepts appears there within a minute, tagged “Team to Team Referrals”, with the
                        client’s details and a summary. When you move the contact to another stage in your CRM, the referral follows here.
                    </p>
                    <p className="text-muted">
                        Client details are only sent after your team accepts a referral. For other tools, use{' '}
                        <a href="/app/developers" className="font-medium text-primary">
                            webhooks and the API
                        </a>
                        .
                    </p>
                </Card>
                {connections.map((c) => (
                    <ConnectionCard key={c.id} c={c} localStages={localStages} canManage={canManage} />
                ))}
                {canManage && providers.filter((p) => !connected.has(p.key)).map((p) => <ConnectCard key={p.key} p={p} />)}
                <CalendarSection calendar={calendar} />
                {canManage && <SyncLog logs={logs} />}
            </div>
        </PortalLayout>
    );
}

function ConnectCard({ p }: { p: Provider }) {
    const f = useForm({ provider: p.key, api_key: '', sync_sent: false, sync_notes: true, backfill: true });
    return (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">{p.label}</h2>
            {!p.available ? (
                <p className="text-sm text-muted">Coming soon.</p>
            ) : (
                <>
                    <Field label="API key" hint={p.keyHelp} error={f.errors.api_key}>
                        <Input type="password" autoComplete="off" value={f.data.api_key} onChange={(e) => f.setData('api_key', e.target.value)} />
                    </Field>
                    <div className="space-y-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={f.data.backfill}
                                onChange={(e) => f.setData('backfill', e.target.checked)}
                            />{' '}
                            Also send the open referrals you’ve already accepted
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={f.data.sync_notes}
                                onChange={(e) => f.setData('sync_notes', e.target.checked)}
                            />{' '}
                            Copy updates posted on a referral as notes
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={f.data.sync_sent}
                                onChange={(e) => f.setData('sync_sent', e.target.checked)}
                            />{' '}
                            Also add clients my team refers out
                        </label>
                    </div>
                    <Button loading={f.processing} onClick={() => f.post('/app/integrations', { preserveScroll: true })}>
                        <Plug /> Connect {p.label}
                    </Button>
                </>
            )}
        </Card>
    );
}

function ConnectionCard({ c, localStages, canManage }: { c: Connection; localStages: LocalStage[]; canManage: boolean }) {
    const [newKey, setNewKey] = useState(false);
    const key = useForm({ provider: c.provider, api_key: '' });
    const s = useForm<{ sync_sent: boolean; sync_notes: boolean; assign_user_id: string | null; map: Record<string, string> }>({
        ...c.settings,
        map: Object.fromEntries(c.stages.map((st) => [st, c.map[st] ?? ''])),
    });
    const [extra, setExtra] = useState('');
    const st = STATUS[c.status];
    return (
        <Card className="space-y-4 p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 className="font-semibold">
                        {c.label} <Badge tone={st.tone}>{st.text}</Badge>
                    </h2>
                    <p className="text-sm text-muted">
                        {c.account ? `${c.account} · ` : ''}
                        {c.lastSynced ? `last synced ${new Date(c.lastSynced).toLocaleString()}` : 'nothing synced yet'} · last 24 hours:{' '}
                        {c.lastDay.ok ?? 0} synced
                        {(c.lastDay.failed ?? 0) > 0 ? `, ${c.lastDay.failed} failed` : ''}
                    </p>
                    {c.lastError && <p className="text-sm text-danger">{c.lastError}</p>}
                </div>
                {canManage && (
                    <div className="flex flex-wrap gap-1">
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => router.post(`/app/integrations/${c.id}/test`, {}, { preserveScroll: true })}
                        >
                            Test connection
                        </Button>
                        {(c.lastDay.failed ?? 0) > 0 && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => router.post(`/app/integrations/${c.id}/retry`, {}, { preserveScroll: true })}
                            >
                                <RefreshCw /> Try failed again
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                router.post(`/app/integrations/${c.id}/pause`, { paused: c.status !== 'paused' }, { preserveScroll: true })
                            }
                        >
                            {c.status === 'paused' ? 'Resume' : 'Pause'}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setNewKey(!newKey)}>
                            New key
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                if (window.confirm(`Disconnect ${c.label}? Contacts already in it stay there.`)) {
                                    router.delete(`/app/integrations/${c.id}`, { preserveScroll: true });
                                }
                            }}
                        >
                            Disconnect
                        </Button>
                    </div>
                )}
            </div>
            {canManage && newKey && (
                <div className="flex flex-wrap items-end gap-2">
                    <Field label="New API key" error={key.errors.api_key} className="min-w-64 flex-1">
                        <Input type="password" autoComplete="off" value={key.data.api_key} onChange={(e) => key.setData('api_key', e.target.value)} />
                    </Field>
                    <Button
                        loading={key.processing}
                        onClick={() => key.post('/app/integrations', { preserveScroll: true, onSuccess: () => setNewKey(false) })}
                    >
                        Save key
                    </Button>
                </div>
            )}
            {canManage && (
                <div className="space-y-4 border-t border-line pt-4">
                    <div className="space-y-1 text-sm">
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={s.data.sync_notes}
                                onChange={(e) => s.setData('sync_notes', e.target.checked)}
                            />{' '}
                            Copy updates posted on a referral as notes
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={s.data.sync_sent}
                                onChange={(e) => s.setData('sync_sent', e.target.checked)}
                            />{' '}
                            Also add clients my team refers out
                        </label>
                    </div>
                    {c.users.length > 0 && (
                        <Field label="Assign new referrals to" hint="Leave as “Your lead routing” to use your CRM’s own rules.">
                            <Select
                                value={s.data.assign_user_id ?? ''}
                                onChange={(e) => s.setData('assign_user_id', e.target.value === '' ? null : e.target.value)}
                            >
                                <option value="">Your lead routing</option>
                                {c.users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <fieldset>
                        <legend className="text-sm font-medium">When a contact moves to a stage in {c.label}…</legend>
                        <p className="mb-2 text-xs text-muted">
                            …move the referral here to the stage you pick. A won stage asks you to record the close (it needs the date and value).
                            Stages appear here as your CRM uses them; you can also add one.
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {Object.keys(s.data.map).map((remote) => (
                                <label key={remote} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="truncate">“{remote}”</span>
                                    <Select
                                        className="w-48"
                                        aria-label={`Stage for ${remote}`}
                                        value={s.data.map[remote]}
                                        onChange={(e) => s.setData('map', { ...s.data.map, [remote]: e.target.value })}
                                    >
                                        <option value="">Leave as is</option>
                                        {localStages.map((l) => (
                                            <option key={l.key} value={l.key}>
                                                {l.label}
                                                {l.kind !== 'open' ? ` (${l.kind})` : ''}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                            ))}
                        </div>
                        <div className="mt-2 flex gap-2">
                            <Input
                                className="max-w-56"
                                placeholder="Add a CRM stage name"
                                value={extra}
                                onChange={(e) => setExtra(e.target.value)}
                                maxLength={100}
                            />
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={extra.trim() === ''}
                                onClick={() => {
                                    s.setData('map', { ...s.data.map, [extra.trim()]: s.data.map[extra.trim()] ?? '' });
                                    setExtra('');
                                }}
                            >
                                Add
                            </Button>
                        </div>
                    </fieldset>
                    <Button loading={s.processing} onClick={() => s.patch(`/app/integrations/${c.id}`, { preserveScroll: true })}>
                        Save settings
                    </Button>
                </div>
            )}
        </Card>
    );
}

function SyncLog({ logs }: { logs: Log[] }) {
    return (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">Sync log</h2>
            {logs.length === 0 ? (
                <p className="text-sm text-muted">Nothing synced yet.</p>
            ) : (
                <ul className="divide-y divide-line text-sm">
                    {logs.map((l) => (
                        <li key={l.id} className="py-2">
                            <Badge tone={logTone(l.status)}>{l.status}</Badge>{' '}
                            <span className="font-medium">
                                {l.direction === 'out' ? `To ${l.provider}` : `From ${l.provider}`}: {l.action}
                            </span>
                            {l.client && (
                                <>
                                    {' · '}
                                    <a href={`/app/referrals/${l.referral}`} className="text-primary">
                                        {l.client}
                                    </a>
                                </>
                            )}
                            <span className="text-muted">
                                {' · '}
                                {new Date(l.at).toLocaleString()}
                                {l.message ? ` · ${l.message}` : ''}
                                {l.status === 'retrying' && l.nextAttempt ? ` · next try ${new Date(l.nextAttempt).toLocaleString()}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
