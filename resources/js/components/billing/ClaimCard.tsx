import { useState } from 'react';
import { CardForm } from '@/components/billing/CardForm';
import { money, months } from '@/lib/utils';

export type ClaimBilling = {
    stripeKey: string | null;
    card: { brand: string; last4: string; payer: string } | null;
    trialMonths: number;
    price: number;
    unit: string;
    firstCharge: string;
    extension: boolean;
};

/** True when payments are on and there's no card yet: the claim button waits for one. */
export const needsCard = (b: ClaimBilling) => b.stripeKey !== null && b.card === null;

/**
 * The card step when claiming a seat (applying, accepting an invitation or a waitlist offer): what it costs and when
 * the first charge is, the card on file (reused) or Stripe's card form. Nothing is charged today.
 */
export function ClaimCard({ billing, error }: { billing: ClaimBilling; error?: string }) {
    const [changing, setChanging] = useState(false);
    if (!billing.stripeKey) return null;
    return (
        <section aria-labelledby="pay" className="space-y-2 rounded-lg border border-line p-4 text-left">
            <h2 id="pay" className="font-semibold">
                Payment card
            </h2>
            <p className="text-sm text-muted">
                Nothing is charged today. After your {months(billing.trialMonths)} free trial it’s {money(billing.price)} per {billing.unit} per
                month, billed to this card. If you start today, your first charge is on {billing.firstCharge}. Cancel any time before then and you pay
                nothing.
                {billing.extension && ' Any month you’re offered no referrals is free (a free extension).'}
            </p>
            {billing.card && !changing ? (
                <p className="flex flex-wrap items-center gap-3 text-sm">
                    <span>
                        {billing.card.payer === 'company' ? 'Your company’s card' : 'Your card'}: {billing.card.brand.toUpperCase()} ending{' '}
                        {billing.card.last4}
                    </span>
                    {billing.card.payer === 'team' && (
                        <button type="button" className="font-medium text-primary" onClick={() => setChanging(true)}>
                            Use a different card
                        </button>
                    )}
                </p>
            ) : (
                <CardForm
                    setupUrl="/app/billing/team/setup-intent"
                    saveUrl="/app/billing/team/card"
                    stripeKey={billing.stripeKey}
                    keepPage
                    label="Add a card"
                    onDone={() => setChanging(false)}
                />
            )}
            {error && <p className="text-sm text-danger">{error}</p>}
        </section>
    );
}
