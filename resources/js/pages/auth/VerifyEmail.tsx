import { router, useForm } from '@inertiajs/react';
import { MailCheck } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { AuthLayout } from '@/layouts/AuthLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

export default function VerifyEmail({ status, seo }: { status?: string; seo: Seo }) {
    const resend = useForm({});
    return (
        <AuthLayout title="Check your email" subtitle="We sent you a link to confirm your email address. Open it on this device to continue.">
            <SeoHead seo={seo} />
            <MailCheck className="mb-6 size-10 text-primary" aria-hidden />
            {status && (
                <Alert tone="success" className="mb-5">
                    {statusMessage(status)}
                </Alert>
            )}
            <Button
                className="w-full"
                variant="secondary"
                loading={resend.processing}
                onClick={() => resend.post('/email/verification-notification')}
            >
                Send the link again
            </Button>
            <Button className="mt-3 w-full" variant="ghost" onClick={() => router.post('/logout')}>
                Log out
            </Button>
        </AuthLayout>
    );
}
