import { geoAlbersUsa } from 'd3-geo';
import { X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { type MapPoint, type MapStatus, STATUS_COLOR, STATUS_LABEL } from './types';

type Outlines = { width: number; height: number; states: { id: string; name: string; d: string }[]; borders: string };

// The outline file is drawn in this same projection (us-atlas "albers" files), so dots land on the right spot.
const projection = geoAlbersUsa().scale(1300).translate([487.5, 305]);
let outlinesPromise: Promise<Outlines> | null = null;
const loadOutlines = (base: string) => (outlinesPromise ??= fetch(`${base}/maps/us-states.json`).then((r) => r.json() as Promise<Outlines>));

const STATE_CODES: Record<string, string> = {
    Alabama: 'AL',
    Alaska: 'AK',
    Arizona: 'AZ',
    Arkansas: 'AR',
    California: 'CA',
    Colorado: 'CO',
    Connecticut: 'CT',
    Delaware: 'DE',
    'District of Columbia': 'DC',
    Florida: 'FL',
    Georgia: 'GA',
    Hawaii: 'HI',
    Idaho: 'ID',
    Illinois: 'IL',
    Indiana: 'IN',
    Iowa: 'IA',
    Kansas: 'KS',
    Kentucky: 'KY',
    Louisiana: 'LA',
    Maine: 'ME',
    Maryland: 'MD',
    Massachusetts: 'MA',
    Michigan: 'MI',
    Minnesota: 'MN',
    Mississippi: 'MS',
    Missouri: 'MO',
    Montana: 'MT',
    Nebraska: 'NE',
    Nevada: 'NV',
    'New Hampshire': 'NH',
    'New Jersey': 'NJ',
    'New Mexico': 'NM',
    'New York': 'NY',
    'North Carolina': 'NC',
    'North Dakota': 'ND',
    Ohio: 'OH',
    Oklahoma: 'OK',
    Oregon: 'OR',
    Pennsylvania: 'PA',
    'Rhode Island': 'RI',
    'South Carolina': 'SC',
    'South Dakota': 'SD',
    Tennessee: 'TN',
    Texas: 'TX',
    Utah: 'UT',
    Vermont: 'VT',
    Virginia: 'VA',
    Washington: 'WA',
    'West Virginia': 'WV',
    Wisconsin: 'WI',
    Wyoming: 'WY',
};

type Box = { x: number; y: number; w: number; h: number };
const FULL: Box = { x: 0, y: 0, w: 975, h: 610 };

/** The drawn map: state outlines, a dot per place, zoom to a state, and a card for the chosen place. */
export default function MapCanvas({
    points,
    shown,
    highlight,
    base,
    external,
}: {
    points: MapPoint[];
    shown: Record<MapStatus, boolean>;
    highlight: string[];
    base: string;
    external: boolean;
}) {
    const [outlines, setOutlines] = useState<Outlines | null>(null);
    const [box, setBox] = useState<Box>(FULL);
    const [stateName, setStateName] = useState<string | null>(null);
    const [selected, setSelected] = useState<string | null>(null);
    const paths = useRef<Record<string, SVGPathElement | null>>({});
    useEffect(() => {
        void loadOutlines(base)
            .then(setOutlines)
            .catch(() => setOutlines({ width: 975, height: 610, states: [], borders: '' }));
    }, [base]);

    const placed = useMemo(
        () =>
            points
                .map((p) => ({ p, xy: projection([p.lon, p.lat]) }))
                .filter((d): d is { p: MapPoint; xy: [number, number] } => d.xy !== null && shown[d.p.status])
                // Open places underneath, filled on top.
                .sort((a, b) => (a.p.status === 'held' ? 1 : 0) - (b.p.status === 'held' ? 1 : 0)),
        [points, shown],
    );
    const zoom = FULL.w / box.w;
    // States that have a place on the map, for the "Zoom to a state" list (keyboard and screen-reader friendly).
    const codes = new Set(points.map((p) => p.state));
    const statesWithPlaces = (outlines?.states ?? [])
        .filter((s) => codes.has(STATE_CODES[s.name] ?? ''))
        .sort((a, b) => a.name.localeCompare(b.name));
    const pick = placed.find((d) => d.p.key === selected) ?? null;
    const href = (path: string) => (external ? `${base}${path}` : path);
    const target = external ? { target: '_blank', rel: 'noopener' } : {};

    const zoomTo = (id: string | null) => {
        setSelected(null);
        const el = id ? paths.current[id] : null;
        if (!el) {
            setBox(FULL);
            setStateName(null);
            return;
        }
        const b = el.getBBox();
        const pad = Math.max(b.width, b.height) * 0.12;
        // Keep the map's shape so it doesn't stretch.
        let w = b.width + pad * 2;
        let h = b.height + pad * 2;
        if (w / h > FULL.w / FULL.h) h = (w * FULL.h) / FULL.w;
        else w = (h * FULL.w) / FULL.h;
        setBox({ x: b.x + b.width / 2 - w / 2, y: b.y + b.height / 2 - h / 2, w, h });
        setStateName(outlines?.states.find((s) => s.id === id)?.name ?? null);
    };

    return (
        <div className="relative">
            <svg
                viewBox={`${box.x} ${box.y} ${box.w} ${box.h}`}
                className="h-auto w-full touch-manipulation rounded-lg border border-line bg-surface transition-all"
                role="group"
                aria-label="Map of places. Choose a state to zoom in, or a place to see who holds it."
            >
                <g>
                    {outlines?.states.map((s) => (
                        <path
                            key={s.id}
                            ref={(el) => {
                                paths.current[s.id] = el;
                            }}
                            d={s.d}
                            className="cursor-zoom-in fill-line transition-colors hover:fill-primary/20"
                            onClick={() => zoomTo(s.id)}
                        >
                            <title>{s.name}</title>
                        </path>
                    ))}
                    {outlines && <path d={outlines.borders} fill="none" className="stroke-surface" strokeWidth={1.2 / zoom} pointerEvents="none" />}
                </g>
                {placed.map(({ p, xy: [x, y] }) => {
                    const mine = highlight.includes(p.key);
                    const r = (p.status === 'held' ? 6 : 4.5) / Math.sqrt(zoom);
                    return (
                        <g
                            key={p.key}
                            role="button"
                            tabIndex={0}
                            aria-label={`${p.name}: ${STATUS_LABEL[p.status]}${p.team ? `, ${p.team.name}` : ''}${mine ? ', your seat' : ''}`}
                            className="cursor-pointer outline-none focus-visible:[&>circle]:stroke-ink"
                            onClick={() => setSelected(p.key)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    setSelected(p.key);
                                }
                            }}
                        >
                            {/* A larger invisible target so small dots are easy to tap. */}
                            <circle cx={x} cy={y} r={14 / Math.sqrt(zoom)} fill="transparent" />
                            {mine && <circle cx={x} cy={y} r={r * 2} fill="none" stroke={STATUS_COLOR.held} strokeWidth={2 / zoom} />}
                            <circle
                                cx={x}
                                cy={y}
                                r={selected === p.key ? r * 1.5 : r}
                                fill={STATUS_COLOR[p.status]}
                                stroke="#fff"
                                strokeWidth={1.5 / zoom}
                            />
                            {zoom >= 2 && (
                                <text x={x + r + 3 / zoom} y={y + 4 / zoom} fontSize={12 / zoom} className="pointer-events-none fill-ink">
                                    {p.name.replace(/, [A-Z]{2}$/, '')}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            {outlines && statesWithPlaces.length > 0 && (
                <select
                    aria-label="Zoom to a state"
                    className="absolute top-2 right-2 h-8 max-w-40 rounded-md border border-line bg-surface px-2 text-xs"
                    value={outlines.states.find((s) => s.name === stateName)?.id ?? ''}
                    onChange={(e) => zoomTo(e.target.value || null)}
                >
                    <option value="">Whole country</option>
                    {statesWithPlaces.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
            )}
            {pick && (
                <div
                    role="dialog"
                    aria-label={pick.p.name}
                    className="relative mt-2 rounded-lg border border-line bg-surface p-3 text-sm shadow-lg sm:absolute sm:right-2 sm:bottom-2 sm:mt-0 sm:w-72"
                >
                    <button type="button" aria-label="Close" onClick={() => setSelected(null)} className="absolute top-2 right-2 text-muted">
                        <X className="size-4" />
                    </button>
                    <p className="pr-6 font-semibold">{pick.p.name}</p>
                    <p className="text-muted">
                        <span className="mr-1 inline-block size-2.5 rounded-full" style={{ background: STATUS_COLOR[pick.p.status] }} aria-hidden />
                        {STATUS_LABEL[pick.p.status]}
                        {pick.p.status === 'waitlist' && pick.p.waitlist ? ` · ${pick.p.waitlist} waiting` : ''}
                        {pick.p.group ? ` · ${pick.p.group}` : ''}
                    </p>
                    {pick.p.team ? (
                        <>
                            <p className="mt-1">
                                {pick.p.team.name}
                                {highlight.includes(pick.p.key) && <span className="ml-1 font-medium text-primary">(your seat)</span>}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-3 font-medium text-primary">
                                <a href={href(`/t/${pick.p.team.slug}`)} {...target}>
                                    View team →
                                </a>
                                <a href={href(`/t/${pick.p.team.slug}#refer`)} {...target}>
                                    Refer a client
                                </a>
                            </div>
                        </>
                    ) : (
                        <div className="mt-2 flex flex-wrap items-center gap-3">
                            {pick.p.claim && (
                                <a href={href(pick.p.claim)} {...target} className="rounded-md bg-primary px-3 py-1.5 font-semibold text-white">
                                    {pick.p.status === 'waitlist' ? 'Join the waitlist' : 'Claim this city'}
                                </a>
                            )}
                            <a href={href(pick.p.url)} {...target} className="font-medium text-primary">
                                City page
                            </a>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
