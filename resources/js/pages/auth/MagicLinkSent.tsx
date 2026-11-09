import { Link } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function MagicLinkSent({ email, seo }: { email: string; seo: Seo }) {
    return (
        <AuthLayout
            title="Check your email"
            subtitle={<>If {email} has an account, a sign-in link is on its way. It works once and expires in 15 minutes.</>}
            footer={
                <Link href="/login" className="font-semibold text-primary">
                    Back to log in
                </Link>
            }
        >
            <SeoHead seo={seo} />
            <MailCheck className="size-10 text-primary" aria-hidden />
        </AuthLayout>
    );
}
