import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Logo } from '@/components/brand/Logo';
import { TempBadge } from '@/components/ui/badge';
import { Toaster } from '@/components/ui/toaster';

/** Split layout for sign-in and sign-up: form on the left, the product's promise on the right. */
export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <div className="flex flex-col px-6 py-8 sm:px-12">
                <Link href="/" aria-label="Team to Team Referrals home" className="w-fit">
                    <Logo className="h-9 w-[150px]" />
                </Link>
                <main id="main" className="mx-auto my-auto w-full max-w-sm py-10">
                    <h1 className="font-display text-3xl font-extrabold display">{title}</h1>
                    {subtitle && <p className="mt-2 text-muted">{subtitle}</p>}
                    <div className="mt-8">{children}</div>
                    {footer && <div className="mt-6 text-center text-sm text-muted">{footer}</div>}
                </main>
                <p className="flex items-center gap-2 text-xs text-muted">
                    <Lock className="size-3" aria-hidden />
                    Protected with encryption, passkeys and optional two-step sign-in.
                </p>
            </div>
            <aside className="hidden flex-col justify-between bg-navy-900 p-12 text-white lg:flex" aria-label="What members say">
                <div />
                <figure>
                    <blockquote className="font-display text-3xl leading-snug font-bold">
                        “Four relocation leads closed through our group last quarter. Every fee was on the closing report.”
                    </blockquote>
                    <figcaption className="mt-6 text-[#AAB4CE]">Jordan Hale · Red Rock Realty Group, St. George (sample)</figcaption>
                </figure>
                <div className="flex gap-3">
                    <TempBadge t="hot" />
                    <TempBadge t="warm" />
                    <TempBadge t="future" />
                </div>
            </aside>
            <Toaster />
        </div>
    );
}
