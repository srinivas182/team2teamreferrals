import { Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { RequestForm, type RequestBlock } from '@/components/directory/RequestForm';
import { OpenPlaces, TeamCard, type OpenPlace, type PublicTeam } from '@/components/directory/TeamCard';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Seat = { territory: string; territoryId: number; type: string; team: PublicTeam; group: string; groupSlug: string };
type Props = {
    industry: { slug: string; name: string; terms: Record<string, string>; hub: boolean };
    state: { code: string; name: string };
    answer: string;
    seats: Seat[];
    open: OpenPlace[];
    group: { slug: string; name: string };
    request: RequestBlock | null;
    disclosure: string | null;
    seo: Seo;
};

/** An industry in one state: the verified teams holding seats there, and the open seats. */
export default function IndustryState({ industry, state, answer, seats, open, group, request, disclosure, seo }: Props) {
    return (
        <PublicLayout current="/groups">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-4xl px-4 py-10">
                <p className="text-sm">
                    {industry.hub ? (
                        <Link href={`/industries/${industry.slug}`} className="text-primary">
                            {industry.name}
                        </Link>
                    ) : (
                        industry.name
                    )}
                </p>
                <h1 className="mt-2 font-display text-4xl font-bold">
                    {industry.name} in {state.name}
                </h1>
                <p className="mt-4 text-lg">{answer}</p>
                {disclosure && (
                    <aside role="note" className="mt-5 flex gap-3 rounded-lg border border-line bg-surface p-4 text-sm">
                        <ShieldAlert className="mt-0.5 size-5 shrink-0 text-warning" aria-hidden />
                        <p>{disclosure}</p>
                    </aside>
                )}
                <section aria-labelledby="teams" className="mt-8">
                    <h2 id="teams" className="font-display text-2xl font-bold">
                        Verified {industry.terms.pros} in {state.name}
                    </h2>
                    <div className="mt-3 space-y-2">
                        {seats.map((s) => (
                            <TeamCard
                                key={`${s.groupSlug}${s.type}${s.territoryId}`}
                                team={s.team}
                                place={`${s.territory} · ${s.group}`}
                                sendHref={`/t/${s.team.slug}#refer`}
                            />
                        ))}
                    </div>
                    {seats.length === 0 && <p className="mt-3 text-muted">No seats are filled in {state.name} yet.</p>}
                </section>
                {request && <RequestForm block={request} pro={industry.terms.pro ?? 'pro'} place={state.name} />}
                {open.length > 0 && (
                    <section aria-labelledby="open" className="mt-8">
                        <h2 id="open" className="font-display text-2xl font-bold">
                            Open seats in {state.name}
                        </h2>
                        <p className="mt-1 text-sm text-muted">In {group.name}.</p>
                        <OpenPlaces places={open} groupSlug={group.slug} unit="seat" />
                    </section>
                )}
            </article>
        </PublicLayout>
    );
}
