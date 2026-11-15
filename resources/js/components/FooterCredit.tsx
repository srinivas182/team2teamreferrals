import { usePage } from '@inertiajs/react';

/** "Powered by MCS": set by super admins in Admin → System → General. Nothing shows when it's switched off. */
export function FooterCredit({ className = '' }: { className?: string }) {
    const credit = usePage<{ credit?: { text: string; url: string | null } | null }>().props.credit;
    if (!credit) return null;
    return (
        <span className={className}>
            {credit.url ? (
                <a href={credit.url} target="_blank" rel="noopener" className="hover:text-ink hover:underline">
                    {credit.text}
                </a>
            ) : (
                credit.text
            )}
        </span>
    );
}
