import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type CloseD = {
    referral: string;
    client: string;
    territory: string;
    sender: string;
    receiver: string;
    reason: string | null;
    closingDate: string | null;
    value: number | null;
    earnings: number | null;
    agreement: string | null;
};
type FeeD = {
    id: number;
    referral: string;
    client: string;
    payer: string;
    payee: string;
    amount: number;
    basis: number | null;
    reason: string | null;
};

const usd = (n: number | null) => (n === null ? '—' : n.toLocaleString(undefined, { style: 'currency', currency: 'USD' }));

function CloseCard({ d }: { d: CloseD }) {
    const [note, setNote] = useState('');
    const go = (confirm: boolean) => router.post(`/app/disputes/close/${d.referral}`, { confirm, note }, { preserveScroll: true });
    return (
        <Card className="space-y-2 p-5">
            <div className="font-semibold">
                Close disputed: {d.client} · {d.territory}
            </div>
            <p className="text-sm text-muted">
                {d.receiver} marked it Won on {d.closingDate} (value {usd(d.value)}, their earnings {usd(d.earnings)}). {d.sender} says: “{d.reason}”
            </p>
            <div className="flex gap-3 text-sm">
                <a href={`/app/referrals/${d.referral}`} className="font-medium text-primary">
                    Timeline and messages
                </a>
                {d.agreement && (
                    <a href={d.agreement} className="font-medium text-primary">
                        Signed agreement
                    </a>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Input
                    aria-label="Decision note"
                    placeholder="Your reasons (both teams see them)"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    className="min-w-72 flex-1"
                />
                <Button size="sm" disabled={!note.trim()} onClick={() => go(true)}>
                    Confirm the close
                </Button>
                <Button size="sm" variant="secondary" disabled={!note.trim()} onClick={() => go(false)}>
                    Reopen the referral
                </Button>
            </div>
        </Card>
    );
}

function FeeCard({ d }: { d: FeeD }) {
    const [note, setNote] = useState('');
    const [amount, setAmount] = useState('');
    const go = (decision: string) =>
        router.post(`/app/disputes/fee/${d.id}`, { decision, note, amount: decision === 'adjusted' ? amount : null }, { preserveScroll: true });
    return (
        <Card className="space-y-2 p-5">
            <div className="font-semibold">
                Fee disputed: {d.client} · {usd(d.amount)}
            </div>
            <p className="text-sm text-muted">
                {d.payer} owes {d.payee}
                {d.basis !== null ? ` (based on earnings of ${usd(d.basis)})` : ''}. {d.payer} says: “{d.reason}”
            </p>
            <a href={`/app/referrals/${d.referral}`} className="text-sm font-medium text-primary">
                Timeline, messages and agreement
            </a>
            <div className="flex flex-wrap gap-2">
                <Input
                    aria-label="Decision note"
                    placeholder="Your reasons (both teams see them)"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    className="min-w-72 flex-1"
                />
                <Button size="sm" disabled={!note.trim()} onClick={() => go('confirmed')}>
                    Confirm as due
                </Button>
                <Input
                    aria-label="Adjusted amount"
                    type="number"
                    min="0"
                    placeholder="New amount"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className="w-32"
                />
                <Button size="sm" variant="secondary" disabled={!note.trim() || !amount} onClick={() => go('adjusted')}>
                    Adjust
                </Button>
                <Button size="sm" variant="ghost" disabled={!note.trim()} onClick={() => go('waived')}>
                    Waive
                </Button>
            </div>
        </Card>
    );
}

export default function Disputes({
    group,
    closes,
    fees,
    status,
    seo,
}: {
    group: { slug: string; name: string };
    closes: CloseD[];
    fees: FeeD[];
    status?: string;
    seo: Seo;
}) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title={`${group.name}: disputes`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="max-w-4xl space-y-3">
                {closes.length + fees.length === 0 && <Card className="p-8 text-center text-muted">No open disputes.</Card>}
                {closes.map((d) => (
                    <CloseCard key={d.referral} d={d} />
                ))}
                {fees.map((d) => (
                    <FeeCard key={d.id} d={d} />
                ))}
            </div>
        </PortalLayout>
    );
}
