import { Link, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge, type BadgeTone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Row = {
    id: number;
    group: string;
    slug: string;
    city: string;
    state: 'pending' | 'holder' | 'backup' | 'waitlist' | 'declined' | 'ended';
    appliedOn: string;
    decidedOn: string | null;
    trialEnds: string | null;
};

/** What each state means to the member, in plain words. */
const states: Record<Row['state'], [string, BadgeTone, string]> = {
    pending: ['Waiting for approval', 'warning', 'The network team is reviewing your application. You’ll get an email when they decide.'],
    holder: ['Seat holder', 'success', 'You hold this seat. Referrals for this city come to you.'],
    backup: ['Backup', 'info', 'You get referrals the seat holder can’t take in time, and the seat if it opens.'],
    waitlist: ['Waitlist', 'neutral', 'You’re in line for this seat. We’ll offer it to you if it opens.'],
    declined: ['Not approved', 'danger', 'This application wasn’t approved. You can apply for another city.'],
    ended: ['Ended', 'neutral', 'You no longer hold a place on this seat.'],
};

const day = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

/** My groups: every network and city this team applied for, and where each one stands. */
export default function MyGroups({ rows, status, seo }: { rows: Row[]; status?: string | null; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="My groups" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {rows.length === 0 ? (
                <Card className="max-w-2xl p-8 text-center">
                    <Users className="mx-auto size-10 text-primary" aria-hidden />
                    <h2 className="mt-3 font-display text-xl font-bold">You haven’t joined a group yet</h2>
                    <p className="mt-2 text-muted">Apply for the seat in your city in a network for your industry.</p>
                    <Button asChild className="mt-5">
                        <Link href="/app/groups">Join new groups</Link>
                    </Button>
                </Card>
            ) : (
                <>
                    <ul className="grid max-w-5xl gap-4 md:grid-cols-2">
                        {rows.map((r) => {
                            const [label, tone, help] = states[r.state];
                            return (
                                <li key={r.id}>
                                    <Card className="flex h-full flex-col p-5">
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            <div>
                                                <h2 className="font-semibold">{r.group}</h2>
                                                <p className="text-sm text-muted">{r.city}</p>
                                            </div>
                                            <Badge tone={tone}>{label}</Badge>
                                        </div>
                                        <p className="mt-3 text-sm text-muted">{help}</p>
                                        <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                                            <div>
                                                <dt className="text-subtle">Applied</dt>
                                                <dd>{day(r.appliedOn)}</dd>
                                            </div>
                                            {r.decidedOn && (
                                                <div>
                                                    <dt className="text-subtle">Decided</dt>
                                                    <dd>{day(r.decidedOn)}</dd>
                                                </div>
                                            )}
                                            {r.trialEnds && (
                                                <div>
                                                    <dt className="text-subtle">Free trial ends</dt>
                                                    <dd>{day(r.trialEnds)}</dd>
                                                </div>
                                            )}
                                        </dl>
                                    </Card>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="mt-6">
                        <Button asChild variant="secondary">
                            <Link href="/app/groups">Join new groups</Link>
                        </Button>
                    </div>
                </>
            )}
        </PortalLayout>
    );
}
