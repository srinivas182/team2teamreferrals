import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { OpenPlaces, TeamCard, article, sendHref, type OpenPlace, type PublicTeam } from '@/components/directory/TeamCard';
import { SeoHead } from '@/components/seo/SeoHead';
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
    seats: Seat[];
    open: OpenPlace[];
    disclosure: string | null;
    viewer: string;
    seo: Seo;
};

/** A group's public page: what it is, its rules, the teams holding its seats by state, and open seats to claim. */
export default function GroupPublic({ group, answer, seats, open, disclosure, viewer, seo }: Props) {
    const byState = seats.reduce<Record<string, Seat[]>>((acc, s) => ({ ...acc, [s.state ?? '']: [...(acc[s.state ?? ''] ?? []), s] }), {});
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-4xl px-4 py-10">
                <p className="text-sm">
                    <Link href="/groups" className="text-primary">
                        Directory
                    </Link>
                    {group.hub && (
                        <>
                            {' · '}
                            <Link href={group.hub} className="text-primary">
                                About {group.industry.toLowerCase()}
                            </Link>
                        </>
                    )}
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">{group.name}</h1>
                <p className="mt-4 text-lg">{answer}</p>
                {group.owners.length > 0 && <p className="mt-2 text-sm text-muted">Run by {group.owners.join(', ')}</p>}
                {disclosure && (
                    <aside role="note" className="mt-5 flex gap-3 rounded-lg border border-line bg-surface p-4 text-sm">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                        <p>{disclosure}</p>
                    </aside>
                )}
                {group.description && <p className="mt-6 whitespace-pre-line">{group.description}</p>}

                <section aria-labelledby="rules" className="mt-8">
                    <h2 id="rules" className="font-display text-2xl font-bold">
                        How this network works
                    </h2>
                    <ul className="mt-3 list-disc space-y-1 pl-6">
                        <li>
                            One verified {group.terms.pro} per {group.unit}; others join the waitlist.
                        </li>
                        <li>Referrals are accepted or declined within {group.rules.accept_hours} hours.</li>
                        <li>Clients are contacted within {group.rules.first_contact_hours} hours of accepting.</li>
                        <li>The referring team gets an update at least every {group.rules.update_days} days.</li>
                        {group.rules.training && <li>Members complete the network’s short training before sending referrals.</li>}
                        <li>{group.rules.fees}</li>
                        <li>
                            Seats are {money(group.pricing.seat_price)} a month after {group.pricing.trial_months} months free.
                        </li>
                    </ul>
                </section>

                <section aria-labelledby="teams" className="mt-8">
                    <h2 id="teams" className="font-display text-2xl font-bold">
                        Verified {group.terms.pros} by state
                    </h2>
                    {seats.length === 0 && <p className="mt-3 text-muted">No seats are filled yet: be the first.</p>}
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
                    <section aria-labelledby="open" className="mt-8">
                        <h2 id="open" className="font-display text-2xl font-bold">
                            Open seats
                        </h2>
                        <p className="mt-1 text-sm text-muted">
                            Are you {article(group.terms.pro)} {group.terms.pro}? Claim your {group.unit} before someone else does.
                        </p>
                        <OpenPlaces places={open} groupSlug={group.slug} unit={group.unit} />
                    </section>
                )}
            </article>
        </PublicLayout>
    );
}
