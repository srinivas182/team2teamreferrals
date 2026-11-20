import { usePage } from '@inertiajs/react';

type Credit = { text: string; url: string | null; linkText: string | null };

/** The footer credit: set by super admins in Admin → System → General. Nothing shows when it's switched off. */
export function FooterCredit({ className = '' }: { className?: string }) {
    const credit = usePage<{ credit?: Credit | null }>().props.credit;
    if (!credit) return null;
    const link = (label: string) => (
        <a href={credit.url ?? undefined} target="_blank" rel="noopener" className="font-medium text-ink underline-offset-2 hover:underline">
            {label}
        </a>
    );
    if (!credit.url) return <span className={className}>{credit.text}</span>;
    // Only the chosen words are the link (e.g. "Developed and maintained by <Company>"); otherwise the whole text.
    const at = credit.linkText ? credit.text.indexOf(credit.linkText) : -1;
    if (credit.linkText && at >= 0) {
        return (
            <span className={className}>
                {credit.text.slice(0, at)}
                {link(credit.linkText)}
                {credit.text.slice(at + credit.linkText.length)}
            </span>
        );
    }
    return <span className={className}>{link(credit.text)}</span>;
}
