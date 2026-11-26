import { Link, usePage } from '@inertiajs/react';
import { Logo } from '@/components/brand/Logo';
import { FooterCredit } from '@/components/FooterCredit';
import { cn } from '@/lib/utils';

/** The website footer (menu from Admin → Website → Menus), shared by the public pages and the sign-in pages. */
export function SiteFooter({ className }: { className?: string }) {
    const footerGroups = usePage<{ menus?: { footer?: [string, [string, string][]][] } }>().props.menus?.footer ?? [];
    return (
        <footer className={cn('border-t border-line bg-surface', className)}>
            <div className="mx-auto grid max-w-content gap-10 px-5 py-14 sm:grid-cols-2 sm:px-8 lg:grid-cols-5">
                <div className="lg:col-span-2">
                    <Logo className="h-10 w-[166px] xl:h-12 xl:w-[200px]" />
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
            <FooterCredit className="mx-auto block max-w-content px-5 pb-6 text-center text-sm text-muted sm:px-8" />
        </footer>
    );
}
