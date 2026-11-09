import { Link, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { AuthLayout } from '@/layouts/AuthLayout';
import type { Seo } from '@/types/seo';

export default function SecureAccount({ action, email, done, seo }: { action: string | null; email: string; done: boolean; seo: Seo }) {
    const form = useForm({});
    if (done) {
        return (
            <AuthLayout
                title="Your account is secured"
                subtitle={<>Every device has been signed out and saved passkeys removed. We emailed {email} a link to choose a new password.</>}
                footer={
                    <Link href="/login" className="font-semibold text-primary">
                        Back to log in
                    </Link>
                }
            >
                <SeoHead seo={seo} />
                <ShieldCheck className="size-10 text-success" aria-hidden />
            </AuthLayout>
        );
    }
    return (
        <AuthLayout
            title="Secure your account?"
            subtitle={<>This signs out every device on {email}, removes saved passkeys, and emails you a link to choose a new password.</>}
        >
            <SeoHead seo={seo} />
            <Button variant="danger" className="w-full" loading={form.processing} onClick={() => action && form.post(action)}>
                Yes, secure my account
            </Button>
            <Button asChild variant="ghost" className="mt-3 w-full">
                <Link href="/login">It was me — cancel</Link>
            </Button>
        </AuthLayout>
    );
}
