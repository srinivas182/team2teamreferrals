import { Link } from '@inertiajs/react';
import { BadgeCheck, ExternalLink, Gift, Phone, ThumbsUp } from 'lucide-react';

export type PartnerCardData = {
    slug: string;
    name: string;
    category: string;
    logo: string | null;
    summary: string;
    phone: string | null;
    website: string | null;
    offer: { title: string; details: string | null; expires: string | null } | null;
    license: { label: string; number: string; state: string | null; verified: string; lookup: string | null } | null;
    endorsements: number;
};

/** A verified license, with the date we checked it and a link to the regulator's own record. */
export function LicenseBadge({ license }: { license: NonNullable<PartnerCardData['license']> }) {
    return (
        <span className="inline-flex flex-wrap items-center gap-1 text-sm">
            <BadgeCheck className="size-4 text-success" aria-hidden />
            <span>
                {license.label} {license.number}
                {license.state ? ` (${license.state})` : ''} checked {license.verified}
            </span>
            {license.lookup && (
                <a href={license.lookup} target="_blank" rel="noopener noreferrer nofollow" className="text-primary underline">
                    Look it up
                </a>
            )}
        </span>
    );
}

/** One advertiser. Outbound links are marked sponsored, as search engines ask for paid placements. */
export function PartnerCard({ partner, cityId }: { partner: PartnerCardData; cityId?: number }) {
    const href = `/partner/${partner.slug}${cityId ? `?city=${cityId}` : ''}`;
    return (
        <article className="flex gap-3 rounded-lg border border-line p-4">
            {partner.logo ? (
                <img src={partner.logo} alt="" width={48} height={48} loading="lazy" className="size-12 shrink-0 rounded-md object-contain" />
            ) : (
                <div aria-hidden className="grid size-12 shrink-0 place-items-center rounded-md bg-surface font-display text-lg font-bold">
                    {partner.name.slice(0, 1)}
                </div>
            )}
            <div className="min-w-0 flex-1">
                <h3 className="font-semibold">
                    <Link href={href} className="text-primary">
                        {partner.name}
                    </Link>
                </h3>
                <p className="text-sm text-muted first-letter:uppercase">{partner.category}</p>
                <p className="mt-1 text-sm">{partner.summary}</p>
                <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    {partner.license && (
                        <li>
                            <LicenseBadge license={partner.license} />
                        </li>
                    )}
                    {partner.endorsements > 0 && (
                        <li className="flex items-center gap-1">
                            <ThumbsUp className="size-4 text-muted" aria-hidden />
                            Endorsed by {partner.endorsements} {partner.endorsements === 1 ? 'member team' : 'member teams'}
                        </li>
                    )}
                    {partner.offer && (
                        <li className="flex items-center gap-1">
                            <Gift className="size-4 text-accent" aria-hidden />
                            {partner.offer.title}
                        </li>
                    )}
                </ul>
                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <Link href={`${href}#enquire`} className="font-medium text-primary">
                        Send a message
                    </Link>
                    {partner.phone && (
                        <a href={`tel:${partner.phone.replace(/[^0-9+]/g, '')}`} className="flex items-center gap-1 text-primary">
                            <Phone className="size-4" aria-hidden /> {partner.phone}
                        </a>
                    )}
                    {partner.website && (
                        <a
                            href={partner.website}
                            target="_blank"
                            rel="sponsored noopener noreferrer"
                            className="flex items-center gap-1 text-primary"
                        >
                            Website <ExternalLink className="size-3" aria-hidden />
                        </a>
                    )}
                </div>
            </div>
        </article>
    );
}

/** "Advertisement" label and the "how this list works" note, shown wherever partners are listed. */
export function AdNote({ note }: { note: string }) {
    return (
        <details className="mt-2 text-sm text-muted">
            <summary className="cursor-pointer">
                <span className="mr-2 rounded border border-line px-1.5 py-0.5 text-xs font-semibold tracking-wide uppercase">Advertisement</span>
                How this list works
            </summary>
            <p className="mt-2">{note}</p>
        </details>
    );
}
