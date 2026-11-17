import { useEffect } from 'react';

/** Count a view or a click for partners (the page is cached, so the browser reports it; bots are filtered on the server). */
export function track(kind: 'view' | 'click', slugs: string[], city?: number | null) {
    if (typeof window === 'undefined' || slugs.length === 0) return;
    const body = JSON.stringify({ k: kind, s: slugs.slice(0, 30), c: city ?? null });
    try {
        if (navigator.sendBeacon?.('/partners/seen', new Blob([body], { type: 'application/json' }))) return;
    } catch {
        // fall through to fetch
    }
    void fetch('/partners/seen', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => undefined);
}

/** Report the partners shown on this page once, after it has rendered. */
export function useTrackViews(slugs: string[], city?: number | null) {
    const key = slugs.join(',');
    useEffect(() => {
        track('view', slugs, city);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, city]);
}
