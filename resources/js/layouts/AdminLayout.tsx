import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CommandPalette } from '@/components/ui/command';
import {
    ArrowLeftRight,
    Search,
    Send,
    Settings2,
    BadgeCheck,
    DollarSign,
    Factory,
    GraduationCap,
    LayoutDashboard,
    LifeBuoy,
    MapPin,
    Network,
    PanelsTopLeft,
    Rocket,
    ScrollText,
    Sparkles,
    UserCog,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/brand/Logo';
import { ThemeToggle } from '@/components/ThemeToggle';
import { Avatar } from '@/components/ui/people';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';

type Item = { label: string; href: string; icon: typeof LayoutDashboard; permission: string | null; ready: boolean };

/** Full admin map (docs/design/information-architecture.md). Items appear when built and permitted. */
const nav: Item[] = [
    { label: 'Dashboard', href: '/admin', icon: LayoutDashboard, permission: null, ready: true },
    { label: 'Industries', href: '/admin/industries', icon: Factory, permission: 'industries.manage', ready: false },
    { label: 'Groups and seats', href: '/admin/groups', icon: Network, permission: 'groups.manage', ready: false },
    { label: 'Locations', href: '/admin/locations', icon: MapPin, permission: 'locations.manage', ready: true },
    { label: 'License queue', href: '/admin/licenses', icon: BadgeCheck, permission: 'licenses.verify', ready: false },
    { label: 'Network CRM', href: '/admin/referrals', icon: ArrowLeftRight, permission: 'referrals.view', ready: false },
    { label: 'Revenue', href: '/admin/revenue', icon: DollarSign, permission: 'revenue.view', ready: false },
    { label: 'Growth', href: '/admin/growth', icon: Rocket, permission: 'growth.manage', ready: false },
    { label: 'Website builder', href: '/admin/content', icon: PanelsTopLeft, permission: 'content.manage', ready: false },
    { label: 'Training', href: '/admin/training', icon: GraduationCap, permission: 'training.manage', ready: false },
    { label: 'AI', href: '/admin/ai', icon: Sparkles, permission: 'ai.manage', ready: false },
    { label: 'Support', href: '/admin/support', icon: LifeBuoy, permission: 'support.manage', ready: false },
    { label: 'Staff', href: '/admin/staff', icon: UserCog, permission: 'staff.manage', ready: true },
    { label: 'Audit log', href: '/admin/audit', icon: ScrollText, permission: 'audit.view', ready: true },
    { label: 'Templates', href: '/admin/templates', icon: PanelsTopLeft, permission: 'content.manage', ready: true },
    { label: 'Delivery log', href: '/admin/deliveries', icon: Send, permission: 'settings.manage', ready: true },
    { label: 'System', href: '/admin/system', icon: Settings2, permission: 'settings.manage', ready: true },
];

/** Super admin area: deliberately distinct navy sidebar so it never looks like a member screen. */
export function AdminLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { url, props } = usePage<SharedProps>();
    const user = props.auth.user;
    const [palette, setPalette] = useState(false);
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
    const allowed = nav.filter((n) => n.ready && (n.permission === null || user?.permissions.includes(n.permission)));
    return (
        <TooltipProvider>
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2"
            >
                Skip to content
            </a>
            <div className="min-h-screen bg-bg lg:grid lg:grid-cols-[248px_1fr]">
                <aside className="sticky top-0 hidden h-screen flex-col bg-navy-900 text-white lg:flex">
                    <div className="flex items-center gap-2 px-5 py-4">
                        <Logo markOnly className="h-8 w-9 text-white" />
                        <span className="text-sm font-semibold">Admin</span>
                    </div>
                    <nav className="mt-2 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Admin">
                        {allowed.map((n) => {
                            const current = n.href === '/admin' ? url === '/admin' : url.startsWith(n.href);
                            return (
                                <Link
                                    key={n.href}
                                    href={n.href}
                                    prefetch
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-[#AAB4CE] hover:bg-white/5 hover:text-white',
                                        current && 'bg-white/10 text-white',
                                    )}
                                >
                                    <n.icon className="size-[18px]" aria-hidden />
                                    {n.label}
                                </Link>
                            );
                        })}
                    </nav>
                    {user && (
                        <div className="flex items-center gap-3 border-t border-white/10 p-4">
                            <Avatar name={user.name} size={32} tone="receiver" />
                            <div className="min-w-0 text-sm">
                                <div className="truncate font-semibold">{user.name}</div>
                                <Link href="/app/settings/security" className="text-[#AAB4CE] hover:text-white">
                                    Security settings
                                </Link>
                            </div>
                        </div>
                    )}
                </aside>
                <div className="min-w-0">
                    <header className="sticky top-[env(safe-area-inset-top,0px)] z-30 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
                        <span className="lg:hidden">
                            <Logo markOnly className="h-8 w-9" />
                        </span>
                        <nav className="flex gap-1 overflow-x-auto lg:hidden" aria-label="Admin">
                            {allowed.map((n) => (
                                <Link
                                    key={n.href}
                                    href={n.href}
                                    className="rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap text-muted hover:text-ink"
                                >
                                    {n.label}
                                </Link>
                            ))}
                        </nav>
                        <button
                            onClick={() => setPalette(true)}
                            className="hidden h-10 flex-1 items-center gap-2 rounded-md border border-line bg-bg px-3 text-left text-sm text-subtle sm:flex sm:max-w-md"
                            aria-label="Search people and settings (Ctrl K)"
                        >
                            <Search className="size-4" aria-hidden />
                            <span className="truncate">Search people, settings…</span>
                            <kbd className="ml-auto rounded border border-line px-1.5 text-xs">Ctrl K</kbd>
                        </button>
                        <div className="ml-auto">
                            <ThemeToggle />
                        </div>
                    </header>
                    <main id="main" className="px-4 py-6 sm:px-6 lg:px-8">
                        <div className="mb-6 flex flex-wrap items-center gap-3">
                            <h1 className="font-display text-2xl font-bold display sm:text-[28px]">{title}</h1>
                            <div className="ml-auto flex flex-wrap gap-2">{actions}</div>
                        </div>
                        {children}
                    </main>
                </div>
            </div>
            <CommandPalette
                open={palette}
                onOpenChange={setPalette}
                items={allowed.map((n) => ({ group: 'Go to', label: n.label, icon: n.icon, onSelect: () => router.visit(n.href) }))}
                remote
            />
            <Toaster />
        </TooltipProvider>
    );
}
