import { usePage } from '@inertiajs/react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { AllyAvatar } from '@/components/ally/AllyAvatar';

// The chat panel loads only when someone opens it, so pages stay fast.
const AllyPanel = lazy(() => import('@/components/ally/AllyPanel'));

/** The "Ask Ally" button, bottom right on public and member pages. */
export function AllyWidget() {
    const { ally } = usePage<{ ally?: boolean }>().props;
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [closed, setClosed] = useState(false);
    const button = useRef<HTMLButtonElement>(null);
    // After the panel closes, focus goes back to the button that opened it (WCAG 2.4.3).
    useEffect(() => {
        if (closed && !open) button.current?.focus();
    }, [closed, open]);
    if (!ally) return null;
    return (
        <>
            {loaded && (
                <Suspense fallback={null}>
                    <AllyPanel
                        open={open}
                        onClose={() => {
                            setOpen(false);
                            setClosed(true);
                        }}
                    />
                </Suspense>
            )}
            {!open && (
                <button
                    ref={button}
                    type="button"
                    onClick={() => {
                        setClosed(false);
                        setLoaded(true);
                        setOpen(true);
                    }}
                    className="fixed right-3 bottom-24 z-40 flex items-center gap-2 rounded-full border border-line bg-surface p-1.5 shadow-lg hover:shadow-xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none sm:right-4 sm:bottom-4 sm:pr-4 print:hidden"
                    aria-haspopup="dialog"
                    data-tour="ally"
                >
                    <AllyAvatar size={40} />
                    {/* The visible words are the button's name (on phones they're read out but not shown). */}
                    <span className="sr-only text-left leading-tight sm:not-sr-only sm:block">
                        <span className="block text-sm font-semibold text-ink">Ask Ally</span>
                        <span className="sr-only">, </span>
                        <span className="block text-xs text-muted">Your AI Assistant</span>
                    </span>
                </button>
            )}
        </>
    );
}
