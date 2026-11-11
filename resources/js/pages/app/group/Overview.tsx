import { useState } from 'react';
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
        feeAllowed: boolean;
        flaggedTeams: { team: string; balance: number }[];
        feeDefault: { type: string; value: number } | null;
        feeDueDays: number;
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
                            ['Seat challenge', `after ${r.challenge_days} days below a service score of ${r.challenge_threshold}`],
                            [
                                'Reciprocity credits',
                                `+${r.credit_sent} sent and accepted, +${r.credit_closed} on a Won close, ${r.credit_received} per referral accepted${r.min_balance_enabled ? `; flagged below ${r.min_balance}` : ''}`,
                            ],
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
                {group.flaggedTeams.length > 0 && (
                    <Card className="p-6">
                        <h2 className="font-semibold">Teams taking much more than they give</h2>
                        <p className="mt-1 text-sm text-muted">
                            Flagged by your minimum-balance rule. Nothing is blocked; a friendly word often helps.
                        </p>
                        <ul className="mt-2 text-sm">
                            {group.flaggedTeams.map((f) => (
                                <li key={f.team}>
                                    {f.team} · balance {f.balance}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
                {group.feeAllowed && <FeeDefault slug={group.slug} fee={group.feeDefault} dueDays={group.feeDueDays} />}
                <Card className="p-6">
                    <a href={`/app/group/${group.slug}/messages`} className="float-right text-sm font-medium text-primary">
                        Message wording
                    </a>
                    <a href={`/app/group/${group.slug}/disputes`} className="float-right mr-4 text-sm font-medium text-primary">
                        Disputes
                    </a>
                    <a href={`/app/group/${group.slug}/challenges`} className="float-right mr-4 text-sm font-medium text-primary">
                        Seat challenges
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

function FeeDefault({ slug, fee, dueDays }: { slug: string; fee: { type: string; value: number } | null; dueDays: number }) {
    const [type, setType] = useState(fee?.type ?? 'percent');
    const [value, setValue] = useState(fee ? String(fee.value / 100) : '');
    const [days, setDays] = useState(String(dueDays));
    return (
        <Card className="p-6">
            <h2 className="font-semibold">Default referral fee</h2>
            <p className="mt-1 text-sm text-muted">
                Stated in every agreement in this network. Two teams can agree a different fee for one referral before signing.
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <select
                    aria-label="Fee type"
                    className="h-9 rounded-md border border-line bg-surface px-2"
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                >
                    <option value="percent">% of the receiving team’s earnings from the referral</option>
                    <option value="flat">Flat amount ($) per referral that closes</option>
                </select>
                <input
                    aria-label="Fee value"
                    type="number"
                    min="0"
                    className="h-9 w-28 rounded-md border border-line bg-surface px-2"
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                />
                due within
                <input
                    aria-label="Days to pay"
                    type="number"
                    min="1"
                    max="90"
                    className="h-9 w-20 rounded-md border border-line bg-surface px-2"
                    value={days}
                    onChange={(e) => setDays(e.target.value)}
                />
                days
                <button
                    className="h-9 rounded-md bg-primary px-3 font-medium text-white"
                    onClick={() =>
                        router.post(
                            `/app/group/${slug}/fee`,
                            { type: value ? type : null, value: value || null, due_days: days },
                            { preserveScroll: true },
                        )
                    }
                >
                    Save
                </button>
            </div>
        </Card>
    );
}
