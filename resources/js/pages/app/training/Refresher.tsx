import { Link, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

/** One-minute refresher before a member's first referral. */
export default function Refresher({ industry, points, seo }: { industry: { slug: string; name: string }; points: string[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Before your first referral" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <Card className="max-w-xl space-y-4 p-6">
                <p className="text-muted">A one-minute reminder of how {industry.name.toLowerCase()} referrals work:</p>
                <ul className="space-y-3">
                    {points.map((p) => (
                        <li key={p} className="flex gap-2">
                            <Check className="mt-0.5 size-5 shrink-0 text-success" aria-hidden /> {p}
                        </li>
                    ))}
                </ul>
                <Button asChild>
                    <Link href="/app">Got it</Link>
                </Button>
            </Card>
        </PortalLayout>
    );
}
