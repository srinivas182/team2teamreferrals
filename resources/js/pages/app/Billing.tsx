import { router, usePage } from '@inertiajs/react';
import { CreditCard, Lock } from 'lucide-react';
import { useRef, useState } from 'react';
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
    credit: number;
    coupon: string | null;
    name: string;
    needsNewCard: boolean;
    invoices: Inv[];
    card: { brand: string; last4: string; exp: string } | null;
    seats: SeatRow[];
};

// Stripe.js is loaded from Stripe only on this page (the security policy allows it here alone).
type StripeJs = {
    handleNextAction: (o: { clientSecret: string }) => Promise<{ error?: { message: string } }>;
    elements: (o: { clientSecret: string }) => { create: (t: string) => { mount: (el: HTMLElement) => void } };
    confirmSetup: (o: {
        elements: unknown;
        redirect: 'if_required';
    }) => Promise<{ error?: { message: string }; setupIntent?: { payment_method: string } }>;
};
declare global {
    interface Window {
        Stripe?: (key: string) => StripeJs;
    }
}
function loadStripe(key: string): Promise<StripeJs> {
    return new Promise((resolve, reject) => {
        if (window.Stripe) return resolve(window.Stripe(key));
        const s = document.createElement('script');
        s.src = 'https://js.stripe.com/v3';
        s.onload = () => (window.Stripe ? resolve(window.Stripe(key)) : reject(new Error('Stripe failed to load')));
        s.onerror = () => reject(new Error('Stripe failed to load'));
        document.head.appendChild(s);
    });
}
const xsrf = () =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

function CardForm({ type, stripeKey, onDone }: { type: string; stripeKey: string; onDone: () => void }) {
    const box = useRef<HTMLDivElement>(null);
    const [state, setState] = useState<{ stripe: StripeJs; elements: unknown } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const start = async () => {
        setError(null);
        try {
            const res = await fetch(`/app/billing/${type}/setup-intent`, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            });
            if (!res.ok) throw new Error('Could not start. Please try again.');
            const { clientSecret } = (await res.json()) as { clientSecret: string };
            const stripe = await loadStripe(stripeKey);
            const elements = stripe.elements({ clientSecret });
            elements.create('payment').mount(box.current as HTMLElement);
            setState({ stripe, elements });
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const save = async () => {
        if (!state) return;
        setBusy(true);
        const r = await state.stripe.confirmSetup({ elements: state.elements, redirect: 'if_required' });
        setBusy(false);
        if (r.error) return setError(r.error.message);
        router.post(`/app/billing/${type}/card`, { payment_method: r.setupIntent?.payment_method }, { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <div className="space-y-3">
            {!state && (
                <Button variant="secondary" size="sm" onClick={() => void start()}>
                    <CreditCard /> Add or replace card
                </Button>
            )}
            <div ref={box} />
            {state && (
                <Button size="sm" loading={busy} onClick={() => void save()}>
                    Save card
                </Button>
            )}
            {error && <p className="text-sm text-danger">{error}</p>}
            <p className="flex items-center gap-1.5 text-xs text-muted">
                <Lock className="size-3" aria-hidden /> Entered securely with Stripe. Your card details never reach our servers.
            </p>
        </div>
    );
}

async function payNow(type: string, id: number, stripeKey: string | null, setError: (e: string | null) => void) {
    setError(null);
    const res = await fetch(`/app/billing/${type}/invoices/${id}/pay`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    const json = (await res.json().catch(() => null)) as { clientSecret?: string } | null;
    // The bank wants the customer to confirm: Stripe shows its own authentication screen.
    if (json?.clientSecret && stripeKey) {
        const stripe = await loadStripe(stripeKey);
        const r = await stripe.handleNextAction({ clientSecret: json.clientSecret });
        if (r.error) setError(r.error.message);
    }
    router.reload();
}

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
                                <CardForm type={a.type} stripeKey={stripeKey} onDone={() => setOpen(null)} />
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
                                            <td className="py-2.5 pr-4 tabular">${s.price.toFixed(2)}/mo</td>
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
