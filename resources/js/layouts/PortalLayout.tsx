import { Link, router, usePage } from '@inertiajs/react';
import { Bell, Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Logo } from '@/components/brand/Logo';
import { ThemeToggle } from '@/components/ThemeToggle';
import { CommandPalette, type CommandItem } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Avatar } from '@/components/ui/people';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { useTeamLive } from '@/lib/realtime';
import { mobileTabs, ownerNav, portalNav, type NavItem } from './nav';
import type { SharedProps } from '@/types/auth';

type Props = { title: string; actions?: ReactNode; children: ReactNode; user?: { name: string; team: string }; isGroupOwner?: boolean };

const isCurrent = (url: string, href: string) => (href === '/app' ? url === '/app' : url === href || url.startsWith(href + '/'));

function NavLink({ item, current }: { item: NavItem; current: boolean }) {
    return (
        <Link
            href={item.href}
            prefetch
            aria-current={current ? 'page' : undefined}
            className={cn(
                'flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-muted hover:bg-bg hover:text-ink',
                current && 'bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200',
            )}
        >
            <item.icon className="size-[18px]" aria-hidden />
            {item.label}
            {item.badge ? <span className="ml-auto rounded-full bg-orange-500 px-2 text-xs font-bold text-navy-900">{item.badge}</span> : null}
        </Link>
    );
}

/** Signed-in member area: sidebar on desktop, bottom tabs on phones, command palette on Ctrl/⌘ K. */
export function PortalLayout({ title, actions, children, user = { name: 'Member', team: '' }, isGroupOwner = false }: Props) {
    const { url, props } = usePage<SharedProps>();
    const unread = props.notifications?.unread ?? 0;
    const latest = props.notifications?.latest ?? [];
    const [palette, setPalette] = useState(false);
    useTeamLive();
    const counts = (props as unknown as { referralUnread?: { inbox: number; sent: number } }).referralUnread ?? { inbox: 0, sent: 0 };
    const withBadge = (n: NavItem): NavItem => ({
        ...n,
        badge: n.href === '/app/inbox' ? counts.inbox || undefined : n.href === '/app/sent' ? counts.sent || undefined : n.badge,
    });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPalette((p) => !p);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const commands = useMemo<CommandItem[]>(
        () => [
            { group: 'Actions', label: 'Send a referral', icon: Plus, shortcut: 'S', onSelect: () => router.visit('/app/send') },
            ...[...portalNav, ...(isGroupOwner ? ownerNav : [])].map((n) => ({
                group: 'Go to',
                label: n.label,
                icon: n.icon,
                onSelect: () => router.visit(n.href),
            })),
        ],
        [isGroupOwner],
    );

    return (
        <TooltipProvider>
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2"
            >
                Skip to content
            </a>
            <div className="min-h-screen bg-bg lg:grid lg:grid-cols-[264px_1fr]">
                <aside className="sticky top-0 hidden h-screen flex-col border-r border-line bg-surface lg:flex">
                    <div className="px-5 py-4">
                        <Link href="/app" aria-label="Home">
                            <Logo className="h-8 w-[140px]" />
                        </Link>
                    </div>
                    <div className="px-3">
                        <Link
                            href="/app/send"
                            className="flex h-11 items-center justify-center gap-2 rounded-md bg-primary font-semibold text-on-primary hover:bg-primary-hover"
                        >
                            <Plus className="size-[18px]" aria-hidden />
                            Send referral
                        </Link>
                    </div>
                    <nav className="mt-4 flex-1 space-y-0.5 overflow-y-auto px-3 pb-4" aria-label="Portal">
                        {portalNav.map((n) => (
                            <NavLink key={n.href} item={withBadge(n)} current={isCurrent(url, n.href)} />
                        ))}
                        {isGroupOwner && (
                            <>
                                <div className="px-3 pt-5 pb-1 text-xs font-semibold text-subtle">My group</div>
                                {ownerNav.map((n) => (
                                    <NavLink key={n.href} item={n} current={isCurrent(url, n.href)} />
                                ))}
                            </>
                        )}
                    </nav>
                    <div className="flex items-center gap-3 border-t border-line p-4">
                        <Avatar name={user.name} size={34} />
                        <div className="min-w-0 text-sm">
                            <div className="truncate font-semibold">{user.name}</div>
                            <div className="truncate text-muted">{user.team}</div>
                        </div>
                    </div>
                </aside>
                <div className="min-w-0 pb-24 lg:pb-0">
                    <header className="sticky top-[env(safe-area-inset-top,0px)] z-30 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
                        <Link href="/app" className="lg:hidden" aria-label="Home">
                            <Logo markOnly className="h-8 w-9" />
                        </Link>
                        <button
                            onClick={() => setPalette(true)}
                            className="flex h-10 flex-1 items-center gap-2 rounded-md border border-line bg-bg px-3 text-left text-sm text-subtle sm:max-w-md"
                            aria-label="Search or jump to (Ctrl K)"
                        >
                            <Search className="size-4" aria-hidden />
                            <span className="truncate">Search referrals, teams, cities…</span>
                            <kbd className="ml-auto hidden rounded border border-line px-1.5 text-xs sm:inline">Ctrl K</kbd>
                        </button>
                        <div className="ml-auto flex items-center gap-1">
                            <ThemeToggle />
                            <Popover>
                                <PopoverTrigger
                                    className="relative rounded-md p-2 text-muted hover:bg-bg hover:text-ink"
                                    aria-label={unread ? `Notifications, ${unread} unread` : 'Notifications'}
                                >
                                    <Bell className="size-5" />
                                    {unread > 0 && (
                                        <span className="absolute -top-0.5 -right-0.5 min-w-5 rounded-full bg-orange-500 px-1 text-center text-[11px] leading-5 font-bold text-navy-900 tabular">
                                            {unread > 99 ? '99+' : unread}
                                        </span>
                                    )}
                                </PopoverTrigger>
                                <PopoverContent>
                                    <div className="flex items-center border-b border-line px-4 py-3">
                                        <span className="font-semibold">Notifications</span>
                                        {unread > 0 && (
                                            <button
                                                className="ml-auto text-sm font-medium text-primary"
                                                onClick={() => router.post('/app/notifications/read-all', {}, { preserveScroll: true })}
                                            >
                                                Mark all read
                                            </button>
                                        )}
                                    </div>
                                    {latest.length === 0 ? (
                                        <p className="px-4 py-8 text-center text-sm text-muted">
                                            You’re all caught up. New referrals and updates appear here.
                                        </p>
                                    ) : (
                                        <ul className="max-h-96 divide-y divide-line overflow-y-auto">
                                            {latest.map((n) => (
                                                <li key={n.id}>
                                                    <button
                                                        onClick={() => router.post(`/app/notifications/${n.id}/read`)}
                                                        className={cn(
                                                            'block w-full px-4 py-3 text-left hover:bg-bg',
                                                            !n.read && 'bg-cobalt-50/60 dark:bg-cobalt-500/10',
                                                        )}
                                                    >
                                                        <div className="text-sm font-medium">{n.title}</div>
                                                        <div className="line-clamp-2 text-sm text-muted">{n.body}</div>
                                                        <div className="mt-1 text-xs text-subtle">{n.ago}</div>
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <div className="flex border-t border-line text-sm">
                                        <Link href="/app/notifications" className="flex-1 px-4 py-3 text-center font-medium text-primary hover:bg-bg">
                                            See all
                                        </Link>
                                        <Link
                                            href="/app/settings/notifications"
                                            className="flex-1 border-l border-line px-4 py-3 text-center text-muted hover:bg-bg"
                                        >
                                            Settings
                                        </Link>
                                    </div>
                                </PopoverContent>
                            </Popover>
                        </div>
                    </header>
                    <main id="main" className="px-4 py-6 sm:px-6 lg:px-8">
                        <div className="mb-6 flex flex-wrap items-center gap-3">
                            <h1 className="font-display text-2xl font-bold display sm:text-[28px]">{title}</h1>
                            <div className="ml-auto flex gap-2">{actions}</div>
                        </div>
                        {children}
                    </main>
                </div>
                <nav
                    className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-line bg-surface pb-[env(safe-area-inset-bottom,0px)] lg:hidden"
                    aria-label="Portal"
                >
                    {mobileTabs.map((t) => (
                        <Link
                            key={t.href}
                            href={t.href}
                            aria-current={isCurrent(url, t.href) ? 'page' : undefined}
                            className={cn(
                                'flex flex-col items-center gap-1 py-2 text-xs font-medium text-muted',
                                isCurrent(url, t.href) && 'text-primary',
                            )}
                        >
                            {t.label === 'Send' ? (
                                <span className="-mt-5 flex size-12 items-center justify-center rounded-full bg-primary text-on-primary shadow-md">
                                    <Plus className="size-6" aria-hidden />
                                </span>
                            ) : (
                                <t.icon className="size-5" aria-hidden />
                            )}
                            {t.label}
                        </Link>
                    ))}
                </nav>
            </div>
            <CommandPalette open={palette} onOpenChange={setPalette} items={commands} remote />
            <Toaster />
        </TooltipProvider>
    );
}
