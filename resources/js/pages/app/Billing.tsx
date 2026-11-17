import { router, usePage } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import { CardForm, payInvoice } from '@/components/billing/CardForm';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type SeatRow = {
    id: number;
    cycle: string;
    paidUntil: string | null;
    annual: number;
    monthsFree: number;
    territory: string;
    group: string;
    team: string;
    status: string;
    founding: boolean;
    price: number;
    trialEnds: string | null;
};
type Inv = { id: number; number: string; period: string; total: number; status: string; failure: string | null; canPay: boolean };
type Account = {
    type: 'team' | 'company';
    ai: { balance: number; usedToday: number; dailyCap: number; allowance: number; packs: { credits: number; price: number }[] } | null;
    premium: { on: boolean; price: number } | null;
    apiPlan: { on: boolean; price: number; perMinute: number; endpoints: number } | null;
    credit: number;
    coupon: string | null;
    name: string;
    needsNewCard: boolean;
    invoices: Inv[];
    card: { brand: string; last4: string; exp: string } | null;
    seats: SeatRow[];
};

const payNow = (type: string, id: number, stripeKey: string | null, setError: (e: string | null) => void) =>
    payInvoice(`/app/billing/${type}/invoices/${id}/pay`, stripeKey, setError);

const invTone: Record<string, 'success' | 'warning' | 'danger' | 'neutral'> = {
    paid: 'success',
    zero: 'success',
    failed: 'danger',
    action_required: 'warning',
    open: 'warning',
};
const invWords: Record<string, string> = {
    paid: 'Paid',
    zero: 'Nothing to pay',
    failed: 'Payment failed',
    action_required: 'Needs your confirmation',
    open: 'Processing',
};

/** Portal → Billing: cards and seats for each account this person pays for. */
export default function Billing({ accounts, stripeKey, status, seo }: { accounts: Account[]; stripeKey: string | null; status?: string; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState<string | null>(null);
    const [payError, setPayError] = useState<string | null>(null);
    return (
        <PortalLayout title="Billing" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(status)}
                </Alert>
            )}
            {!stripeKey && (
                <Alert tone="info" className="mb-4 max-w-5xl">
                    Payments aren’t switched on yet. You can keep using your free trial.
                </Alert>
            )}
            <div className="max-w-5xl space-y-6">
                {accounts.length === 0 && <Card className="p-6 text-muted">Your team leader manages billing.</Card>}
                {accounts.map((a) => (
                    <Card key={a.type} className="space-y-4 p-6">
                        <div className="flex flex-wrap items-center gap-3">
                            <h2 className="font-semibold">{a.name}</h2>
                            <Badge>{a.type === 'company' ? 'Company account' : 'Team account'}</Badge>
                            <span className="ml-auto text-sm">
                                {a.card ? (
                                    `${a.card.brand.toUpperCase()} ending ${a.card.last4} · expires ${a.card.exp}`
                                ) : (
                                    <span className="text-warning">No card on file</span>
                                )}
                            </span>
                        </div>
                        {stripeKey &&
                            (open === a.type ? (
                                <CardForm
                                    setupUrl={`/app/billing/${a.type}/setup-intent`}
                                    saveUrl={`/app/billing/${a.type}/card`}
                                    stripeKey={stripeKey}
                                    onDone={() => setOpen(null)}
                                />
                            ) : (
                                <Button variant="secondary" size="sm" onClick={() => setOpen(a.type)}>
                                    <CreditCard /> {a.card ? 'Replace card' : 'Add a card'}
                                </Button>
                            ))}
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[640px] text-sm">
                                <thead className="text-left text-muted">
                                    <tr>
                                        {['Seat', 'Network', a.type === 'company' ? 'Branch' : '', 'Price', 'Free trial until', 'Status'].map(
                                            (h, i) => (
                                                <th key={i} scope="col" className="py-2 pr-4 font-medium">
                                                    {h}
                                                </th>
                                            ),
                                        )}
                                    </tr>
                                </thead>
                                <tbody>
                                    {a.seats.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="py-4 text-muted">
                                                No seats are billed to this account.
                                            </td>
                                        </tr>
                                    )}
                                    {a.seats.map((s) => (
                                        <tr key={s.territory + s.group} className="border-t border-line">
                                            <td className="py-2.5 pr-4 font-medium">
                                                {s.territory} {s.founding && <Badge tone="sender">Founding</Badge>}
                                            </td>
                                            <td className="py-2.5 pr-4 text-muted">{s.group}</td>
                                            <td className="py-2.5 pr-4">{a.type === 'company' ? s.team : ''}</td>
                                            <td className="py-2.5 pr-4 tabular">
                                                {s.cycle === 'annual' ? `$${s.annual.toFixed(2)}/yr` : `$${s.price.toFixed(2)}/mo`}
                                                {s.paidUntil && <div className="text-xs text-muted">paid until {s.paidUntil}</div>}
                                                <button
                                                    className="block text-xs font-medium text-primary"
                                                    onClick={() =>
                                                        router.post(
                                                            `/app/billing/${a.type}/seats/${s.id}/cycle`,
                                                            { cycle: s.cycle === 'annual' ? 'monthly' : 'annual' },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                >
                                                    {s.cycle === 'annual' ? 'Switch to monthly' : `Switch to annual (${s.monthsFree} months free)`}
                                                </button>
                                            </td>
                                            <td className="py-2.5 pr-4 tabular">{s.trialEnds ?? '—'}</td>
                                            <td className="py-2.5">
                                                <Badge tone={s.status === 'grace' ? 'warning' : s.status === 'inactive' ? 'danger' : 'success'}>
                                                    {s.status}
                                                </Badge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-xs text-muted">
                            Any month in which no referral is offered to a seat is free (on annual plans, it’s credited to your account).
                        </p>
                        {a.premium && (
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={a.premium.on}
                                    onChange={(e) => router.post('/app/billing/team/premium', { on: e.target.checked }, { preserveScroll: true })}
                                />{' '}
                                Premium membership (${a.premium.price.toFixed(2)}/month, billed from the day it starts)
                            </label>
                        )}
                        {a.apiPlan && (
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={a.apiPlan.on}
                                    onChange={(e) => router.post('/app/billing/team/api-plan', { on: e.target.checked }, { preserveScroll: true })}
                                />
                                <span>
                                    API plan (${a.apiPlan.price.toFixed(2)}/month, billed for the days it’s on): {a.apiPlan.perMinute} API requests a
                                    minute instead of 60, up to {a.apiPlan.endpoints} webhook endpoints instead of 1, and a 30-day request log.{' '}
                                    <a href="/app/developers" className="underline">
                                        Developers (API)
                                    </a>
                                </span>
                            </label>
                        )}
                        {a.ai && (
                            <div className="rounded-md bg-bg p-3 text-sm">
                                <p className="font-medium">AI credits: {a.ai.balance}</p>
                                <p className="text-xs text-muted">
                                    {a.ai.allowance} free this month (unused free credits expire at month end; bought credits don’t) ·{' '}
                                    {a.ai.usedToday} of {a.ai.dailyCap} used today
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {a.ai.packs.map((p) => (
                                        <Button
                                            key={p.credits}
                                            size="sm"
                                            variant="secondary"
                                            onClick={() =>
                                                window.confirm(`Buy ${p.credits} AI credits for $${p.price}? It’s added to your next invoice.`) &&
                                                router.post('/app/billing/team/ai-credits', { credits: p.credits }, { preserveScroll: true })
                                            }
                                        >
                                            {p.credits.toLocaleString()} credits · ${p.price}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            {a.credit > 0 && <span>Account credit: ${a.credit.toFixed(2)}</span>}
                            {a.coupon ? <Badge tone="success">Coupon {a.coupon} applied</Badge> : <CouponForm type={a.type} />}
                        </div>
                        {a.needsNewCard && (
                            <Alert tone="danger">
                                Payments failed three times this year, so a new card is needed before seats can be reactivated.
                            </Alert>
                        )}
                        {a.invoices.length > 0 && (
                            <div className="border-t border-line pt-4">
                                <h3 className="mb-2 text-sm font-semibold">Invoices</h3>
                                <ul className="divide-y divide-line text-sm">
                                    {a.invoices.map((i) => (
                                        <li key={i.id} className="flex flex-wrap items-center gap-3 py-2">
                                            <span className="font-medium">
                                                {new Date(i.period + '-01T00:00').toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
                                            </span>
                                            <span className="tabular">${i.total.toFixed(2)}</span>
                                            <Badge tone={invTone[i.status] ?? 'neutral'}>{invWords[i.status] ?? i.status}</Badge>
                                            {i.failure && <span className="text-xs text-muted">{i.failure}</span>}
                                            <a href={`/app/billing/${a.type}/invoices/${i.id}.pdf`} className="ml-auto text-primary">
                                                PDF
                                            </a>
                                            {i.canPay && stripeKey && (
                                                <Button size="sm" onClick={() => void payNow(a.type, i.id, stripeKey, setPayError)}>
                                                    Pay now
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {payError && <p className="mt-2 text-sm text-danger">{payError}</p>}
                            </div>
                        )}
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}

function CouponForm({ type }: { type: string }) {
    const [code, setCode] = useState('');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return (
        <span className="flex items-center gap-2">
            <input
                aria-label="Coupon code"
                placeholder="Coupon code"
                className="h-9 w-36 rounded-md border border-line bg-surface px-2"
                value={code}
                onChange={(e) => setCode(e.target.value)}
            />
            <Button
                size="sm"
                variant="ghost"
                disabled={!code.trim()}
                onClick={() => router.post(`/app/billing/${type}/coupon`, { code }, { preserveScroll: true })}
            >
                Apply
            </Button>
            {errors.code && <span className="text-xs text-danger">{errors.code}</span>}
        </span>
    );
}
