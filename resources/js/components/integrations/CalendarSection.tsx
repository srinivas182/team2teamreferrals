import { router, useForm } from '@inertiajs/react';
import { CalendarDays, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select } from '@/components/ui/field';

export type CalendarConn = {
    id: string;
    provider: string;
    label: string;
    status: 'active' | 'error';
    email: string | null;
    calendarId: string;
    calendarName: string | null;
    kinds: string[];
    lastError: string | null;
    lastSynced: string | null;
    items: number;
};
export type CalendarProps = {
    link: string | null;
    kinds: Record<string, string>;
    providers: { key: string; label: string; ready: boolean }[];
    connections: CalendarConn[];
};

/** Integrations → Your calendar: the private calendar link, and Google or Microsoft calendar sync (each person their own). */
export function CalendarSection({ calendar }: { calendar: CalendarProps }) {
    const [copied, setCopied] = useState(false);
    const connected = new Set(calendar.connections.map((c) => c.provider));
    return (
        <Card className="space-y-4 p-5">
            <div>
                <h2 className="font-semibold">Your calendar</h2>
                <p className="text-sm text-muted">
                    Events you’re going to, your team’s referral deadlines (respond by, first contact, next update) and check-ins for future leads,
                    kept up to date in your own calendar. This is just for you: each person on your team can set up their own.
                </p>
            </div>
            {calendar.connections.map((c) => (
                <Connected key={c.id} c={c} kinds={calendar.kinds} />
            ))}
            <div className="flex flex-wrap gap-2">
                {calendar.providers
                    .filter((p) => p.ready && !connected.has(p.key))
                    .map((p) => (
                        // The server answers with Google's or Microsoft's sign-in page, where the person approves access.
                        <Button key={p.key} variant="secondary" onClick={() => router.post(`/app/calendar/${p.key}/connect`)}>
                            <CalendarDays /> Connect {p.label}
                        </Button>
                    ))}
            </div>
            <div className="space-y-2 border-t border-line pt-4 text-sm">
                <h3 className="font-semibold">Calendar link (Apple Calendar and any other app)</h3>
                {calendar.link ? (
                    <>
                        <p className="text-muted">
                            Subscribe to this private address in your calendar app (in Apple Calendar: File → New Calendar Subscription). Most apps
                            refresh it every hour or so. Anyone with the link can see these items, so keep it private.
                        </p>
                        <code className="block rounded-md bg-bg p-3 text-xs break-all">{calendar.link}</code>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={() => void navigator.clipboard.writeText(calendar.link ?? '').then(() => setCopied(true))}
                            >
                                <Copy /> {copied ? 'Copied' : 'Copy link'}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => {
                                    if (window.confirm('Make a new link? The old one stops working.'))
                                        router.post('/app/calendar/link', {}, { preserveScroll: true });
                                }}
                            >
                                New link
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => router.delete('/app/calendar/link', { preserveScroll: true })}>
                                Turn off
                            </Button>
                        </div>
                    </>
                ) : (
                    <Button size="sm" variant="secondary" onClick={() => router.post('/app/calendar/link', {}, { preserveScroll: true })}>
                        Get my calendar link
                    </Button>
                )}
            </div>
        </Card>
    );
}

function Connected({ c, kinds }: { c: CalendarConn; kinds: Record<string, string> }) {
    const f = useForm({ calendar_id: c.calendarId, kinds: c.kinds });
    const [calendars, setCalendars] = useState<{ id: string; name: string; primary: boolean }[] | null>(null);
    useEffect(() => {
        if (c.status !== 'active') return;
        let live = true;
        void fetch(`/app/calendar/${c.id}/calendars`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : []))
            .then((list: { id: string; name: string; primary: boolean }[]) => {
                if (live) setCalendars(list);
            });
        return () => {
            live = false;
        };
    }, [c.id, c.status]);
    const toggle = (k: string) => f.setData('kinds', f.data.kinds.includes(k) ? f.data.kinds.filter((x) => x !== k) : [...f.data.kinds, k]);
    return (
        <div className="space-y-3 rounded-md border border-line p-4 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {c.label} {c.status === 'active' ? <Badge tone="success">connected</Badge> : <Badge tone="danger">connect again</Badge>}
                    </p>
                    <p className="text-muted">
                        {c.email ? `${c.email} · ` : ''}
                        {c.items} items · {c.lastSynced ? `updated ${new Date(c.lastSynced).toLocaleString()}` : 'not synced yet'}
                    </p>
                    {c.lastError && <p className="text-danger">{c.lastError}</p>}
                </div>
                <div className="flex flex-wrap gap-1">
                    {c.status === 'active' && (
                        <Button size="sm" variant="secondary" onClick={() => router.post(`/app/calendar/${c.id}/sync`, {}, { preserveScroll: true })}>
                            Sync now
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            if (window.confirm(`Disconnect ${c.label}? The items we added are removed from it.`))
                                router.delete(`/app/calendar/${c.id}`, { preserveScroll: true });
                        }}
                    >
                        Disconnect
                    </Button>
                </div>
            </div>
            {c.status === 'active' && (
                <>
                    <label className="block">
                        <span className="mb-1 block font-medium">Add to</span>
                        <Select value={f.data.calendar_id} onChange={(e) => f.setData('calendar_id', e.target.value)} disabled={calendars === null}>
                            <option value={c.calendarId}>
                                {c.calendarName ?? (c.calendarId === 'primary' ? 'Your main calendar' : c.calendarId)}
                            </option>
                            {(calendars ?? [])
                                .filter((x) => x.id !== c.calendarId && !(c.calendarId === 'primary' && x.primary))
                                .map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.name}
                                        {x.primary ? ' (main)' : ''}
                                    </option>
                                ))}
                        </Select>
                    </label>
                    <fieldset className="space-y-1">
                        <legend className="mb-1 font-medium">What to add</legend>
                        {Object.entries(kinds).map(([k, label]) => (
                            <label key={k} className="flex items-center gap-2">
                                <input type="checkbox" className="size-4" checked={f.data.kinds.includes(k)} onChange={() => toggle(k)} /> {label}
                            </label>
                        ))}
                    </fieldset>
                    <Button size="sm" loading={f.processing} onClick={() => f.patch(`/app/calendar/${c.id}`, { preserveScroll: true })}>
                        Save
                    </Button>
                </>
            )}
        </div>
    );
}
