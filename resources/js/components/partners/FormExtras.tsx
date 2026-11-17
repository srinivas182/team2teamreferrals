import { useEffect } from 'react';

/** Pages around these forms are cached at the edge, so each form fetches its spam-check start token when it opens. */
export function useStartToken(set: (token: string) => void) {
    useEffect(() => {
        fetch('/request/token', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d: { started: string }) => set(d.started))
            .catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}

/** A field people never see or fill; bots usually do. */
export function Honeypot({ value, onChange }: { value: string; onChange: (v: string) => void }) {
    return (
        <div aria-hidden="true" className="absolute -left-[9999px]">
            <label>
                Leave this empty <input tabIndex={-1} autoComplete="off" value={value} onChange={(e) => onChange(e.target.value)} />
            </label>
        </div>
    );
}

export function Faq({ items }: { items: { q: string; a: string }[] }) {
    if (items.length === 0) return null;
    return (
        <section aria-labelledby="faq" className="mt-10">
            <h2 id="faq" className="font-display text-xl font-bold">
                Questions people ask
            </h2>
            <dl className="mt-3 space-y-4">
                {items.map((f) => (
                    <div key={f.q}>
                        <dt className="font-semibold">{f.q}</dt>
                        <dd className="mt-1">{f.a}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
