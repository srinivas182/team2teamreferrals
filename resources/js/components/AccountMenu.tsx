import { Link, router } from '@inertiajs/react';
import { ChevronsUpDown, KeyRound, LogOut, UserRound } from 'lucide-react';
import { Avatar } from '@/components/ui/people';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/** Account pages, also read by the menu-reachability test. */
export const ACCOUNT_LINKS = [
    { href: '/app/settings/account', label: 'My account', icon: UserRound },
    { href: '/app/settings/security', label: 'Password and security', icon: KeyRound },
];

/**
 * The signed-in person at the bottom of the menu: one button that opens My account, Password and security and Sign out.
 * `dark` for the navy admin sidebar.
 */
export function AccountMenu({ name, detail, dark = false, className }: { name: string; detail?: string; dark?: boolean; className?: string }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'flex w-full min-w-0 items-center gap-3 rounded-lg p-2 text-left outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    dark ? 'text-white hover:bg-white/5 data-[state=open]:bg-white/10' : 'hover:bg-bg data-[state=open]:bg-bg',
                    className,
                )}
                aria-label={`Account menu for ${name}`}
            >
                <Avatar name={name} size={32} tone={dark ? 'receiver' : undefined} />
                <span className="min-w-0 flex-1 text-sm">
                    <span className="block truncate font-semibold">{name}</span>
                    {detail && <span className={cn('block truncate', dark ? 'text-[#AAB4CE]' : 'text-muted')}>{detail}</span>}
                </span>
                <ChevronsUpDown className={cn('size-4 shrink-0', dark ? 'text-[#AAB4CE]' : 'text-muted')} aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="w-[var(--radix-dropdown-menu-trigger-width)] min-w-56">
                {ACCOUNT_LINKS.map((l) => (
                    <DropdownMenuItem key={l.href} asChild>
                        <Link href={l.href}>
                            <l.icon className="size-4 text-muted" aria-hidden />
                            {l.label}
                        </Link>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')}>
                    <LogOut className="size-4 text-muted" aria-hidden />
                    Sign out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
