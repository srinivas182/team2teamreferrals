import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BadgeCheck,
    DollarSign,
    Factory,
    GraduationCap,
    LayoutDashboard,
    LifeBuoy,
    Network,
    PanelsTopLeft,
    Rocket,
    Settings2,
    Sparkles,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/brand/Logo';
import { ThemeToggle } from '@/components/ThemeToggle';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

const nav = [
    ['Dashboard', '/admin', LayoutDashboard],
    ['Industries', '/admin/industries', Factory],
    ['Groups and seats', '/admin/groups', Network],
    ['License queue', '/admin/licenses', BadgeCheck],
    ['Network CRM', '/admin/referrals', ArrowLeftRight],
    ['Revenue', '/admin/revenue', DollarSign],
    ['Growth', '/admin/growth', Rocket],
    ['Website builder', '/admin/content', PanelsTopLeft],
    ['Training', '/admin/training', GraduationCap],
    ['AI', '/admin/ai', Sparkles],
    ['Support', '/admin/support', LifeBuoy],
    ['System', '/admin/system', Settings2],
] as const;

/** Super admin area: deliberately distinct navy sidebar so it never looks like a member screen. */
export function AdminLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const { url } = usePage();
    return (
        <TooltipProvider>
            <div className="min-h-screen bg-bg lg:grid lg:grid-cols-[248px_1fr]">
                <aside className="sticky top-0 hidden h-screen flex-col bg-navy-900 text-white lg:flex">
                    <div className="flex items-center gap-2 px-5 py-4">
                        <Logo markOnly className="h-8 w-9 text-white" />
                        <span className="text-sm font-semibold">Super admin</span>
                    </div>
                    <nav className="mt-2 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Admin">
                        {nav.map(([label, href, Icon]) => {
                            const current = href === '/admin' ? url === '/admin' : url.startsWith(href);
                            return (
                                <Link
                                    key={href}
                                    href={href}
                                    prefetch
                                    aria-current={current ? 'page' : undefined}
                                    className={cn(
                                        'flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-[#AAB4CE] hover:bg-white/5 hover:text-white',
                                        current && 'bg-white/10 text-white',
                                    )}
                                >
                                    <Icon className="size-[18px]" aria-hidden />
                                    {label}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>
                <div className="min-w-0">
                    <header className="sticky top-[env(safe-area-inset-top,0px)] z-30 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
                        <span className="lg:hidden">
                            <Logo markOnly className="h-8 w-9" />
                        </span>
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
            <Toaster />
        </TooltipProvider>
    );
}
