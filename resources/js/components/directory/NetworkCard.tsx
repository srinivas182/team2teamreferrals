import { Link } from '@inertiajs/react';
import { ArrowRight, CalendarCheck, MapPin, Users } from 'lucide-react';
import { IndustryIcon } from '@/components/directory/IndustryIcon';
import type { PublicTeam } from '@/components/directory/TeamCard';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type NetworkRow = {
    slug: string;
    name: string;
    industry: string;
    industrySlug: string;
    description: string;
    seatsFilled: number;
    states: number;
    unit: string;
    photo: string | null;
    icon: string;
    family: string;
    inviteOnly: boolean;
    price: { seat: number; trialMonths: number };
    citySeat?: { territory: string; status: string; team: (PublicTeam & { since: string }) | null; waitlist: number } | null;
};

/** A cover for networks without a photo: the industry's family colours and icon. */
const covers: Record<string, string> = {
    property: 'from-[#1E3A8A] to-[#2563EB]',
    home: 'from-[#9A3412] to-[#F97316]',
    money: 'from-[#065F46] to-[#10B981]',
    legal: 'from-[#312E81] to-[#6366F1]',
    life: 'from-[#831843] to-[#EC4899]',
    care: 'from-[#134E4A] to-[#14B8A6]',
    business: 'from-[#0F172A] to-[#475569]',
    vehicles: 'from-[#7C2D12] to-[#EAB308]',
};

const money = (n: number) => `$${n.toLocaleString('en-US')}`;

/** One referral network in the directory. With a city chosen, it shows that city's seat and how to claim it. */
export function NetworkCard({ g, cityId, claimHref }: { g: NetworkRow; cityId?: number; claimHref: string }) {
    const seat = g.citySeat;
    const seatOpen = seat?.status === 'open';
    return (
        <li className="group flex flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <Link href={`/groups/${g.slug}`} className="relative block aspect-[16/7] overflow-hidden" tabIndex={-1} aria-hidden>
                {g.photo ? (
                    <img
                        src={g.photo}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className="size-full object-cover transition group-hover:scale-[1.02]"
                    />
                ) : (
                    <div className={cn('flex size-full items-center justify-center bg-gradient-to-br', covers[g.family] ?? covers.business)}>
                        <IndustryIcon name={g.icon} className="size-14 text-white/90" />
                    </div>
                )}
                <span className="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-navy-900 shadow-sm">
                    <IndustryIcon name={g.icon} className="size-3.5" />
                    {g.industry}
                </span>
            </Link>
            <div className="flex flex-1 flex-col p-5">
                <div className="flex items-start justify-between gap-3">
                    <h2 className="font-display text-xl leading-tight font-bold">
                        <Link href={`/groups/${g.slug}`} className="hover:text-primary">
                            {g.name}
                        </Link>
                    </h2>
                    {g.inviteOnly ? (
                        <Badge className="shrink-0 whitespace-nowrap">Invite only</Badge>
                    ) : (
                        <Badge tone="success" className="shrink-0 whitespace-nowrap">
                            Seats open
                        </Badge>
                    )}
                </div>
                {g.description && <p className="mt-2 line-clamp-2 text-sm text-muted">{g.description}</p>}
                <ul className="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-sm text-muted">
                    <li className="inline-flex items-center gap-1.5">
                        <Users className="size-4" aria-hidden />
                        {g.seatsFilled} {g.seatsFilled === 1 ? 'team' : 'teams'}
                    </li>
                    {g.states > 0 && (
                        <li className="inline-flex items-center gap-1.5">
                            <MapPin className="size-4" aria-hidden />
                            {g.states} {g.states === 1 ? 'state' : 'states'}
                        </li>
                    )}
                    <li className="inline-flex items-center gap-1.5">
                        <CalendarCheck className="size-4" aria-hidden />
                        {g.price.trialMonths} months free, then {money(g.price.seat)}/mo
                    </li>
                </ul>
                <p className="mt-1.5 text-xs text-muted">One verified team per {g.unit}.</p>

                {seat && (
                    <div
                        className={cn(
                            'mt-4 rounded-xl border p-3 text-sm',
                            seatOpen ? 'border-success/40 bg-[#ECFDF3] dark:bg-success/10' : 'border-line bg-bg',
                        )}
                    >
                        {seat.team ? (
                            <>
                                <span className="text-muted">{seat.territory}: </span>
                                <Link href={`/t/${seat.team.slug}`} className="font-semibold text-primary">
                                    {seat.team.name}
                                </Link>
                                {seat.waitlist > 0 && <span className="text-muted"> · {seat.waitlist} waiting</span>}
                            </>
                        ) : seatOpen ? (
                            <span className="font-semibold text-success">{seat.territory} seat is open</span>
                        ) : (
                            <span className="text-muted">{seat.territory}: seat filled</span>
                        )}
                    </div>
                )}

                <div className="mt-auto flex flex-wrap gap-2 pt-5">
                    <Button asChild variant="secondary" size="sm">
                        <Link href={`/groups/${g.slug}`}>
                            View network <ArrowRight aria-hidden />
                        </Link>
                    </Button>
                    {!g.inviteOnly && (
                        <Button asChild size="sm">
                            {seatOpen && cityId ? (
                                <a href={`/claim/${g.slug}/${cityId}`}>Claim this seat</a>
                            ) : (
                                <Link href={claimHref}>Claim a seat</Link>
                            )}
                        </Button>
                    )}
                </div>
            </div>
        </li>
    );
}
