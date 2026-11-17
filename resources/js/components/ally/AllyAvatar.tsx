import { useId } from 'react';

/**
 * Ally's avatar: an original mark — a rounded speech bubble with a friendly face and a small spark, in the brand
 * colours. Decorative wherever the name "Ally" is shown next to it.
 */
export function AllyAvatar({ size = 40, className = '' }: { size?: number; className?: string }) {
    const id = `ally-bg-${useId().replace(/:/g, '')}`;
    return (
        <svg width={size} height={size} viewBox="0 0 48 48" aria-hidden="true" focusable="false" className={className}>
            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stopColor="var(--color-primary, #1d4ed8)" />
                    <stop offset="1" stopColor="var(--color-accent, #f97316)" />
                </linearGradient>
            </defs>
            <circle cx="24" cy="24" r="24" fill={`url(#${id})`} />
            <path d="M13 15.5c0-2.5 2-4.5 4.5-4.5h13c2.5 0 4.5 2 4.5 4.5v10c0 2.5-2 4.5-4.5 4.5H23l-5.5 5v-5h0c-2.5 0-4.5-2-4.5-4.5z" fill="#fff" />
            <circle cx="20" cy="19.5" r="1.9" fill="#1f2a44" />
            <circle cx="28" cy="19.5" r="1.9" fill="#1f2a44" />
            <path d="M19.5 24c1.2 1.5 2.7 2.2 4.5 2.2s3.3-.7 4.5-2.2" stroke="#1f2a44" strokeWidth="1.8" fill="none" strokeLinecap="round" />
            <path d="M37 9.5l1 2.4 2.4 1-2.4 1-1 2.4-1-2.4-2.4-1 2.4-1z" fill="#fff" opacity=".9" />
        </svg>
    );
}
