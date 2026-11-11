import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = {
    stats: { revenueMonth: number; revenueYear: number; freeMonthsLastRun: number; failed: number; needNewCard: number; platformFees: number };
    failed: { number: string; payer: string; total: number; attempts: number; failure: string | null }[];
    invoices: { id: number; number: string; payer: string; total: number; refunded: number }[];
    coupons: {
        id: number;
        code: string;
        percent_off: number | null;
        amount_off_cents: number | null;
        months: number;
        max_redemptions: number | null;
        redeemed: number;
        expires_on: string | null;
        active: boolean;
    }[];
    settings: {
        platformFeeCollection: boolean;
        stripeTax: boolean;
        premiumPrice: number;
        privateNetworkPrice: number;
        inviteRewardMonths: number;
        inviteCap: number;
        ownerPerSeats: number;
    };
    isSuperAdmin: boolean;
    status?: string;
    seo: Seo;
};

const usd = (n: number) => n.toLocaleString(undefined, { style: 'currency', currency: 'USD' });
const post = (url: string, data: Record<string, unknown>) => router.post(url, data as never, { preserveScroll: true });

function Refund({ inv }: { inv: Props['invoices'][number] }) {
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Input
                aria-label="Refund amount"
                type="number"
                min="0"
                placeholder="Amount"
                className="h-8 w-24"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
            />
            <Input
                aria-label="Refund reason"
                placeholder="Reason (the payer sees it)"
                className="h-8 w-56"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
            />
            <Button
                size="sm"
                variant="secondary"
                disabled={!amount || reason.trim().length < 5}
                onClick={() =>
                    window.confirm(`Refund $${amount} on ${inv.number}?`) && post(`/admin/billing/invoices/${inv.id}/refund`, { amount, reason })
                }
            >
                Refund
            </Button>
        </div>
    );
}

/** Admin → Billing. */
export default function AdminBilling({ stats, failed, invoices, coupons, settings, isSuperAdmin, status, seo }: Props) {
    const prices = useForm({
        premium_price: String(settings.premiumPrice),
        private_network_price: String(settings.privateNetworkPrice),
        invite_reward_months: String(settings.inviteRewardMonths),
        invite_cap: String(settings.inviteCap),
        owner_per_seats: String(settings.ownerPerSeats),
    });
    const coupon = useForm({ code: '', percent_off: '', amount_off: '', months: '1', max_redemptions: '', expires_on: '' });
    return (
        <AdminLayout title="Billing">
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                {[
                    ['Revenue this month', usd(stats.revenueMonth)],
                    ['Revenue this year', usd(stats.revenueYear)],
                    ['Free months (last run)', stats.freeMonthsLastRun],
                    ['Failed payments', stats.failed],
                    ['Need a new card', stats.needNewCard],
                    ['Platform fees collected', usd(stats.platformFees)],
                ].map(([l, v]) => (
                    <Card key={l as string} className="p-4">
                        <div className="font-display text-xl font-bold tabular">{v}</div>
                        <div className="text-xs text-muted">{l}</div>
                    </Card>
                ))}
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="space-y-3 p-6">
                    <h2 className="font-semibold">Switches and prices</h2>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            disabled={!isSuperAdmin}
                            checked={settings.platformFeeCollection}
                            onChange={(e) => post('/admin/billing/settings', { platform_fee_collection: e.target.checked })}
                        />{' '}
                        Collect platform fees
                        <span className="text-xs text-muted">(super admin only, after legal sign-off)</span>
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={settings.stripeTax}
                            onChange={(e) => post('/admin/billing/settings', { stripe_tax: e.target.checked })}
                        />{' '}
                        Calculate sales tax with Stripe Tax
                        <span className="text-xs text-muted">(switch on at launch)</span>
                    </label>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Premium membership ($/month)" error={prices.errors.premium_price}>
                            <Input
                                type="number"
                                min="0"
                                value={prices.data.premium_price}
                                onChange={(e) => prices.setData('premium_price', e.target.value)}
                            />
                        </Field>
                        <Field label="Private network ($/month)" error={prices.errors.private_network_price}>
                            <Input
                                type="number"
                                min="0"
                                value={prices.data.private_network_price}
                                onChange={(e) => prices.setData('private_network_price', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-3 gap-3">
                        <Field label="Invite reward (free months)">
                            <Input
                                type="number"
                                min="0"
                                value={prices.data.invite_reward_months}
                                onChange={(e) => prices.setData('invite_reward_months', e.target.value)}
                            />
                        </Field>
                        <Field label="Yearly cap per team">
                            <Input
                                type="number"
                                min="0"
                                value={prices.data.invite_cap}
                                onChange={(e) => prices.setData('invite_cap', e.target.value)}
                            />
                        </Field>
                        <Field label="Owner: 1 month per N paid seats">
                            <Input
                                type="number"
                                min="1"
                                value={prices.data.owner_per_seats}
                                onChange={(e) => prices.setData('owner_per_seats', e.target.value)}
                            />
                        </Field>
                    </div>
                    <p className="text-xs text-muted">
                        Invite rewards are paid once the invitee’s licence is verified and they accept a first referral. Teams in restricted (Tier B)
                        industries get the Network builder badge instead.
                    </p>
                    <Button size="sm" variant="secondary" onClick={() => prices.post('/admin/billing/settings', { preserveScroll: true })}>
                        Save prices and rewards
                    </Button>
                </Card>
                <Card className="space-y-3 p-6">
                    <h2 className="font-semibold">Coupons</h2>
                    <div className="grid grid-cols-3 gap-2">
                        <Input
                            aria-label="Code"
                            placeholder="CODE"
                            value={coupon.data.code}
                            onChange={(e) => coupon.setData('code', e.target.value)}
                        />
                        <Input
                            aria-label="Percent off"
                            type="number"
                            placeholder="% off"
                            value={coupon.data.percent_off}
                            onChange={(e) => coupon.setData('percent_off', e.target.value)}
                        />
                        <Input
                            aria-label="Amount off"
                            type="number"
                            placeholder="or $ off"
                            value={coupon.data.amount_off}
                            onChange={(e) => coupon.setData('amount_off', e.target.value)}
                        />
                        <Input
                            aria-label="Months"
                            type="number"
                            placeholder="Months"
                            value={coupon.data.months}
                            onChange={(e) => coupon.setData('months', e.target.value)}
                        />
                        <Input
                            aria-label="Max uses"
                            type="number"
                            placeholder="Max uses"
                            value={coupon.data.max_redemptions}
                            onChange={(e) => coupon.setData('max_redemptions', e.target.value)}
                        />
                        <Input
                            aria-label="Expires on"
                            type="date"
                            value={coupon.data.expires_on}
                            onChange={(e) => coupon.setData('expires_on', e.target.value)}
                        />
                    </div>
                    {Object.values(coupon.errors)[0] && <p className="text-sm text-danger">{Object.values(coupon.errors)[0]}</p>}
                    <Button
                        size="sm"
                        onClick={() => coupon.post('/admin/billing/coupons', { preserveScroll: true, onSuccess: () => coupon.reset() })}
                    >
                        Create coupon
                    </Button>
                    <ul className="divide-y divide-line text-sm">
                        {coupons.map((c) => (
                            <li key={c.id} className="flex items-center gap-2 py-2">
                                <span className="font-mono font-medium">{c.code}</span>
                                <span className="text-muted">
                                    {c.percent_off ? `${c.percent_off}%` : usd((c.amount_off_cents ?? 0) / 100)} off · {c.months} mo · used{' '}
                                    {c.redeemed}
                                    {c.max_redemptions ? `/${c.max_redemptions}` : ''}
                                    {c.expires_on ? ` · until ${c.expires_on}` : ''}
                                </span>
                                <Badge tone={c.active ? 'success' : 'neutral'}>{c.active ? 'active' : 'off'}</Badge>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() => post(`/admin/billing/coupons/${c.id}/toggle`, {})}
                                >
                                    {c.active ? 'Switch off' : 'Switch on'}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card className="p-6">
                    <h2 className="mb-2 font-semibold">Failed payments</h2>
                    {failed.length === 0 && <p className="text-sm text-muted">None.</p>}
                    <ul className="divide-y divide-line text-sm">
                        {failed.map((f) => (
                            <li key={f.number} className="py-2">
                                <span className="font-medium">{f.payer}</span> · {f.number} · {usd(f.total)} · {f.attempts} attempt
                                {f.attempts === 1 ? '' : 's'}
                                {f.failure && <div className="text-xs text-muted">{f.failure}</div>}
                            </li>
                        ))}
                    </ul>
                </Card>
                <Card className="p-6">
                    <h2 className="mb-2 font-semibold">Recent payments and refunds</h2>
                    <ul className="divide-y divide-line text-sm">
                        {invoices.map((i) => (
                            <li key={i.id} className="space-y-1.5 py-2">
                                <div>
                                    <span className="font-medium">{i.payer}</span> · {i.number} · {usd(i.total)}
                                    {i.refunded > 0 && <span className="text-muted"> · refunded {usd(i.refunded)}</span>}
                                </div>
                                {i.refunded < i.total && <Refund inv={i} />}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </AdminLayout>
    );
}
