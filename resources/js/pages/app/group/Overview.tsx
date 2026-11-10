import { router, usePage } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Kpi } from '@/components/ui/data';
import { PortalLayout } from '@/layouts/PortalLayout';
import { money } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    group: {
        slug: string;
        trainingRequired: boolean;
        trainingLocked: boolean;
        trainingFrom: string;
        name: string;
        description: string | null;
        status: string;
        visibility: string;
        industry: string;
        terms: Record<string, string>;
        rules: Record<string, number | string>;
        pricing: Record<string, unknown>;
    };
    health: { owners: number; seats: number; referralsThisMonth: number | null; avgAcceptMinutes: number | null; quietSeats: number | null };
    upcoming: { section: string; effectiveOn: string }[];
    seo: Seo;
};

/** Owner overview: how the group is doing and what is coming. Owners run the group; pricing is set by the platform. */
export default function GroupOverview({ group, health, upcoming, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const r = group.rules;
    return (
        <PortalLayout title={group.name} user={{ name: auth.user?.name ?? '', team: '' }} isGroupOwner>
            <SeoHead seo={seo} />
            <div className="mb-5 flex flex-wrap items-center gap-2">
                <a href={`/app/group/${group.slug}/applications`} className="text-sm font-medium text-primary">
                    Applications
                </a>
                <a href={`/app/group/${group.slug}/seat-map`} className="mr-2 text-sm font-medium text-primary">
                    Seat map and invitations
                </a>
                <Badge>{group.industry}</Badge>
                {group.status === 'live' ? <Badge tone="success">Live</Badge> : <Badge>Draft</Badge>}
                <Badge>{group.visibility === 'open' ? 'Open to applications' : 'Invite only'}</Badge>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi label={`${group.terms.pros?.[0]?.toUpperCase()}${group.terms.pros?.slice(1)} with seats`} value={String(health.seats)} />
                <Kpi
                    label={`${group.terms.referral?.[0]?.toUpperCase()}${group.terms.referral?.slice(1)}s this month`}
                    value={health.referralsThisMonth === null ? '—' : String(health.referralsThisMonth)}
                />
                <Kpi label="Average time to accept" value={health.avgAcceptMinutes === null ? '—' : `${health.avgAcceptMinutes} min`} />
                <Kpi label="Quiet seats this month" value={health.quietSeats === null ? '—' : String(health.quietSeats)} />
            </div>
            <div className="mt-6 grid gap-6 lg:grid-cols-2">
                <Card className="p-6">
                    <h2 className="font-semibold">Network rules</h2>
                    <ul className="mt-3 space-y-2 text-sm">
                        {[
                            ['Accept or decline within', `${r.accept_hours} hours`],
                            ['First contact within', `${r.first_contact_hours} hours`],
                            ['Status update every', `${r.update_days} days`],
                            ['Seat challenge', `after ${r.challenge_days} days below trust ${r.challenge_threshold}`],
                            ['Company cap', `${r.company_cap_pct}% of a state’s seats`],
                        ].map(([a, b]) => (
                            <li key={a} className="flex justify-between gap-3">
                                <span className="text-muted">{a}</span>
                                <span className="font-medium">{b}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-4 text-sm text-muted">
                        Seat price {money(Number(group.pricing.seat_price))}/month after a {String(group.pricing.trial_months)}-month free trial.
                        Pricing is set by Team to Team.
                    </p>
                </Card>
                <Card className="p-6">
                    <a href={`/app/group/${group.slug}/messages`} className="float-right text-sm font-medium text-primary">
                        Message wording
                    </a>
                    <a href={`/app/group/${group.slug}/pipeline`} className="float-right mr-4 text-sm font-medium text-primary">
                        Pipeline stages
                    </a>
                    <h2 className="font-semibold">Network training</h2>
                    <p className="mt-1 text-sm text-muted">
                        {group.trainingRequired
                            ? `Required: members who haven’t passed can’t send or receive new referrals from ${new Date(group.trainingFrom + 'T00:00').toLocaleDateString()}. Work in progress is never affected, and new referrals go to the backup meanwhile.`
                            : 'Optional: members who pass earn the “Network trained” badge and move up waitlists.'}
                    </p>
                    {group.trainingLocked ? (
                        <p className="mt-3 text-sm font-medium">Required by the platform for this industry.</p>
                    ) : (
                        <label className="mt-3 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                role="switch"
                                checked={group.trainingRequired}
                                onChange={(e) =>
                                    router.post(`/app/group/${group.slug}/training`, { required: e.target.checked }, { preserveScroll: true })
                                }
                            />{' '}
                            Require training before members send or receive referrals
                        </label>
                    )}
                </Card>
                <Card className="p-6">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <CalendarClock className="size-5 text-primary" aria-hidden /> Coming up
                    </h2>
                    {upcoming.length === 0 ? (
                        <p className="mt-3 text-sm text-muted">No changes scheduled.</p>
                    ) : (
                        <ul className="mt-3 space-y-2 text-sm">
                            {upcoming.map((u) => (
                                <li key={u.section + u.effectiveOn}>
                                    {u.section === 'pricing' ? 'Pricing' : 'Network rules'} change on{' '}
                                    {new Date(u.effectiveOn + 'T00:00').toLocaleDateString()}
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
