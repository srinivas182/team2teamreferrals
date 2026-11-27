import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, CreditCard } from 'lucide-react';
import { useState } from 'react';
import { CardForm, payInvoice } from '@/components/billing/CardForm';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    locked: boolean;
    team: string;
    canPay: boolean;
    leader: string;
    seats: { territory: string; group: string; releasedOn: string | null }[];
    missed: { client: string; date: string; territory: string; group: string }[];
    invoices: { id: number; number: string; total: number; period: string; failure: string | null }[];
    card: { brand: string; last4: string; payer: string } | null;
    stripeKey: string | null;
    status?: string | null;
    seo: Seo;
};

const usd = (cents: number) => (cents / 100).toLocaleString('en-US', { style: 'currency', currency: 'USD' });

/** Renew: an unpaid team's seats, the referrals it missed while inactive, and paying to get them back. */
export default function Renew({ locked, team, canPay, leader, seats, missed, invoices, card, stripeKey, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [error, setError] = useState<string | null>(null);
    const [paying, setPaying] = useState<number | null>(null);
    const [changing, setChanging] = useState(false);
    return (
        <PortalLayout title="Renew your seats" user={{ name: auth.user?.name ?? '', team }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                {seats.length === 0 ? (
                    <Card className="p-6">
                        <h2 className="font-semibold">You’re all set</h2>
                        <p className="mt-1 text-muted">None of your seats need renewing.</p>
                        <Button asChild className="mt-4">
                            <Link href="/app">Go to your home page</Link>
                        </Button>
                    </Card>
                ) : (
                    <Card className="space-y-3 border-danger/40 p-6">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 size-5 shrink-0 text-danger" aria-hidden />
                            <div>
                                <h2 className="font-semibold">
                                    {locked ? 'Your team’s seats are inactive until you renew' : 'Some of your seats are inactive'}
                                </h2>
                                <p className="mt-1 text-sm text-muted">
                                    We couldn’t take payment. New referrals for {seats.length === 1 ? 'this seat go' : 'these seats go'} to the backup
                                    team, and other teams can’t send to you. {locked && 'You can still work the clients you’d already accepted.'}{' '}
                                    Paying brings {seats.length === 1 ? 'it' : 'them'} back at once.
                                </p>
                            </div>
                        </div>
                        <ul className="divide-y divide-line text-sm">
                            {seats.map((s) => (
                                <li key={`${s.group}${s.territory}`} className="flex flex-wrap justify-between gap-2 py-2">
                                    <span>
                                        <strong>{s.territory}</strong> · {s.group}
                                    </span>
                                    {s.releasedOn && <span className="text-muted">Offered to the next team on {s.releasedOn} if unpaid</span>}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                {seats.length > 0 && (
                    <Card className="space-y-4 p-6">
                        <h2 className="font-semibold">Renew</h2>
                        {!canPay ? (
                            <p className="text-sm text-muted">Your team leader, {leader}, can renew from this page. Let them know.</p>
                        ) : (
                            <>
                                <p className="text-sm">
                                    {card ? (
                                        <>
                                            Card: {card.brand.toUpperCase()} ending {card.last4}{' '}
                                            {stripeKey && card.payer === 'team' && !changing && (
                                                <button type="button" className="font-medium text-primary" onClick={() => setChanging(true)}>
                                                    Use a different card
                                                </button>
                                            )}
                                        </>
                                    ) : (
                                        <span className="text-danger">No working card on file. Add one, then pay.</span>
                                    )}
                                </p>
                                {stripeKey && (!card || changing) && (
                                    <CardForm
                                        setupUrl="/app/billing/team/setup-intent"
                                        saveUrl="/app/billing/team/card"
                                        stripeKey={stripeKey}
                                        label="Add a card"
                                        onDone={() => setChanging(false)}
                                    />
                                )}
                                {invoices.length === 0 ? (
                                    <p className="text-sm text-muted">
                                        Nothing to pay right now. If your company pays for your seats, ask its admin to update its card on{' '}
                                        <Link href="/app/billing" className="text-primary">
                                            Billing
                                        </Link>
                                        .
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-line text-sm">
                                        {invoices.map((i) => (
                                            <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
                                                <span>
                                                    {i.number} · {i.period} · <strong>{usd(i.total)}</strong>
                                                    {i.failure && <span className="block text-xs text-muted">Last try: {i.failure}</span>}
                                                </span>
                                                <Button
                                                    size="sm"
                                                    disabled={!card || !stripeKey}
                                                    loading={paying === i.id}
                                                    onClick={() => {
                                                        setPaying(i.id);
                                                        void payInvoice(`/app/billing/team/invoices/${i.id}/pay`, stripeKey, setError).finally(() =>
                                                            setPaying(null),
                                                        );
                                                    }}
                                                >
                                                    <CreditCard /> Renew: pay {usd(i.total)}
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {error && <p className="text-sm text-danger">{error}</p>}
                            </>
                        )}
                    </Card>
                )}

                <Card className="p-6">
                    <h2 className="font-semibold">
                        {missed.length > 0
                            ? `You missed ${missed.length} ${missed.length === 1 ? 'referral' : 'referrals'} while inactive`
                            : 'No missed referrals in the last 90 days'}
                    </h2>
                    {missed.length > 0 && (
                        <table className="mt-3 w-full text-sm">
                            <caption className="sr-only">Referrals you missed while your seat was inactive</caption>
                            <thead className="text-left text-muted">
                                <tr>
                                    {['Date', 'Client', 'City', 'Network'].map((h) => (
                                        <th key={h} scope="col" className="py-2 pr-4 font-medium">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-line">
                                {missed.map((m, i) => (
                                    <tr key={i}>
                                        <td className="py-2 pr-4 tabular-nums">{m.date}</td>
                                        <td className="py-2 pr-4">{m.client}</td>
                                        <td className="py-2 pr-4">{m.territory}</td>
                                        <td className="py-2 pr-4">{m.group}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
