import { router, useForm } from '@inertiajs/react';
import { Copy, Send, Webhook } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';

export type Endpoint = {
    id: string;
    url: string;
    description: string | null;
    mode: 'live' | 'test';
    events: string[];
    active: boolean;
    disabled_reason: string | null;
    failing_since: string | null;
    last_success_at: string | null;
    created_at: string;
    overLimit: boolean;
    lastDay: { succeeded: number; failed: number };
};
export type Delivery = {
    id: string;
    event_id: string;
    event_type: string;
    status: 'pending' | 'sending' | 'retrying' | 'succeeded' | 'failed';
    attempts: number;
    next_attempt_at: string | null;
    response_status: number | null;
    response_body: string | null;
    error: string | null;
    duration_ms: number | null;
    created_at: string;
    payload: unknown;
    endpoint: string;
    url: string;
};
export type Plan = {
    on: boolean;
    price: number;
    perMinute: number;
    basePerMinute: number;
    endpoints: number;
    baseEndpoints: number;
    endpointLimit: number;
};
export type LogRow = { method: string; path: string; status: number; ms: number; id: string; at: string; token: string };

const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '');
const tone = (s: Delivery['status']) => (s === 'succeeded' ? 'success' : s === 'failed' ? 'danger' : s === 'retrying' ? 'warning' : 'neutral');

/** The API plan: what it adds, and a link to switch it on or off in Billing. */
export function PlanCard({ plan }: { plan: Plan }) {
    return (
        <Card className="space-y-1 p-5 text-sm">
            <h2 className="font-semibold">API plan {plan.on ? <Badge tone="success">on</Badge> : <Badge>off</Badge>}</h2>
            <p className="text-muted">
                Included with your seat: {plan.basePerMinute} requests a minute per token and {plan.baseEndpoints} webhook endpoint. The API plan ($
                {plan.price.toFixed(2)}
                /month) raises that to {plan.perMinute} a minute and {plan.endpoints} endpoints, and adds a 30-day request log.{' '}
                <a href="/app/billing" className="font-medium text-primary">
                    {plan.on ? 'Manage in Billing' : 'Switch it on in Billing'}
                </a>
            </p>
        </Card>
    );
}

/** A secret shown once, after adding an endpoint or rotating its secret. */
export function NewSecret({ secret }: { secret: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <Card className="space-y-2 border-primary p-5">
            <h2 className="font-semibold">Signing secret</h2>
            <p className="text-sm text-muted">Use it to check that webhooks come from us (see the docs). Copy it now: it won’t be shown again.</p>
            <code className="block rounded-md bg-bg p-3 text-sm break-all">{secret}</code>
            <Button size="sm" variant="secondary" onClick={() => void navigator.clipboard.writeText(secret).then(() => setCopied(true))}>
                <Copy /> {copied ? 'Copied' : 'Copy secret'}
            </Button>
        </Card>
    );
}

/** Webhook endpoints: add, test, switch off or on, rotate the secret, delete. */
export function WebhookEndpoints({ endpoints, eventTypes, plan }: { endpoints: Endpoint[]; eventTypes: Record<string, string>; plan: Plan }) {
    const f = useForm<{ url: string; mode: 'live' | 'test'; description: string; events: string[] }>({
        url: '',
        mode: 'test',
        description: '',
        events: ['*'],
    });
    const all = f.data.events.includes('*');
    const toggle = (e: string) => {
        const base = all ? [] : f.data.events;
        const next = base.includes(e) ? base.filter((x) => x !== e) : [...base, e];
        f.setData('events', next.length === 0 ? ['*'] : next);
    };
    const full = endpoints.length >= plan.endpointLimit;
    return (
        <Card className="space-y-4 p-5">
            <div>
                <h2 className="font-semibold">Webhooks</h2>
                <p className="text-sm text-muted">
                    We POST each referral change (and each new profile lead) to your address, signed with your secret, and retry for about 24 hours if
                    it fails. Test endpoints get events from the sandbox. Read{' '}
                    <a href="/developers#webhooks" className="font-medium text-primary">
                        how to check signatures
                    </a>
                    .
                </p>
            </div>
            <ul className="divide-y divide-line">
                {endpoints.map((e) => (
                    <li key={e.id} className="space-y-1 py-3 text-sm">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <p className="font-medium break-all">
                                    {e.url} <Badge tone={e.mode === 'live' ? 'success' : 'info'}>{e.mode}</Badge>{' '}
                                    {!e.active && <Badge tone="danger">off</Badge>}{' '}
                                    {e.overLimit && <Badge tone="warning">paused: over your plan’s endpoint limit</Badge>}
                                </p>
                                <p className="text-muted">
                                    {e.description ? `${e.description} · ` : ''}
                                    {e.events.includes('*') ? 'all events' : e.events.join(', ')} · last 24 hours: {e.lastDay.succeeded} delivered,{' '}
                                    {e.lastDay.failed} failed
                                    {e.failing_since && e.active ? ` · failing since ${when(e.failing_since)}` : ''}
                                </p>
                                {e.disabled_reason && <p className="text-danger">{e.disabled_reason}</p>}
                            </div>
                            <div className="flex flex-wrap gap-1">
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    disabled={!e.active}
                                    onClick={() => router.post(`/app/developers/webhooks/${e.id}/test`, {}, { preserveScroll: true })}
                                >
                                    <Send /> Send test event
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => router.patch(`/app/developers/webhooks/${e.id}`, { active: !e.active }, { preserveScroll: true })}
                                >
                                    {e.active ? 'Switch off' : 'Switch on'}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Make a new signing secret? The old one keeps working for 24 hours, so you can switch over.',
                                            )
                                        ) {
                                            router.post(`/app/developers/webhooks/${e.id}/rotate`, {}, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    New secret
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        if (window.confirm(`Delete ${e.url}? Its delivery log is deleted too.`)) {
                                            router.delete(`/app/developers/webhooks/${e.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    Delete
                                </Button>
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
            {full ? (
                <p className="text-sm text-muted">
                    You have {endpoints.length} of {plan.endpointLimit} endpoints.{' '}
                    {!plan.on && (
                        <a href="/app/billing" className="font-medium text-primary">
                            The API plan allows {plan.endpoints}.
                        </a>
                    )}
                </p>
            ) : (
                <div className="space-y-3 border-t border-line pt-4">
                    <h3 className="text-sm font-semibold">Add an endpoint</h3>
                    <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <Field label="Address (https)" error={f.errors.url}>
                            <Input
                                value={f.data.url}
                                onChange={(e) => f.setData('url', e.target.value)}
                                placeholder="https://example.com/t2t-webhooks"
                                maxLength={500}
                            />
                        </Field>
                        <fieldset>
                            <legend className="mb-1.5 text-sm font-medium">Mode</legend>
                            <div className="flex gap-3 text-sm">
                                {(['test', 'live'] as const).map((m) => (
                                    <label key={m} className="flex items-center gap-1.5">
                                        <input type="radio" name="hook-mode" checked={f.data.mode === m} onChange={() => f.setData('mode', m)} />{' '}
                                        {m === 'test' ? 'Test' : 'Live'}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                    </div>
                    <Field label="Description (optional)" error={f.errors.description}>
                        <Input value={f.data.description} onChange={(e) => f.setData('description', e.target.value)} maxLength={120} />
                    </Field>
                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium">Events</legend>
                        <label className="mb-2 flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4" checked={all} onChange={() => f.setData('events', all ? [] : ['*'])} /> All
                            events, including new ones
                        </label>
                        {!all && (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {Object.entries(eventTypes).map(([k, label]) => (
                                    <label key={k} className="flex items-start gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="mt-1 size-4"
                                            checked={f.data.events.includes(k)}
                                            onChange={() => toggle(k)}
                                        />
                                        <span>
                                            <code className="text-xs">{k}</code>
                                            <span className="block text-muted">{label}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        )}
                        {f.errors.events && <p className="mt-1 text-sm text-danger">{f.errors.events}</p>}
                    </fieldset>
                    <Button
                        loading={f.processing}
                        onClick={() => f.post('/app/developers/webhooks', { preserveScroll: true, onSuccess: () => f.reset('url', 'description') })}
                    >
                        <Webhook /> Add endpoint
                    </Button>
                </div>
            )}
        </Card>
    );
}

/** The last 50 deliveries, with what we sent and what your endpoint answered. */
export function DeliveryLog({ deliveries }: { deliveries: Delivery[] }) {
    const [open, setOpen] = useState<string | null>(null);
    return (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">Webhook deliveries</h2>
            {deliveries.length === 0 ? (
                <p className="text-sm text-muted">Nothing sent yet. Use “Send test event” to try an endpoint.</p>
            ) : (
                <ul className="divide-y divide-line text-sm">
                    {deliveries.map((d) => (
                        <li key={d.id} className="py-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <button
                                    type="button"
                                    className="min-w-0 text-left"
                                    onClick={() => setOpen(open === d.id ? null : d.id)}
                                    aria-expanded={open === d.id}
                                >
                                    <code className="text-xs">{d.event_type}</code> <Badge tone={tone(d.status)}>{d.status}</Badge>{' '}
                                    <span className="text-muted">
                                        {when(d.created_at)} · {d.response_status ? `HTTP ${d.response_status}` : (d.error ?? 'waiting')} ·{' '}
                                        {d.attempts} {d.attempts === 1 ? 'attempt' : 'attempts'}
                                        {d.status === 'retrying' && d.next_attempt_at ? ` · next try ${when(d.next_attempt_at)}` : ''}
                                    </span>
                                </button>
                                {(d.status === 'succeeded' || d.status === 'failed') && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.post(`/app/developers/deliveries/${d.id}/resend`, {}, { preserveScroll: true })}
                                    >
                                        Send again
                                    </Button>
                                )}
                            </div>
                            {open === d.id && (
                                <div className="mt-2 space-y-2">
                                    <p className="text-xs break-all text-muted">
                                        To {d.url} · event {d.event_id}
                                        {d.duration_ms !== null ? ` · ${d.duration_ms} ms` : ''}
                                    </p>
                                    {d.error && <p className="text-danger">{d.error}</p>}
                                    <pre className="max-h-64 overflow-auto rounded-md bg-bg p-3 text-xs">{JSON.stringify(d.payload, null, 2)}</pre>
                                    {d.response_body && (
                                        <pre className="max-h-32 overflow-auto rounded-md bg-bg p-3 text-xs whitespace-pre-wrap">
                                            {d.response_body}
                                        </pre>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}

/** API plan: the last 100 requests (method, path, status, time). */
export function RequestLog({ logs }: { logs: LogRow[] }) {
    return (
        <Card className="space-y-3 p-5">
            <h2 className="font-semibold">Request log (last 30 days)</h2>
            {logs.length === 0 ? (
                <p className="text-sm text-muted">No requests yet.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="text-xs text-muted">
                            <tr>
                                <th className="py-1 pr-3 font-medium">When</th>
                                <th className="py-1 pr-3 font-medium">Request</th>
                                <th className="py-1 pr-3 font-medium">Status</th>
                                <th className="py-1 pr-3 font-medium">Time</th>
                                <th className="py-1 font-medium">Token</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {logs.map((l) => (
                                <tr key={l.id} title={l.id}>
                                    <td className="py-1.5 pr-3 whitespace-nowrap">{when(l.at)}</td>
                                    <td className="py-1.5 pr-3 break-all">
                                        <code className="text-xs">
                                            {l.method} {l.path}
                                        </code>
                                    </td>
                                    <td className="py-1.5 pr-3">
                                        <Badge tone={l.status < 400 ? 'success' : l.status < 500 ? 'warning' : 'danger'}>{l.status}</Badge>
                                    </td>
                                    <td className="py-1.5 pr-3 whitespace-nowrap">{l.ms} ms</td>
                                    <td className="py-1.5">{l.token}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Card>
    );
}
