import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { TOURS, type TourStep } from '@/lib/tours';
import { xsrf } from '@/lib/xsrf';

const visible = (el: Element | null): el is HTMLElement =>
    !!el && el instanceof HTMLElement && el.offsetParent !== null && el.getClientRects().length > 0;
const find = (target: string) =>
    visible(document.querySelector(`[data-tour="${target}"]`)) ? (document.querySelector(`[data-tour="${target}"]`) as HTMLElement) : null;

/** A short guided tour: highlights each element in turn with a note. Escape or "Skip" ends it. */
export function Tour({ steps, onEnd }: { steps: TourStep[]; onEnd: () => void }) {
    const [ready, setReady] = useState<TourStep[] | null>(null);
    const [i, setI] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    useEffect(() => {
        // Wait a moment for the page to settle, then keep the steps that are on screen.
        const t = window.setTimeout(() => setReady(steps.filter((s) => find(s.target))), 400);
        return () => window.clearTimeout(t);
    }, [steps]);
    const step = ready?.[i];
    const measure = useCallback(() => {
        const el = step ? find(step.target) : null;
        setRect(el ? el.getBoundingClientRect() : null);
    }, [step]);
    useLayoutEffect(() => {
        const el = step ? find(step.target) : null;
        el?.scrollIntoView({ block: 'center', behavior: 'auto' });
        const frame = window.requestAnimationFrame(measure);
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);
        return () => {
            window.cancelAnimationFrame(frame);
            window.removeEventListener('resize', measure);
            window.removeEventListener('scroll', measure, true);
        };
    }, [step, measure]);
    useEffect(() => {
        const key = (e: KeyboardEvent) => e.key === 'Escape' && onEnd();
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, [onEnd]);
    useEffect(() => {
        if (ready && ready.length === 0) onEnd();
    }, [ready, onEnd]);
    if (!ready || !step || !rect) return null;
    const last = i === ready.length - 1;
    const below = rect.bottom + 220 < window.innerHeight;
    const left = Math.min(Math.max(12, rect.left), window.innerWidth - 332);
    return (
        <div className="print:hidden">
            <div
                aria-hidden
                className="pointer-events-none fixed z-[60] rounded-lg ring-4 ring-orange-500/80"
                style={{ top: rect.top - 4, left: rect.left - 4, width: rect.width + 8, height: rect.height + 8 }}
            />
            <section
                role="dialog"
                aria-modal="false"
                aria-labelledby="tour-title"
                className="fixed z-[61] w-[320px] max-w-[calc(100vw-24px)] rounded-lg border border-line bg-surface p-4 shadow-xl"
                style={below ? { top: rect.bottom + 12, left } : { top: Math.max(12, rect.top - 12), left, transform: 'translateY(-100%)' }}
            >
                <p className="text-xs text-muted">
                    {i + 1} of {ready.length}
                </p>
                <h2 id="tour-title" className="mt-1 font-semibold">
                    {step.title}
                </h2>
                <p className="mt-1 text-sm">{step.body}</p>
                <div className="mt-3 flex items-center gap-2">
                    <Button size="sm" variant="ghost" onClick={onEnd}>
                        Skip tour
                    </Button>
                    <span className="flex-1" />
                    {i > 0 && (
                        <Button size="sm" variant="secondary" onClick={() => setI(i - 1)}>
                            Back
                        </Button>
                    )}
                    <Button size="sm" autoFocus onClick={() => (last ? onEnd() : setI(i + 1))}>
                        {last ? 'Done' : 'Next'}
                    </Button>
                </div>
            </section>
        </div>
    );
}

/** Shows the tour for the current page once, for signed-in people who haven't finished it and haven't turned tips off. */
export function TourRunner() {
    const { url, props } = usePage<{ tours?: { done: string[]; off: boolean } | null }>();
    const path = url.split('?')[0] ?? url;
    const tour = useMemo(() => TOURS.find((t) => t.path.test(path)), [path]);
    const [ended, setEnded] = useState<string[]>([]);
    const end = useCallback(() => {
        if (!tour) return;
        setEnded((e) => [...e, tour.id]);
        void fetch(`/app/tours/${tour.id}/done`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        }).catch(() => undefined);
    }, [tour]);
    const state = props.tours;
    // Visual checks in CI pass ?tour=off so screenshots are stable.
    if (!tour || !state || state.off || state.done.includes(tour.id) || ended.includes(tour.id) || url.includes('tour=off')) return null;
    return <Tour steps={tour.steps} onEnd={end} />;
}
