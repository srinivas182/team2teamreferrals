import { lazy, Suspense, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { type MapData, type MapPoint, type MapStatus, STATUS_COLOR, STATUS_LABEL } from './types';

const noop = () => () => undefined;
const MapCanvas = lazy(() => import('./MapCanvas'));

/** How often an open map checks for seat changes (the data is cached for at most 30 seconds). */
const REFRESH_MS = 30_000;

/**
 * The interactive network map: filled, waitlisted and open places, refreshed while it's on screen. Server
 * rendering (and anyone without scripts) gets the counts and a text list of every place with its link.
 */
export function NetworkMap({
    initial,
    src,
    highlight = [],
    extra = [],
    base = '',
    external = false,
    list = true,
    caption = true,
}: {
    initial?: MapData | null;
    src: string;
    highlight?: string[];
    /** Places always shown (a team's own seats on its dashboard, even with a private profile). */
    extra?: MapPoint[];
    base?: string;
    external?: boolean;
    list?: boolean;
    caption?: boolean;
}) {
    const [loaded, setData] = useState<MapData | null>(initial ?? null);
    const data = loaded && extra.length ? withExtra(loaded, extra) : loaded;
    // True in the browser, false while rendering on the server (the drawn map needs the browser).
    const mounted = useSyncExternalStore(
        noop,
        () => true,
        () => false,
    );
    const [shown, setShown] = useState<Record<MapStatus, boolean>>({ held: true, waitlist: true, open: true });
    // The map is drawn only when it is about to scroll into view, so a phone spends its first seconds on the text
    // (keeps Total Blocking Time low, Sprint 43). Without IntersectionObserver it is drawn straight away.
    const holder = useRef<HTMLDivElement>(null);
    const [near, setNear] = useState(() => typeof IntersectionObserver === 'undefined');
    useEffect(() => {
        if (near || !holder.current) return;
        const io = new IntersectionObserver((entries) => entries.some((e) => e.isIntersecting) && setNear(true), { rootMargin: '300px' });
        io.observe(holder.current);
        return () => io.disconnect();
    }, [near, mounted]);
    useEffect(() => {
        let stop = false;
        const load = () => {
            if (document.visibilityState !== 'visible') return;
            fetch(`${base}${src}`, { credentials: 'omit' })
                .then((r) => (r.ok ? (r.json() as Promise<MapData>) : null))
                .then((d) => !stop && d && setData(d))
                .catch(() => undefined);
        };
        if (!initial) load();
        const t = window.setInterval(load, REFRESH_MS);
        document.addEventListener('visibilitychange', load);
        return () => {
            stop = true;
            window.clearInterval(t);
            document.removeEventListener('visibilitychange', load);
        };
    }, [src, base, initial]);

    if (!data) return null;
    const href = (path: string) => (external ? `${base}${path}` : path);
    const byState = data.points.reduce<Record<string, typeof data.points>>((acc, p) => ((acc[p.state] ??= []).push(p), acc), {});
    return (
        <figure className="mt-3">
            <div className="mb-2 flex flex-wrap gap-2" role="group" aria-label="Show on the map">
                {(Object.keys(STATUS_LABEL) as MapStatus[]).map((k) => (
                    <button
                        key={k}
                        type="button"
                        aria-pressed={shown[k]}
                        onClick={() => setShown({ ...shown, [k]: !shown[k] })}
                        className={`flex items-center gap-2 rounded-full border px-3 py-1 text-sm ${shown[k] ? 'border-line bg-surface' : 'border-dashed border-line text-muted'}`}
                    >
                        <span
                            className="size-3 rounded-full"
                            style={{ background: shown[k] ? STATUS_COLOR[k] : 'transparent', border: `2px solid ${STATUS_COLOR[k]}` }}
                            aria-hidden
                        />
                        {STATUS_LABEL[k]}: {data.counts[k]}
                    </button>
                ))}
            </div>
            {mounted && near ? (
                <Suspense fallback={<div className="aspect-[975/610] w-full animate-pulse rounded-lg bg-surface" />}>
                    <MapCanvas points={data.points} shown={shown} highlight={highlight} base={base} external={external} />
                </Suspense>
            ) : (
                <div ref={holder} className="aspect-[975/610] w-full rounded-lg border border-line bg-surface" />
            )}
            {caption && (
                <figcaption className="mt-2 text-xs text-muted">
                    Choose a state to zoom in, or a dot to see who holds it. Updates by itself every 30 seconds.
                </figcaption>
            )}
            {list && (
                <details className="mt-3 text-sm">
                    <summary className="cursor-pointer font-medium">Every place on this map, as a list</summary>
                    <div className="mt-2 grid gap-3 sm:grid-cols-2">
                        {Object.entries(byState)
                            .sort(([a], [b]) => a.localeCompare(b))
                            .map(([st, ps]) => (
                                <div key={st}>
                                    <h3 className="font-semibold">{st}</h3>
                                    <ul className="space-y-0.5">
                                        {ps.map((p) => (
                                            <li key={p.key}>
                                                {p.team ? (
                                                    <a href={href(`/t/${p.team.slug}`)} className="text-primary">
                                                        {p.name}: {p.team.name}
                                                    </a>
                                                ) : (
                                                    <>
                                                        {p.name}: {STATUS_LABEL[p.status].toLowerCase()}
                                                        {p.claim && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                <a href={href(p.claim)} className="text-primary">
                                                                    claim
                                                                </a>
                                                            </>
                                                        )}
                                                    </>
                                                )}
                                                {p.group ? <span className="text-muted"> ({p.group})</span> : null}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                    </div>
                </details>
            )}
        </figure>
    );
}

function withExtra(d: MapData, extra: MapPoint[]): MapData {
    const keys = new Set(d.points.map((p) => p.key));
    const add = extra.filter((p) => !keys.has(p.key));
    return add.length ? { ...d, points: [...d.points, ...add], counts: { ...d.counts, held: d.counts.held + add.length } } : d;
}
