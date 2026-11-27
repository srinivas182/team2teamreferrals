import { Link } from '@inertiajs/react';
import { BadgeDollarSign, BarChart3, CalendarCheck, ChevronRight, Clock, GraduationCap, MapPinned, Phone, ShieldAlert, Users } from 'lucide-react';
import { EmbedMapCode } from '@/components/map/EmbedMapCode';
import { NetworkMap } from '@/components/map/NetworkMap';
import type { MapData } from '@/components/map/types';
import { OpenPlaces, TeamCard, article, sendHref, type OpenPlace, type PublicTeam } from '@/components/directory/TeamCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { IconChip, PageHero } from '@/components/site/Site';
import { Badge } from '@/components/ui/badge';
import { PublicLayout } from '@/layouts/PublicLayout';
import { money } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Seat = { territory: string; state: string | null; territoryId: number; type: string; cityId: number; team: PublicTeam };
type Props = {
    group: {
        slug: string;
        name: string;
        description: string;
        industry: string;
        industrySlug: string;
        tier: string;
        unit: string;
        terms: Record<string, string>;
        visibility: string;
        owners: string[];
        rules: { accept_hours: number; first_contact_hours: number; update_days: number; training: boolean; fees: string };
        pricing: { seat_price: number; trial_months: number };
        hub: string | null;
    };
    answer: string;
    openCities?: string | null;
    seats: Seat[];
    open: OpenPlace[];
    map: MapData;
    disclosure: string | null;
    viewer: string;
    seo: Seo;
};

/** A group's public page: what it is, its rules, the teams holding its seats by state, and open seats to claim. */
export default function GroupPublic({ group, answer, openCities, seats, open, map, disclosure, viewer, seo }: Props) {
    const byState = seats.reduce<Record<string, Seat[]>>((acc, s) => ({ ...acc, [s.state ?? '']: [...(acc[s.state ?? ''] ?? []), s] }), {});
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow={
                        <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1 normal-case">
                            <Link href="/groups" className="hover:underline">
                                Directory
                            </Link>
                            {group.hub && (
                                <>
                                    <ChevronRight className="size-4" aria-hidden />
                                    <Link href={group.hub} className="hover:underline">
                                        About {group.industry.toLowerCase()}
                                    </Link>
                                </>
                            )}
                        </nav>
                    }
                    title={group.name}
                    lead={answer}
                    meta={
                        group.owners.length > 0 || openCities ? (
                            <>
                                {group.owners.length > 0 && <>Run by {group.owners.join(', ')}. </>}
                                {openCities}
                            </>
                        ) : undefined
                    }
                >
                    <div className="flex flex-wrap gap-3">
                        <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-4 py-2 text-sm font-medium">
                            <Users className="size-4 text-primary" aria-hidden /> {seats.length} {seats.length === 1 ? 'seat' : 'seats'} filled
                        </span>
                        <span className="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-4 py-2 text-sm font-medium">
                            <CalendarCheck className="size-4 text-primary" aria-hidden /> {group.pricing.trial_months} months free, then{' '}
                            {money(group.pricing.seat_price)}/mo
                        </span>
                        {group.visibility === 'invite_only' ? (
                            <Badge className="px-4 py-2 text-sm">Invite only</Badge>
                        ) : (
                            open.length > 0 && (
                                <a
                                    href="#open"
                                    className="inline-flex h-10 items-center rounded-md bg-primary px-5 text-sm font-semibold text-on-primary"
                                >
                                    Claim a seat
                                </a>
                            )
                        )}
                    </div>
                </PageHero>
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    {disclosure && (
                        <aside role="note" className="flex gap-3 rounded-2xl border border-line bg-surface p-5 text-sm shadow-sm">
                            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                            <p>{disclosure}</p>
                        </aside>
                    )}
                    {group.description && <p className="mt-6 max-w-3xl text-lg whitespace-pre-line">{group.description}</p>}

                    <section aria-labelledby="rules" className="mt-12">
                        <h2 id="rules" className="font-display text-2xl font-bold">
                            How this network works
                        </h2>
                        <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                            {[
                                { icon: MapPinned, text: `One verified ${group.terms.pro} per ${group.unit}; others join the waitlist.` },
                                { icon: Clock, text: `Referrals are accepted or declined within ${group.rules.accept_hours} hours.` },
                                { icon: Phone, text: `Clients are contacted within ${group.rules.first_contact_hours} hours of accepting.` },
                                { icon: BarChart3, text: `The referring team gets an update at least every ${group.rules.update_days} days.` },
                                ...(group.rules.training
                                    ? [{ icon: GraduationCap, text: 'Members complete the network’s short training before sending referrals.' }]
                                    : []),
                                { icon: BadgeDollarSign, text: group.rules.fees },
                                {
                                    icon: CalendarCheck,
                                    text: `Seats are ${money(group.pricing.seat_price)} a month after ${group.pricing.trial_months} months free.`,
                                },
                            ].map((r) => (
                                <li key={r.text} className="flex gap-3 rounded-2xl border border-line bg-surface p-4 shadow-sm">
                                    <IconChip icon={r.icon} className="size-9" />
                                    <span className="self-center">{r.text}</span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {map.points.length > 0 && (
                        <section aria-labelledby="map" className="mt-12">
                            <h2 id="map" className="font-display text-2xl font-bold">
                                Where the network is
                            </h2>
                            <NetworkMap initial={map} src={`/map/group/${group.slug}.json`} />
                            <EmbedMapCode path={`group/${group.slug}`} title={group.name} />
                        </section>
                    )}

                    <section aria-labelledby="teams" className="mt-12">
                        <h2 id="teams" className="font-display text-2xl font-bold">
                            Verified {group.terms.pros} by state
                        </h2>
                        {seats.length === 0 && (
                            <p className="mt-3 rounded-2xl border border-dashed border-line bg-surface p-6 text-center text-muted">
                                No seats are filled yet: be the first.
                            </p>
                        )}
                        {Object.entries(byState).map(([st, list]) => (
                            <div key={st} className="mt-5">
                                <h3 className="font-semibold">
                                    {st ? (
                                        <Link href={`/industries/${group.industrySlug}/${st.toLowerCase()}`} className="text-primary">
                                            {st}
                                        </Link>
                                    ) : (
                                        'Other'
                                    )}
                                </h3>
                                <div className="mt-2 space-y-2">
                                    {list.map((s) => (
                                        <TeamCard
                                            key={`${s.type}${s.territoryId}`}
                                            team={s.team}
                                            place={s.territory}
                                            sendHref={sendHref(viewer, s.team, group.slug, s.cityId)}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </section>

                    {open.length > 0 && group.visibility !== 'invite_only' && (
                        <section aria-labelledby="open" className="mt-12 scroll-mt-24">
                            <h2 id="open" className="font-display text-2xl font-bold">
                                Open seats
                            </h2>
                            <p className="mt-1 text-sm text-muted">
                                Are you {article(group.terms.pro)} {group.terms.pro}? Claim your {group.unit} before someone else does.
                            </p>
                            <OpenPlaces places={open} groupSlug={group.slug} unit={group.unit} />
                        </section>
                    )}
                </div>
            </article>
        </PublicLayout>
    );
}
