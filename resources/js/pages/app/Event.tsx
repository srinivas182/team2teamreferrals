import { router, usePage, useForm } from '@inertiajs/react';
import { CalendarPlus, CheckCircle2, MapPin, Video } from 'lucide-react';
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

type Props = {
    event: {
        id: number;
        title: string;
        description: string | null;
        kind: string;
        location: string | null;
        cancelled: boolean;
        ended: boolean;
        notes: string | null;
        capacity: number | null;
        network: string;
        whenLocal: string;
        whenEvent: string;
        differentZone: boolean;
        videoUrl: string | null;
    };
    mine: string | null;
    checkedIn: boolean;
    attendees: { name: string; team: string | null; status: string; checkedIn: boolean }[];
    going: number;
    met: { team: string; profile: string }[];
    isOwner: boolean;
    checkInUrl: string | null;
    status?: string;
    seo: Seo;
};

const opts = { preserveScroll: true } as const;

/** One event: times in your zone, RSVP, the video link when it’s time, attendees, and afterwards who you met. */
export default function Event({ event: e, mine, checkedIn, attendees, going, met, isOwner, checkInUrl, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [notes, setNotes] = useState(e.notes ?? '');
    const rsvp = (s: string) => router.post(`/app/events/${e.id}/rsvp`, { status: s }, opts);
    return (
        <PortalLayout title={e.title} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {e.cancelled && <Alert tone="danger">This event has been cancelled.</Alert>}
                <Card className="space-y-3 p-5">
                    <p className="text-sm text-muted">{e.network}</p>
                    <p className="text-lg font-semibold">{e.whenLocal}</p>
                    {e.differentZone && <p className="text-sm text-muted">Event time: {e.whenEvent}</p>}
                    <p className="flex items-center gap-2 text-sm">
                        {e.kind === 'virtual' ? <Video className="size-4" aria-hidden /> : <MapPin className="size-4" aria-hidden />}
                        {e.kind === 'virtual' ? 'Online' : e.location}
                    </p>
                    {e.description && <p className="whitespace-pre-wrap">{e.description}</p>}
                    {e.videoUrl && (
                        <a
                            href={e.videoUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex h-10 items-center gap-2 rounded-md bg-primary px-4 text-sm font-semibold text-white"
                        >
                            <Video className="size-4" aria-hidden /> Join now
                        </a>
                    )}
                    {e.kind === 'virtual' && !e.videoUrl && !e.ended && !e.cancelled && (
                        <p className="text-xs text-muted">The video link appears here for people going, 15 minutes before the start.</p>
                    )}
                    {!e.ended && !e.cancelled && (
                        <div className="flex flex-wrap items-center gap-2" role="group" aria-label="RSVP">
                            {(
                                [
                                    ['going', 'Going'],
                                    ['maybe', 'Maybe'],
                                    ['no', 'Can’t go'],
                                ] as const
                            ).map(([s, l]) => (
                                <Button
                                    key={s}
                                    size="sm"
                                    variant={mine === s || (s === 'going' && mine === 'waitlist') ? 'primary' : 'secondary'}
                                    onClick={() => rsvp(s)}
                                >
                                    {l}
                                </Button>
                            ))}
                            {mine === 'waitlist' && <Badge tone="warning">You’re on the waitlist</Badge>}
                            <a href={`/app/events/${e.id}.ics`} className="ml-auto inline-flex items-center gap-1 text-sm font-medium text-primary">
                                <CalendarPlus className="size-4" aria-hidden /> Add to calendar
                            </a>
                        </div>
                    )}
                    {checkedIn && (
                        <p className="flex items-center gap-1 text-sm text-success">
                            <CheckCircle2 className="size-4" aria-hidden /> Checked in
                        </p>
                    )}
                </Card>
                {met.length > 0 && (
                    <Card className="space-y-2 p-5">
                        <h2 className="font-semibold">Who you met</h2>
                        <p className="text-sm text-muted">Follow up while it’s fresh: a referral is the best thank-you.</p>
                        <ul className="divide-y divide-line text-sm">
                            {met.map((m) => (
                                <li key={m.profile} className="flex items-center gap-2 py-2">
                                    <span className="flex-1 font-medium">{m.team}</span>
                                    <a href={m.profile} className="text-primary">
                                        View profile
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                {e.notes && !isOwner && (
                    <Card className="p-5">
                        <h2 className="mb-1 font-semibold">Notes from the event</h2>
                        <p className="text-sm whitespace-pre-wrap">{e.notes}</p>
                    </Card>
                )}
                <Card className="p-5">
                    <h2 className="mb-2 font-semibold">
                        Attendees · {going}
                        {e.capacity ? ` of ${e.capacity}` : ''} going
                    </h2>
                    <ul className="space-y-1 text-sm">
                        {attendees.map((a, i) => (
                            <li key={i} className="flex items-center gap-2">
                                <span>{a.name}</span>
                                {a.team && <span className="text-muted">· {a.team}</span>}
                                {a.status === 'waitlist' && <Badge tone="warning">Waitlist</Badge>}
                                {a.checkedIn && <Badge tone="success">Came</Badge>}
                            </li>
                        ))}
                    </ul>
                </Card>
                {isOwner && (
                    <Card className="space-y-3 p-5">
                        <h2 className="font-semibold">Owner tools</h2>
                        {checkInUrl && (
                            <div className="flex flex-wrap items-center gap-3">
                                <img src={`/app/events/${e.id}/qr.svg`} alt="Check-in QR code" className="size-32" />
                                <p className="text-sm text-muted">Show this at the door: attendees scan it to check in.</p>
                            </div>
                        )}
                        <div className="flex gap-2">
                            <Input
                                aria-label="Notes"
                                placeholder="Post-event notes (slides, takeaways, next date)"
                                value={notes}
                                onChange={(ev) => setNotes(ev.target.value)}
                            />
                            <Button
                                size="sm"
                                variant="secondary"
                                disabled={!notes.trim()}
                                onClick={() => router.post(`/app/events/${e.id}/notes`, { notes }, opts)}
                            >
                                Save notes
                            </Button>
                        </div>
                        {!e.cancelled && !e.ended && <EditEvent e={e} />}
                        {!e.cancelled && !e.ended && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() =>
                                    window.confirm('Cancel this event? Everyone going is told and their calendars update.') &&
                                    router.post(`/app/events/${e.id}/cancel`, {}, opts)
                                }
                            >
                                Cancel event
                            </Button>
                        )}
                    </Card>
                )}
            </div>
        </PortalLayout>
    );
}

/** Owner edit: everyone going or maybe gets the revised calendar invite. */
function EditEvent({ e }: { e: Props['event'] }) {
    const [open, setOpen] = useState(false);
    const f = useForm({ title: e.title, description: e.description ?? '', starts_at: '', minutes: '', location: e.location ?? '', video_url: '' });
    if (!open)
        return (
            <Button size="sm" variant="secondary" onClick={() => setOpen(true)}>
                Edit event
            </Button>
        );
    return (
        <div className="space-y-2 rounded-md border border-line p-3">
            <Field label="Title" error={f.errors.title}>
                <Input value={f.data.title} onChange={(ev) => f.setData('title', ev.target.value)} />
            </Field>
            <Field label="Description">
                <Input value={f.data.description} onChange={(ev) => f.setData('description', ev.target.value)} />
            </Field>
            <Field label="New start (leave empty to keep)" hint="In the event’s time zone." error={f.errors.starts_at}>
                <Input type="datetime-local" value={f.data.starts_at} onChange={(ev) => f.setData('starts_at', ev.target.value)} />
            </Field>
            <Field label="New length in minutes (optional)">
                <Input type="number" min="15" value={f.data.minutes} onChange={(ev) => f.setData('minutes', ev.target.value)} />
            </Field>
            {e.kind === 'in_person' ? (
                <Field label="Location">
                    <Input value={f.data.location} onChange={(ev) => f.setData('location', ev.target.value)} />
                </Field>
            ) : (
                <Field label="New video link (optional)" error={f.errors.video_url}>
                    <Input value={f.data.video_url} onChange={(ev) => f.setData('video_url', ev.target.value)} placeholder="https://" />
                </Field>
            )}
            <div className="flex gap-2">
                <Button
                    size="sm"
                    loading={f.processing}
                    onClick={() => {
                        f.transform((d) => ({
                            ...d,
                            starts_at: d.starts_at || null,
                            minutes: d.minutes || null,
                            location: d.location || null,
                            video_url: d.video_url || null,
                        }));
                        f.post(`/app/events/${e.id}/update`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                >
                    Save and update calendars
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    Close
                </Button>
            </div>
        </div>
    );
}
