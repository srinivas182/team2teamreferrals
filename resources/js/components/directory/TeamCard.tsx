import { Link } from '@inertiajs/react';
import { BadgeCheck, Send, Star } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type PublicTeam = {
    name: string;
    slug: string;
    logo: string | null;
    foundedYear: number | null;
    licenses: string[];
    rating: number | null;
    ratings: number;
    closed: number;
    trust: { score: number | null; status: string } | null;
};

/**
 * A team holding a seat, with only its public facts as readable text (for people and for search engines), and a
 * "Send referral" button: members go to the send form for that seat; visitors to the team's public referral form.
 */
export function TeamCard({ team, place, sendHref }: { team: PublicTeam; place: string; sendHref: string }) {
    return (
        <article className="flex gap-3 rounded-lg border border-line p-4">
            {team.logo ? (
                <img src={team.logo} alt="" width={48} height={48} loading="lazy" className="size-12 shrink-0 rounded-md object-cover" />
            ) : (
                <div aria-hidden className="grid size-12 shrink-0 place-items-center rounded-md bg-surface font-display text-lg font-bold">
                    {team.name.slice(0, 1)}
                </div>
            )}
            <div className="min-w-0 flex-1">
                <h3 className="font-semibold">
                    <Link href={`/t/${team.slug}`} className="text-primary">
                        {team.name}
                    </Link>
                </h3>
                <p className="text-sm text-muted">{place}</p>
                <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    {team.licenses.length > 0 && (
                        <li className="flex items-center gap-1">
                            <BadgeCheck className="size-4 text-success" aria-hidden /> License verified ({team.licenses.join(', ')})
                        </li>
                    )}
                    <li>Trust score: {team.trust?.score != null ? team.trust.score : 'New'}</li>
                    {team.rating != null ? (
                        <li className="flex items-center gap-1">
                            <Star className="size-4 text-warning" aria-hidden /> {team.rating} from {team.ratings} ratings
                        </li>
                    ) : (
                        team.ratings > 0 && <li>{team.ratings} ratings so far</li>
                    )}
                    {team.closed > 0 && <li>{team.closed} confirmed closes</li>}
                    {team.foundedYear && <li>In business since {team.foundedYear}</li>}
                </ul>
            </div>
            <div className="shrink-0 self-center">
                <Button asChild size="sm" variant="secondary">
                    <Link href={sendHref}>
                        <Send /> Send referral
                    </Link>
                </Button>
            </div>
        </article>
    );
}

/** Where "Send referral" goes for a seat holder. */
export const sendHref = (viewer: string, team: PublicTeam, groupSlug: string, cityId: number) =>
    viewer === 'member' && cityId > 0 ? `/app/send?group=${groupSlug}&city=${cityId}&from=${team.slug}` : `/t/${team.slug}#refer`;

export type OpenPlace = { city: string; cityId: number; state: string; territory: string; waitlist: number };

/** Open seats with a "Claim" link (sign-up, license check, application and training follow). */
export function OpenPlaces({ places, groupSlug, unit }: { places: OpenPlace[]; groupSlug: string; unit: string }) {
    if (places.length === 0) {
        return null;
    }
    return (
        <ul className="mt-3 grid gap-2 sm:grid-cols-2">
            {places.map((p) => (
                <li key={`${p.cityId}`} className="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
                    <span>
                        <span className="font-semibold">{p.territory}</span>
                        <span className="block text-xs text-muted">
                            Open {unit} seat{p.waitlist > 0 ? ` · ${p.waitlist} waiting` : ''}
                        </span>
                    </span>
                    <Button asChild size="sm">
                        <a href={`/claim/${groupSlug}/${p.cityId}`}>Claim this seat</a>
                    </Button>
                </li>
            ))}
        </ul>
    );
}

/** "a" or "an" before a word, for sentences like "Are you an installer?". */
export const article = (word = '') => (/^[aeiou]/i.test(word) ? 'an' : 'a');
