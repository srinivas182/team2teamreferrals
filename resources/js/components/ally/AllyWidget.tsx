import { usePage } from '@inertiajs/react';
import { lazy, Suspense, useState } from 'react';
import { AllyAvatar } from '@/components/ally/AllyAvatar';

// The chat panel loads only when someone opens it, so pages stay fast.
const AllyPanel = lazy(() => import('@/components/ally/AllyPanel'));

/** The "Ask Ally" button, bottom right on public and member pages. */
export function AllyWidget() {
    const { ally } = usePage<{ ally?: boolean }>().props;
    const [open, setOpen] = useState(false);
    const [loaded, setLoaded] = useState(false);
    if (!ally) return null;
    return (
        <>
            {loaded && (
                <Suspense fallback={null}>
                    <AllyPanel open={open} onClose={() => setOpen(false)} />
                </Suspense>
            )}
            {!open && (
                <button
                    type="button"
                    onClick={() => {
                        setLoaded(true);
                        setOpen(true);
                    }}
                    className="fixed right-4 bottom-4 z-40 flex items-center gap-2 rounded-full border border-line bg-surface py-1.5 pr-4 pl-1.5 shadow-lg hover:shadow-xl focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none print:hidden"
                    aria-haspopup="dialog"
                >
                    <AllyAvatar size={40} />
                    <span className="text-left leading-tight">
                        <span className="block text-sm font-semibold text-ink">Ask Ally</span>
                        <span className="block text-xs text-muted">Your AI Assistant</span>
                    </span>
                </button>
            )}
        </>
    );
}
