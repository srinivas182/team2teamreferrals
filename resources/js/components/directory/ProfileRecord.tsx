import { BadgeCheck, CircleCheck, Clock, ShieldCheck, Star, Timer, TrendingUp, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

export type Review = { stars: number; by: 'client' | 'team'; from: string | null; comment: string | null; date: string };
export type Reviews = { breakdown: Record<string, number>; clients: number; peers: number; items: Review[] };
export type PartCount = { items: number; good: number };
export type TrustRecord = { score: number | null; status: string; parts?: Partial<Record<'response' | 'conversion' | 'quality', PartCount>> };

/** Fewer than this many referrals behind a rate, and the tile says "New" instead of a percentage. */
const MIN_ITEMS = 3;

type Tile = {
    key: string;
    icon: LucideIcon;
    label: string;
    value: string;
    unit?: string;
    detail: string;
    about: string;
    tone: 'good' | 'ok' | 'low' | 'new';
};

const toneFor = (pct: number): Tile['tone'] => (pct >= 80 ? 'good' : pct >= 50 ? 'ok' : 'low');

function rateTile(key: string, icon: LucideIcon, label: string, part: PartCount | undefined, detail: (p: PartCount) => string, about: string): Tile {
    if (!part || part.items < MIN_ITEMS) {
        return { key, icon, label, value: 'New', detail: 'Not enough referrals yet', about, tone: 'new' };
    }
    const pct = Math.round((100 * part.good) / part.items);
    return { key, icon, label, value: String(pct), unit: '%', detail: detail(part), about, tone: toneFor(pct) };
}

const toneClass: Record<Tile['tone'], string> = {
    good: 'text-success',
    ok: 'text-warning',
    low: 'text-danger',
    new: 'text-muted',
};

/**
 * The track record panel on a team's profile: the trust score and the rates behind it, each with the counts it comes
 * from ("9 of 10") and one line on what it means. Rates show once there are 3 referrals behind them; before that they
 * read "New", so a new team never looks worse than it is.
 */
export function TrackRecord({
    trust,
    rating,
    ratings,
    licenses,
    closed,
}: {
    trust: TrustRecord | null | undefined;
    rating: number | null;
    ratings: number;
    licenses: number;
    closed: number;
}) {
    const parts = trust?.parts ?? {};
    const scored = trust?.status === 'scored' && trust.score !== null;
    const tiles: Tile[] = [
        {
            key: 'trust',
            icon: ShieldCheck,
            label: 'Trust score',
            value: scored ? String(trust?.score) : 'New',
            unit: scored ? '/100' : undefined,
            detail: scored ? 'From the last 12 months, recent work counting more' : 'Scored after 3 accepted referrals',
            about: 'Response speed, updates, closing, fees paid on time, accurate outcomes and referral quality.',
            tone: scored ? toneFor(trust?.score ?? 0) : 'new',
        },
        rateTile(
            'conversion',
            TrendingUp,
            'Closing rate',
            parts.conversion,
            (p) => `${p.good} of ${p.items} finished referrals closed`,
            'Referrals they accepted and won, out of those finished.',
        ),
        rateTile(
            'response',
            Timer,
            'Responds on time',
            parts.response,
            (p) => `${p.good} of ${p.items} answered before the deadline`,
            'How often they accept or decline a new referral in time.',
        ),
        rateTile(
            'quality',
            CircleCheck,
            'Referral quality',
            parts.quality,
            (p) => `${p.good} of ${p.items} referrals sent were real clients`,
            'Clients they referred who had correct details and wanted help.',
        ),
        {
            key: 'rating',
            icon: Star,
            label: 'Rating',
            value: rating !== null ? rating.toFixed(1) : 'New',
            unit: rating !== null ? '/5' : undefined,
            detail: rating !== null ? `From ${ratings} verified ${ratings === 1 ? 'rating' : 'ratings'}` : 'Shown after 3 ratings',
            about: 'Rated by clients and referring teams after tracked referrals.',
            tone: rating === null ? 'new' : rating >= 4 ? 'good' : rating >= 3 ? 'ok' : 'low',
        },
        {
            key: 'verified',
            icon: BadgeCheck,
            label: 'Verified',
            value: licenses > 0 ? String(licenses) : 'Pending',
            unit: licenses > 0 ? (licenses === 1 ? ' license' : ' licenses') : undefined,
            detail:
                closed > 0
                    ? `${closed} ${closed === 1 ? 'referral' : 'referrals'} closed on the platform`
                    : 'No referrals closed on the platform yet',
            about: 'Licenses checked by our staff against the state’s public lookup.',
            tone: licenses > 0 ? 'good' : 'new',
        },
    ];
    return (
        <section aria-labelledby="track-record" className="rounded-2xl border border-line bg-surface p-5 shadow-sm sm:p-6">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="track-record" className="font-display text-xl font-bold">
                    Track record
                </h2>
                <p className="text-xs text-muted">From referrals tracked on Team to Team · last 12 months</p>
            </div>
            <ul className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {tiles.map((t) => (
                    <li key={t.key} className="flex flex-col rounded-xl border border-line bg-bg/60 p-4" data-testid={`tile-${t.key}`}>
                        <span className="flex items-center gap-2 text-sm font-medium text-muted">
                            <t.icon className={`size-4 ${toneClass[t.tone]}`} aria-hidden /> {t.label}
                        </span>
                        <span className="mt-2 font-display text-3xl leading-none font-extrabold tabular">
                            {t.value}
                            {t.unit && <span className="ml-0.5 text-base font-semibold text-muted">{t.unit}</span>}
                        </span>
                        <span className="mt-2 text-sm">{t.detail}</span>
                        <span className="mt-1 text-xs text-muted">{t.about}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export type Credential = { state: string; stateName: string; industry: string; number: string; verifiedOn: string | null; expiresOn: string | null };

/** At-a-glance record: response time and the last 12 months, from real referrals (shown once there are enough). */
export function RecordFacts({
    response,
    lastYear,
    foundedYear,
}: {
    response: { hours: number | null };
    lastYear: { received: number; closed: number };
    foundedYear: number | null;
}) {
    const years = foundedYear ? new Date().getFullYear() - foundedYear : null;
    const facts = [
        response.hours !== null && `Responds in about ${response.hours} ${response.hours === 1 ? 'hour' : 'hours'}`,
        lastYear.received > 0 && `${lastYear.received} referrals received in the last 12 months`,
        lastYear.closed > 0 && `${lastYear.closed} confirmed closes in the last 12 months`,
        years !== null && years > 0 && `${years} ${years === 1 ? 'year' : 'years'} in business`,
    ].filter(Boolean) as string[];
    if (facts.length === 0) {
        return null;
    }
    return (
        <ul className="mt-5 grid gap-2 sm:grid-cols-2">
            {facts.map((f) => (
                <li key={f} className="flex items-center gap-2 rounded-md border border-line p-3 text-sm">
                    <Clock className="size-4 text-primary" aria-hidden /> {f}
                </li>
            ))}
        </ul>
    );
}

/** What was verified: each license, its number, when we checked it and when it expires. */
export function Credentials({ items }: { items: Credential[] }) {
    if (items.length === 0) {
        return null;
    }
    return (
        <section aria-labelledby="verified" className="mt-8">
            <h2 id="verified" className="font-display text-xl font-bold">
                What we verified
            </h2>
            <ul className="mt-3 space-y-2">
                {items.map((c) => (
                    <li key={c.state + c.number} className="flex items-start gap-2 text-sm">
                        <BadgeCheck className="mt-0.5 size-4 shrink-0 text-success" aria-hidden />
                        <span>
                            {c.industry} license in {c.stateName}, number {c.number}
                            {c.verifiedOn && <>, checked {c.verifiedOn}</>}
                            {c.expiresOn && <>, valid until {c.expiresOn}</>}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** Ratings from verified referrals: a 5-to-1 breakdown and the reviews, filterable by who rated and by stars. */
export function Ratings({ reviews }: { reviews: Reviews }) {
    const [by, setBy] = useState<'all' | 'client' | 'team'>('all');
    const [stars, setStars] = useState(0);
    const total = reviews.clients + reviews.peers;
    if (total === 0) {
        return null;
    }
    const shown = reviews.items.filter((r) => (by === 'all' || r.by === by) && (stars === 0 || r.stars === stars));
    return (
        <section aria-labelledby="ratings" className="mt-8">
            <h2 id="ratings" className="font-display text-xl font-bold">
                Ratings from verified referrals
            </h2>
            <p className="mt-1 text-sm text-muted">
                {total} {total === 1 ? 'rating' : 'ratings'}: {reviews.clients} from clients, {reviews.peers} from referring teams. Every rating comes
                from a referral tracked on the platform.
            </p>
            <div className="mt-3 space-y-1" role="group" aria-label="Filter by stars">
                {[5, 4, 3, 2, 1].map((n) => {
                    const count = reviews.breakdown[String(n)] ?? 0;
                    return (
                        <button
                            key={n}
                            type="button"
                            onClick={() => setStars(stars === n ? 0 : n)}
                            aria-pressed={stars === n}
                            className="flex w-full items-center gap-2 text-left text-sm"
                        >
                            <span className="w-12">{n} star</span>
                            <span className="h-2 flex-1 overflow-hidden rounded-full bg-bg">
                                <span className="block h-full bg-warning" style={{ width: `${total ? (count / total) * 100 : 0}%` }} />
                            </span>
                            <span className="w-8 text-right tabular">{count}</span>
                        </button>
                    );
                })}
            </div>
            <div className="mt-3 flex gap-2 text-sm" role="group" aria-label="Filter by who rated">
                {(
                    [
                        ['all', 'All'],
                        ['client', 'Clients'],
                        ['team', 'Referring teams'],
                    ] as const
                ).map(([k, l]) => (
                    <button
                        key={k}
                        type="button"
                        aria-pressed={by === k}
                        onClick={() => setBy(k)}
                        className={`rounded-full border px-3 py-1 ${by === k ? 'border-primary text-primary' : 'border-line'}`}
                    >
                        {l}
                    </button>
                ))}
            </div>
            <ul className="mt-4 space-y-3">
                {shown.map((r, i) => (
                    <li key={i} className="rounded-md border border-line p-3 text-sm">
                        <span className="flex items-center gap-1" aria-label={`${r.stars} out of 5`}>
                            {Array.from({ length: 5 }, (_, s) => (
                                <Star key={s} className={`size-4 ${s < r.stars ? 'fill-warning text-warning' : 'text-line'}`} aria-hidden />
                            ))}
                        </span>
                        {r.comment && <p className="mt-1">{r.comment}</p>}
                        <p className="mt-1 text-xs text-muted">
                            {r.by === 'client' ? 'Verified client' : (r.from ?? 'Referring team')} · Verified referral · {r.date}
                        </p>
                    </li>
                ))}
                {shown.length === 0 && <li className="text-sm text-muted">No ratings match this filter.</li>}
            </ul>
        </section>
    );
}
