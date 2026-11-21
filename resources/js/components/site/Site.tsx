import { Link } from '@inertiajs/react';
import {
    BadgeCheck,
    BadgeDollarSign,
    BarChart3,
    Bell,
    BookOpen,
    Building2,
    Clock,
    FileSignature,
    Globe,
    HandCoins,
    Handshake,
    HeartHandshake,
    Lock,
    Mail,
    MapPinned,
    MessageSquare,
    Phone,
    Search,
    Send,
    ShieldCheck,
    Sparkles,
    Star,
    Users,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The public site's shared pieces, in the style of the network directory: a tinted page header, icon cards, a dark
 * call-to-action band. Every public page uses these so the site looks like one product.
 */

/** The tinted band at the top of a public page. */
export function PageHero({
    eyebrow,
    title,
    lead,
    children,
    meta,
    narrow = false,
    className,
}: {
    eyebrow?: ReactNode;
    title: ReactNode;
    lead?: ReactNode;
    children?: ReactNode;
    meta?: ReactNode;
    narrow?: boolean;
    className?: string;
}) {
    return (
        <section className={cn('border-b border-line bg-gradient-to-b from-cobalt-50/70 to-bg dark:from-navy-900/60', className)}>
            <div className={cn('mx-auto px-5 pt-12 pb-10 sm:px-8 sm:pt-14', narrow ? 'max-w-4xl' : 'max-w-6xl')}>
                {eyebrow && <div className="text-sm font-semibold tracking-wide text-primary uppercase">{eyebrow}</div>}
                <h1 className="mt-2 max-w-3xl font-display text-4xl leading-tight font-bold text-balance sm:text-5xl">{title}</h1>
                {lead && <div className="mt-4 max-w-2xl text-lg text-muted">{lead}</div>}
                {meta && <div className="mt-3 text-sm text-muted">{meta}</div>}
                {children && <div className="mt-8">{children}</div>}
            </div>
        </section>
    );
}

/** A page section with the standard width and spacing; `tinted` alternates the background. */
export function Section({
    title,
    intro,
    children,
    tinted = false,
    narrow = false,
    id,
    className,
}: {
    title?: ReactNode;
    intro?: ReactNode;
    children: ReactNode;
    tinted?: boolean;
    narrow?: boolean;
    id?: string;
    className?: string;
}) {
    return (
        <section aria-labelledby={title && id ? id : undefined} className={cn(tinted && 'border-y border-line bg-surface', className)}>
            <div className={cn('mx-auto px-5 py-12 sm:px-8', narrow ? 'max-w-4xl' : 'max-w-6xl')}>
                {title && (
                    <h2 id={id} className="font-display text-3xl font-bold text-balance">
                        {title}
                    </h2>
                )}
                {intro && <p className="mt-2 max-w-2xl text-muted">{intro}</p>}
                <div className={cn(title || intro ? 'mt-8' : undefined)}>{children}</div>
            </div>
        </section>
    );
}

/** An icon in a soft coloured circle. */
export function IconChip({ icon: Icon, className }: { icon: LucideIcon; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-cobalt-50 text-primary dark:bg-cobalt-500/20',
                className,
            )}
        >
            <Icon className="size-5" aria-hidden />
        </span>
    );
}

/** A card with an icon, a title and a line or two; `step` numbers it. */
export function FeatureCard({
    icon,
    title,
    children,
    step,
    href,
}: {
    icon: LucideIcon;
    title: ReactNode;
    children?: ReactNode;
    step?: number;
    href?: string;
}) {
    const body = (
        <>
            <div className="flex items-center gap-3">
                <IconChip icon={icon} />
                {step !== undefined && <span className="text-sm font-semibold text-muted">Step {step}</span>}
            </div>
            <h3 className="mt-4 text-lg font-semibold">{title}</h3>
            {children && <div className="mt-1 text-muted">{children}</div>}
        </>
    );
    const cls = 'block h-full rounded-2xl border border-line bg-surface p-6 shadow-sm';
    return href ? (
        <Link href={href} className={cn(cls, 'transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md')}>
            {body}
        </Link>
    ) : (
        <div className={cls}>{body}</div>
    );
}

/** The dark band that ends a page with one clear next step. */
export function CtaBand({
    title,
    text,
    action,
    href,
    external = false,
}: {
    title: ReactNode;
    text?: ReactNode;
    action: string;
    href: string;
    external?: boolean;
}) {
    const cls = 'inline-flex h-11 shrink-0 items-center rounded-md bg-white px-5 font-semibold text-navy-900 hover:bg-white/90';
    return (
        <div className="flex flex-col items-start justify-between gap-4 rounded-2xl bg-navy-900 p-6 text-white sm:flex-row sm:items-center sm:p-8">
            <div>
                <p className="font-display text-2xl font-bold">{title}</p>
                {text && <p className="mt-1 text-white/80">{text}</p>}
            </div>
            {external ? (
                <a href={href} className={cls}>
                    {action}
                </a>
            ) : (
                <Link href={href} className={cls}>
                    {action}
                </Link>
            )}
        </div>
    );
}

/** Questions and answers, one open at a time is not enforced; the first starts open. */
export function Faq({ items }: { items: { q: ReactNode; a: ReactNode }[] }) {
    return (
        <div className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
            {items.map((i, n) => (
                <details key={n} className="group px-5 [&_summary::-webkit-details-marker]:hidden" open={n === 0}>
                    <summary className="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-4 font-semibold">
                        {i.q}
                        <span
                            className="inline-flex size-7 shrink-0 items-center justify-center rounded-full border border-line text-muted transition group-open:rotate-45"
                            aria-hidden
                        >
                            +
                        </span>
                    </summary>
                    <div className="pb-5 text-muted">{i.a}</div>
                </details>
            ))}
        </div>
    );
}

/**
 * An icon for a card from its words, for content written in Admin without an icon (e.g. "Verified licenses" →
 * a badge). Falls back to a sparkle.
 */
const keywordIcons: [RegExp, LucideIcon][] = [
    [/licen|verif|vetted|checked/i, BadgeCheck],
    [/consent|privacy|private|secure|safe|protect/i, ShieldCheck],
    [/agreement|sign|contract|terms/i, FileSignature],
    [/fee|paid|pay|price|cost|commission|earn|money|billing/i, BadgeDollarSign],
    [/claim|city|seat|local|area|map|territor/i, MapPinned],
    [/send|refer|introduc|hand/i, Send],
    [/track|report|progress|status|update|dashboard/i, BarChart3],
    [/fast|hour|deadline|quick|time|respond/i, Clock],
    [/contact|call|phone/i, Phone],
    [/email|mail/i, Mail],
    [/message|chat|talk/i, MessageSquare],
    [/trust|partner|relationship/i, Handshake],
    [/team|member|people|network/i, Users],
    [/rate|review|star|quality/i, Star],
    [/notif|remind|alert/i, Bell],
    [/search|find/i, Search],
    [/learn|guide|train|help/i, BookOpen],
    [/business|company|broker/i, Building2],
    [/client|customer|care/i, HeartHandshake],
    [/lock|password/i, Lock],
    [/web|online|site/i, Globe],
    [/reward|bonus|credit/i, HandCoins],
];
export function iconFor(title: string, text = ''): LucideIcon {
    // The title decides first ("Claim your city" → a map pin, even if its text mentions licenses), then the text.
    for (const t of [title, text]) {
        const hit = keywordIcons.find(([re]) => re.test(t));
        if (hit) return hit[1];
    }
    return Sparkles;
}
