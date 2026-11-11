import { router, useForm, usePage } from '@inertiajs/react';
import { CalendarPlus, MapPin, Video } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Ev = {
    id: number;
    title: string;
    network: string;
    kind: string;
    when: string;
    past: boolean;
    cancelled: boolean;
    going: number;
    capacity: number | null;
    mine: string | null;
};
type Props = { events: Ev[]; ownedGroups: { slug: string; name: string }[]; timezone: string; status?: string; seo: Seo };

function CreateForm({ groups, timezone }: { groups: { slug: string; name: string }[]; timezone: string }) {
    const [group, setGroup] = useState(groups[0]?.slug ?? '');
    const f = useForm({
        title: '',
        description: '',
        kind: 'virtual',
        location: '',
        video_url: '',
        starts_at: '',
        minutes: '60',
        timezone,
        capacity: '',
        repeat: '',
        count: '6',
    });
    return (
        <Card className="space-y-3 p-5">
            <h2 className="flex items-center gap-2 font-semibold">
                <CalendarPlus className="size-5" aria-hidden /> New event
            </h2>
            <div className="grid gap-3 sm:grid-cols-2">
                {groups.length > 1 && (
                    <Field label="Network">
                        <select
                            className="h-10 w-full rounded-md border border-line bg-surface px-2"
                            value={group}
                            onChange={(e) => setGroup(e.target.value)}
                        >
                            {groups.map((g) => (
                                <option key={g.slug} value={g.slug}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Title" error={f.errors.title}>
                    <Input value={f.data.title} onChange={(e) => f.setData('title', e.target.value)} />
                </Field>
                <Field label="Type">
                    <select
                        className="h-10 w-full rounded-md border border-line bg-surface px-2"
                        value={f.data.kind}
                        onChange={(e) => f.setData('kind', e.target.value)}
                    >
                        <option value="virtual">Online</option>
                        <option value="in_person">In person</option>
                    </select>
                </Field>
                {f.data.kind === 'virtual' ? (
                    <Field label="Video link" hint="Shown only to people going, shortly before the start." error={f.errors.video_url}>
                        <Input value={f.data.video_url} onChange={(e) => f.setData('video_url', e.target.value)} placeholder="https://" />
                    </Field>
                ) : (
                    <>
                        <Field label="Location" error={f.errors.location}>
                            <Input value={f.data.location} onChange={(e) => f.setData('location', e.target.value)} />
                        </Field>
                        <Field label="Capacity (optional)" hint="Extra people join a waitlist.">
                            <Input type="number" min="1" value={f.data.capacity} onChange={(e) => f.setData('capacity', e.target.value)} />
                        </Field>
                    </>
                )}
                <Field label={`Starts (${f.data.timezone.replace('_', ' ')})`} error={f.errors.starts_at}>
                    <Input type="datetime-local" value={f.data.starts_at} onChange={(e) => f.setData('starts_at', e.target.value)} />
                </Field>
                <Field label="Length (minutes)">
                    <Input type="number" min="15" value={f.data.minutes} onChange={(e) => f.setData('minutes', e.target.value)} />
                </Field>
                <Field label="Repeat">
                    <select
                        className="h-10 w-full rounded-md border border-line bg-surface px-2"
                        value={f.data.repeat}
                        onChange={(e) => f.setData('repeat', e.target.value)}
                    >
                        <option value="">Doesn’t repeat</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly (same weekday, e.g. first Tuesday)</option>
                    </select>
                </Field>
                {f.data.repeat && (
                    <Field label="How many dates">
                        <Input type="number" min="2" max="12" value={f.data.count} onChange={(e) => f.setData('count', e.target.value)} />
                    </Field>
                )}
                <div className="sm:col-span-2">
                    <Field label="Description (optional)">
                        <Input value={f.data.description} onChange={(e) => f.setData('description', e.target.value)} />
                    </Field>
                </div>
            </div>
            <Button loading={f.processing} disabled={!f.data.title || !f.data.starts_at} onClick={() => f.post(`/app/events/${group}`)}>
                Create event
            </Button>
        </Card>
    );
}

/** Portal → Events: your networks’ meetups, in your own time zone. */
export default function Events({ events, ownedGroups, timezone, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Events" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {ownedGroups.length > 0 && <CreateForm groups={ownedGroups} timezone={timezone} />}
                {events.length === 0 && <Card className="p-6 text-center text-muted">No upcoming events in your networks.</Card>}
                {events.map((e) => (
                    <Card key={e.id} className={`flex flex-wrap items-center gap-3 p-4 ${e.past || e.cancelled ? 'opacity-70' : ''}`}>
                        {e.kind === 'virtual' ? (
                            <Video className="size-5 text-primary" aria-hidden />
                        ) : (
                            <MapPin className="size-5 text-primary" aria-hidden />
                        )}
                        <div className="min-w-0 flex-1">
                            <a href={`/app/events/${e.id}`} className="font-semibold hover:underline">
                                {e.title}
                            </a>
                            <p className="text-sm text-muted">
                                {e.when} · {e.network}
                            </p>
                        </div>
                        {e.cancelled && <Badge tone="danger">Cancelled</Badge>}
                        {e.mine && !e.cancelled && (
                            <Badge tone={e.mine === 'going' ? 'success' : 'neutral'}>{e.mine === 'waitlist' ? 'Waitlist' : e.mine}</Badge>
                        )}
                        <span className="text-sm text-muted">
                            {e.going} going{e.capacity ? ` of ${e.capacity}` : ''}
                        </span>
                        {!e.past && !e.cancelled && e.mine !== 'going' && (
                            <Button size="sm" onClick={() => router.post(`/app/events/${e.id}/rsvp`, { status: 'going' }, { preserveScroll: true })}>
                                I’m going
                            </Button>
                        )}
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
