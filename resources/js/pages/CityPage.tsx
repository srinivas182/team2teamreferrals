import { Link } from '@inertiajs/react';
import { ChevronRight, ShieldAlert } from 'lucide-react';
import { RequestForm, type RequestBlock } from '@/components/directory/RequestForm';
import { LocalPartners, type PartnerBlock } from '@/components/partners/LocalPartners';
import { TeamCard, article, type PublicTeam } from '@/components/directory/TeamCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
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
    partners: PartnerBlock[];
    partnersNote: string;
    disclosure: string | null;
    seo: Seo;
};

/** One industry in one city: who holds the seat (with their public facts as text), or that it is open to claim. */
export default function CityPage({
    industry,
    state,
    city,
    answer,
    intro,
    seats,
    nearby,
    otherIndustries,
    howTo,
    request,
    partners,
    partnersNote,
    disclosure,
    seo,
}: Props) {
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow={
                        <nav aria-label="Breadcrumb" className="flex flex-wrap items-center gap-1 normal-case">
                            {industry.hub ? (
                                <Link href={`/industries/${industry.slug}`} className="hover:underline">
                                    {industry.name}
                                </Link>
                            ) : (
                                industry.name
                            )}
                            <ChevronRight className="size-4" aria-hidden />
                            <Link href={`/industries/${industry.slug}/${state.code.toLowerCase()}`} className="hover:underline">
                                {state.name}
                            </Link>
                        </nav>
                    }
                    title={
                        <>
                            {industry.name} in {city.name}, {state.code}
                        </>
                    }
                    lead={answer}
                />
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    {intro && <p className="max-w-3xl text-lg">{intro}</p>}
                    {disclosure && (
                        <aside role="note" className="mt-6 flex gap-3 rounded-2xl border border-line bg-surface p-5 text-sm shadow-sm">
                            <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                            <p>{disclosure}</p>
                        </aside>
                    )}
                    {seats.map((s) => (
                        <section key={s.groupSlug} className="mt-8 sm:rounded-2xl sm:border sm:border-line sm:bg-surface sm:p-6 sm:shadow-sm">
                            <h2 className="font-display text-xl font-bold">
                                <Link href={`/groups/${s.groupSlug}`} className="text-primary hover:underline">
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
                                <div className="mt-4 rounded-xl border border-success/40 bg-[#ECFDF3] p-5 dark:bg-success/10">
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
                    <section aria-labelledby="how-to" className="mt-12">
                        <h2 id="how-to" className="font-display text-xl font-bold">
                            How do I find a trusted {industry.terms.pro} in {city.name}, {state.code}?
                        </h2>
                        <p className="mt-2">{howTo}</p>
                    </section>
                    {nearby.length > 0 && (
                        <section aria-labelledby="nearby" className="mt-12">
                            <h2 id="nearby" className="font-display text-xl font-bold">
                                {industry.name} near {city.name}
                            </h2>
                            <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                                {nearby.map((n) => (
                                    <li key={n.slug} className="rounded-xl border border-line bg-surface p-4">
                                        <Link
                                            href={`/industries/${industry.slug}/${state.code.toLowerCase()}/${n.slug}`}
                                            className="font-semibold text-primary hover:underline"
                                        >
                                            {n.name}
                                        </Link>{' '}
                                        <span className="text-sm text-muted">· {n.team}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    {otherIndustries.length > 0 && (
                        <section aria-labelledby="also" className="mt-12">
                            <h2 id="also" className="font-display text-xl font-bold">
                                Also verified in {city.name}
                            </h2>
                            <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                                {otherIndustries.map((o) => (
                                    <li key={o.slug} className="rounded-xl border border-line bg-surface p-4">
                                        <Link
                                            href={`/industries/${o.slug}/${state.code.toLowerCase()}/${city.slug}`}
                                            className="font-semibold text-primary hover:underline"
                                        >
                                            {o.name}
                                        </Link>{' '}
                                        <span className="text-sm text-muted">· {o.team}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    <LocalPartners blocks={partners} note={partnersNote} city={`${city.name}, ${state.code}`} cityId={city.id} />
                </div>
            </article>
        </PublicLayout>
    );
}
