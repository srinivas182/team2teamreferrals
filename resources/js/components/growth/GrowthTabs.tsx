import { Link } from '@inertiajs/react';

const TABS = [
    { href: '/admin/growth/prospects', label: 'Prospects' },
    { href: '/admin/growth/campaigns', label: 'Campaigns' },
    { href: '/admin/growth/sources', label: 'Sign-up sources' },
    { href: '/admin/growth/settings', label: 'Email settings' },
];

/** Admin → Growth sections. */
export function GrowthTabs({ current }: { current: string }) {
    return (
        <nav className="flex gap-1 overflow-x-auto border-b border-line" aria-label="Growth">
            {TABS.map((t) => (
                <Link
                    key={t.href}
                    href={t.href}
                    aria-current={current === t.href ? 'page' : undefined}
                    className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap ${current === t.href ? 'border-primary' : 'border-transparent text-muted'}`}
                >
                    {t.label}
                </Link>
            ))}
        </nav>
    );
}
