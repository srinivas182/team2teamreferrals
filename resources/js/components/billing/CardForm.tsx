import { router } from '@inertiajs/react';
import { CreditCard, Lock } from 'lucide-react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { xsrf } from '@/lib/xsrf';

// Stripe.js is loaded from Stripe only on billing pages (the security policy allows it there alone).
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
export function loadStripe(key: string): Promise<StripeJs> {
    return new Promise((resolve, reject) => {
        if (window.Stripe) return resolve(window.Stripe(key));
        const s = document.createElement('script');
        s.src = 'https://js.stripe.com/v3';
        s.onload = () => (window.Stripe ? resolve(window.Stripe(key)) : reject(new Error('Stripe failed to load')));
        s.onerror = () => reject(new Error('Stripe failed to load'));
        document.head.appendChild(s);
    });
}
export { xsrf };

/** Add or replace a card with Stripe's own form (card details never reach our servers). */
export function CardForm({ setupUrl, saveUrl, stripeKey, onDone }: { setupUrl: string; saveUrl: string; stripeKey: string; onDone: () => void }) {
    const box = useRef<HTMLDivElement>(null);
    const [state, setState] = useState<{ stripe: StripeJs; elements: unknown } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const start = async () => {
        setError(null);
        try {
            const res = await fetch(setupUrl, {
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
        router.post(saveUrl, { payment_method: r.setupIntent?.payment_method }, { preserveScroll: true, onSuccess: onDone });
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

/** "Pay now" on an invoice; when the bank asks the customer to confirm, Stripe shows its own screen. */
export async function payInvoice(url: string, stripeKey: string | null, setError: (e: string | null) => void) {
    setError(null);
    const res = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
    const json = (await res.json().catch(() => null)) as { clientSecret?: string } | null;
    if (json?.clientSecret && stripeKey) {
        const stripe = await loadStripe(stripeKey);
        const r = await stripe.handleNextAction({ clientSecret: json.clientSecret });
        if (r.error) setError(r.error.message);
    }
    router.reload();
}
