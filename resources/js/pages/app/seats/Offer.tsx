import { router, usePage } from '@inertiajs/react';
import { Timer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { money } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    offer: { id: number; status: string; expiresAt: string; territory: string; group: string; pricing: { seat_price: number; trial_months: number } };
    seo: Seo;
};

/** A seat offered to this team, with a live countdown. Unclaimed offers pass to the next team automatically. */
export default function Offer({ offer, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(t);
    }, []);
    const left = Math.max(0, new Date(offer.expiresAt.replace(' ', 'T') + 'Z').getTime() - now);
    const open = offer.status === 'pending' && left > 0;
    const h = Math.floor(left / 3.6e6);
    const m = Math.floor((left % 3.6e6) / 6e4);
    return (
        <PortalLayout title="A seat is yours to claim" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <Card className="max-w-xl p-6">
                <h2 className="font-display text-2xl font-bold">{offer.territory}</h2>
                <p className="text-muted">{offer.group}</p>
                {open ? (
                    <>
                        <p className="mt-4 flex items-center gap-2 font-medium tabular" role="timer">
                            <Timer className="size-5 text-warning" aria-hidden /> {h}h {m}m left to claim
                        </p>
                        <p className="mt-2 text-sm text-muted">
                            {money(offer.pricing.seat_price)} a month after a {offer.pricing.trial_months}-month free trial. If you don’t claim it in
                            time, it goes to the next team.
                        </p>
                        <div className="mt-6 flex gap-2">
                            <Button onClick={() => router.post(`/app/seats/offers/${offer.id}/accept`)}>Claim this seat</Button>
                            <Button variant="ghost" onClick={() => router.post(`/app/seats/offers/${offer.id}/decline`)}>
                                No thanks
                            </Button>
                        </div>
                    </>
                ) : (
                    <Alert tone="info" className="mt-4">
                        This offer is no longer open ({offer.status === 'pending' ? 'expired' : offer.status}).
                    </Alert>
                )}
            </Card>
        </PortalLayout>
    );
}
