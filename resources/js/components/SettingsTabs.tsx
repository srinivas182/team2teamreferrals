import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const tabs = [
    { href: '/app/settings/account', label: 'My account', short: 'Account' },
    { href: '/app/settings/security', label: 'Password and security', short: 'Security' },
    { href: '/app/settings/notifications', label: 'Notifications', short: 'Notifications' },
];

/** The three account pages, one row of tabs (short names on phones so all three fit). */
export function SettingsTabs() {
    const { url } = usePage();
    return (
        <nav aria-label="Account settings" className="-mx-1 mb-6 flex gap-1 overflow-x-auto border-b border-line px-1">
            {tabs.map((t) => {
                const current = url.startsWith(t.href);
                return (
                    <Link
                        key={t.href}
                        href={t.href}
                        aria-current={current ? 'page' : undefined}
                        className={cn(
                            '-mb-px inline-flex min-h-11 items-center border-b-2 px-3 text-sm font-medium whitespace-nowrap',
                            current ? 'border-primary text-ink' : 'border-transparent text-muted hover:text-ink',
                        )}
                    >
                        <span className="sm:hidden">{t.short}</span>
                        <span className="hidden sm:inline">{t.label}</span>
                    </Link>
                );
            })}
        </nav>
    );
}
