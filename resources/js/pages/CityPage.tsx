import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { RequestForm, type RequestBlock } from '@/components/directory/RequestForm';
import { TeamCard, article, type PublicTeam } from '@/components/directory/TeamCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Seat = {
    group: string;
    groupSlug: string;
    seat: { territory: string; status: string; team: (PublicTeam & { since: string }) | null; waitlist: number };
};
type Props = {
    industry: { slug: string; name: string; terms: Record<string, string>; hub: boolean };
    state: { code: string; name: string };
    city: { id: number; name: string; slug: string };
    answer: string;
    intro: string | null;
    seats: Seat[];
    nearby: { name: string; slug: string; team: string }[];
    otherIndustries: { slug: string; name: string; team: string }[];
    howTo: string;
    request: RequestBlock | null;
    disclosure: string | null;
    seo: Seo;
};

/** One industry in one city: who holds the seat (with their public facts as text), or that it is open to claim. */
export default function CityPage({ industry, state, city, answer, intro, seats, nearby, otherIndustries, howTo, request, disclosure, seo }: Props) {
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-3xl px-4 py-10">
                <p className="text-sm">
                    {industry.hub ? (
                        <Link href={`/industries/${industry.slug}`} className="text-primary">
                            {industry.name}
                        </Link>
                    ) : (
                        industry.name
                    )}
                    {' · '}
                    <Link href={`/industries/${industry.slug}/${state.code.toLowerCase()}`} className="text-primary">
                        {state.name}
                    </Link>
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">
                    {industry.name} in {city.name}, {state.code}
                </h1>
                <p className="mt-4 text-lg">{answer}</p>
                {intro && <p className="mt-3">{intro}</p>}
                {disclosure && (
                    <aside role="note" className="mt-5 flex gap-3 rounded-lg border border-line bg-surface p-4 text-sm">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                        <p>{disclosure}</p>
                    </aside>
                )}
                {seats.map((s) => (
                    <section key={s.groupSlug} className="mt-8">
                        <h2 className="font-display text-xl font-bold">
                            <Link href={`/groups/${s.groupSlug}`} className="text-primary">
                                {s.group}
                            </Link>
                            : {s.seat.territory}
                        </h2>
                        {s.seat.team ? (
                            <div className="mt-3">
                                <TeamCard
                                    team={s.seat.team}
                                    place={`Seat holder since ${s.seat.team.since}`}
                                    sendHref={`/t/${s.seat.team.slug}#refer`}
                                />
                            </div>
                        ) : s.seat.status === 'open' ? (
                            <div className="mt-3 rounded-lg border border-line p-4">
                                <p>
                                    This seat is open.{' '}
                                    {s.seat.waitlist > 0 && `${s.seat.waitlist} ${s.seat.waitlist === 1 ? 'team is' : 'teams are'} waiting. `}
                                    Are you {article(industry.terms.pro)} {industry.terms.pro} here?
                                </p>
                                <Button asChild className="mt-3">
                                    <a href={`/claim/${s.groupSlug}/${city.id}`}>Claim this seat</a>
                                </Button>
                            </div>
                        ) : (
                            <p className="mt-3 text-muted">This seat is filled.</p>
                        )}
                    </section>
                ))}
                {request && <RequestForm block={request} pro={industry.terms.pro ?? 'pro'} place={`${city.name}, ${state.code}`} />}
                <section aria-labelledby="how-to" className="mt-8">
                    <h2 id="how-to" className="font-display text-xl font-bold">
                        How do I find a trusted {industry.terms.pro} in {city.name}, {state.code}?
                    </h2>
                    <p className="mt-2">{howTo}</p>
                </section>
                {nearby.length > 0 && (
                    <section aria-labelledby="nearby" className="mt-8">
                        <h2 id="nearby" className="font-display text-xl font-bold">
                            {industry.name} near {city.name}
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {nearby.map((n) => (
                                <li key={n.slug}>
                                    <Link href={`/industries/${industry.slug}/${state.code.toLowerCase()}/${n.slug}`} className="text-primary">
                                        {n.name}
                                    </Link>{' '}
                                    <span className="text-sm text-muted">· {n.team}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {otherIndustries.length > 0 && (
                    <section aria-labelledby="also" className="mt-8">
                        <h2 id="also" className="font-display text-xl font-bold">
                            Also verified in {city.name}
                        </h2>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
                            {otherIndustries.map((o) => (
                                <li key={o.slug}>
                                    <Link href={`/industries/${o.slug}/${state.code.toLowerCase()}/${city.slug}`} className="text-primary">
                                        {o.name}
                                    </Link>{' '}
                                    <span className="text-sm text-muted">· {o.team}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </article>
        </PublicLayout>
    );
}
