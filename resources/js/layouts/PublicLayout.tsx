import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Logo } from '@/components/brand/Logo';
import { ThemeToggle } from '@/components/ThemeToggle';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/toaster';
import { TooltipProvider } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

// Header and footer links come from the server (Admin → Website menus, or the built-in ones), keeping only links that open.

export function PublicLayout({ children, current }: { children: ReactNode; current?: string }) {
    // While pre-launch is on, "Claim your city" goes to the reservation page; afterwards, to sign-up.
    const shared = usePage<{ modules?: Record<string, boolean>; menus?: { header?: [string, string][]; footer?: [string, [string, string][]][] } }>()
        .props;
    const claimHref = shared.modules?.prelaunch ? '/reserve' : '/register';
    const header = shared.menus?.header ?? [];
    const footerGroups = shared.menus?.footer ?? [];
    const [open, setOpen] = useState(false);
    return (
        <TooltipProvider>
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2"
            >
                Skip to content
            </a>
            <div className="min-h-screen bg-bg">
                <header className="sticky top-[env(safe-area-inset-top,0px)] z-40 border-b border-line bg-surface/90 backdrop-blur">
                    <div className="mx-auto flex h-16 max-w-content items-center gap-6 px-5 sm:px-8">
                        <Link href="/" aria-label="Team to Team Referrals home" prefetch>
                            <Logo className="h-9 w-[150px]" />
                        </Link>
                        <nav className="ml-4 hidden items-center gap-1 md:flex" aria-label="Main">
                            {header.map(([label, href]) => (
                                <Link
                                    key={href}
                                    href={href}
                                    prefetch
                                    aria-current={current === href ? 'page' : undefined}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-sm font-medium text-muted hover:text-ink',
                                        current === href && 'text-ink',
                                    )}
                                >
                                    {label}
                                </Link>
                            ))}
                        </nav>
                        <div className="ml-auto hidden items-center gap-2 md:flex">
                            <ThemeToggle />
                            <Button variant="ghost" size="sm" asChild>
                                <Link href="/login">Log in</Link>
                            </Button>
                            <Button size="sm" asChild>
                                <Link href={claimHref}>Claim your city</Link>
                            </Button>
                        </div>
                        <button
                            className="ml-auto rounded-md p-2 md:hidden"
                            aria-label={open ? 'Close menu' : 'Open menu'}
                            aria-expanded={open}
                            onClick={() => setOpen(!open)}
                        >
                            {open ? <X /> : <Menu />}
                        </button>
                    </div>
                    {open && (
                        <div className="border-t border-line bg-surface md:hidden">
                            <nav className="mx-auto flex max-w-content flex-col px-5 py-3" aria-label="Main">
                                {header.map(([label, href]) => (
                                    <Link key={href} href={href} className="rounded-md px-2 py-3 font-medium">
                                        {label}
                                    </Link>
                                ))}
                                <div className="mt-2 grid grid-cols-2 gap-2">
                                    <Button variant="secondary" asChild>
                                        <Link href="/login">Log in</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href={claimHref}>Claim your city</Link>
                                    </Button>
                                </div>
                            </nav>
                        </div>
                    )}
                </header>
                <main id="main">{children}</main>
                <footer className="mt-24 border-t border-line bg-surface">
                    <div className="mx-auto grid max-w-content gap-10 px-5 py-14 sm:grid-cols-2 sm:px-8 lg:grid-cols-5">
                        <div className="lg:col-span-2">
                            <Logo className="h-9 w-[150px]" />
                            <p className="mt-4 max-w-sm text-sm text-muted">
                                The referral network for licensed teams. Hand out-of-area leads to teams you trust, and get paid when they close.
                            </p>
                        </div>
                        {footerGroups.map(([title, links]) => (
                            <nav key={title} aria-label={title}>
                                <h2 className="text-sm font-semibold">{title}</h2>
                                <ul className="mt-3 space-y-2">
                                    {links.map(([label, href]) => (
                                        <li key={href}>
                                            <Link href={href} className="text-sm text-muted hover:text-ink">
                                                {label}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </nav>
                        ))}
                    </div>
                    <div className="mx-auto flex max-w-content flex-col gap-2 border-t border-line px-5 py-6 text-sm text-muted sm:flex-row sm:justify-between sm:px-8">
                        <span>© {new Date().getFullYear()} Team to Team Referrals</span>
                        <span>Referral fees are paid broker to broker. We never take a share of your commission.</span>
                    </div>
                </footer>
            </div>
            <Toaster />
        </TooltipProvider>
    );
}
