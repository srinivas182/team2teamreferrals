import { cn } from '@/lib/utils';

const avatarTones = { sender: 'bg-cobalt-500 text-white', receiver: 'bg-orange-500 text-navy-900', neutral: 'bg-navy-700 text-white' };

export function Avatar({ name, size = 36, tone = 'sender', src }: { name: string; size?: number; tone?: keyof typeof avatarTones; src?: string }) {
    const initials = name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('');
    if (src) return <img src={src} alt="" width={size} height={size} className="shrink-0 rounded-full object-cover" />;
    return (
        <span
            aria-hidden
            className={cn('inline-flex shrink-0 items-center justify-center rounded-full font-semibold', avatarTones[tone])}
            style={{ width: size, height: size, fontSize: size * 0.38 }}
        >
            {initials}
        </span>
    );
}

/** Trust score 0–100 as a ring; "New" until a team has 3 accepted referrals. */
export function TrustRing({ score, size = 44 }: { score: number | 'new'; size?: number }) {
    const r = (size - 6) / 2;
    const c = 2 * Math.PI * r;
    const pct = score === 'new' ? 0 : Math.max(0, Math.min(100, score)) / 100;
    return (
        <span
            role="img"
            className="relative inline-flex items-center justify-center"
            style={{ width: size, height: size }}
            aria-label={score === 'new' ? 'New member, no trust score yet' : `Trust score ${score} out of 100`}
        >
            <svg width={size} height={size} className="-rotate-90" aria-hidden>
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--t-border)" strokeWidth={4} />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke="var(--color-success)"
                    strokeWidth={4}
                    strokeLinecap="round"
                    strokeDasharray={`${c * pct} ${c}`}
                />
            </svg>
            <span className="absolute text-xs font-bold tabular">{score === 'new' ? 'New' : score}</span>
        </span>
    );
}
