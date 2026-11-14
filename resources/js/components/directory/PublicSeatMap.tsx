type Point = { name: string; lat: number; lon: number; state: 'held' | 'waitlist' | 'open' };

const color: Record<Point['state'], string> = { held: '#2451F5', waitlist: '#FF7A1A', open: '#94A3B8' };
const label: Record<Point['state'], string> = { held: 'Seat filled', waitlist: 'Open, with a waitlist', open: 'Open' };
// Simple projection for the lower 48 (lon −125…−66, lat 24…50); places outside it are listed below the map.
const inFrame = (p: Point) => p.lon > -125 && p.lon < -66 && p.lat > 24 && p.lat < 50;
const xy = (p: Point) => [((p.lon + 125) / 59) * 960, ((50 - p.lat) / 26) * 520] as const;

/** The public seat map for a group: filled, waitlisted and open places, with a text summary for screen readers and crawlers. */
export function PublicSeatMap({ points }: { points: Point[] }) {
    if (points.length === 0) {
        return null;
    }
    const counts = (Object.keys(label) as Point['state'][]).map((k) => [k, points.filter((p) => p.state === k).length] as const);
    const outside = points.filter((p) => !inFrame(p));
    return (
        <figure className="mt-3">
            <svg viewBox="0 0 960 540" className="h-auto w-full rounded-lg border border-line bg-surface" role="img" aria-labelledby="map-summary">
                {[30, 35, 40, 45].map((lat) => (
                    <line
                        key={lat}
                        x1="0"
                        x2="960"
                        y1={((50 - lat) / 26) * 520}
                        y2={((50 - lat) / 26) * 520}
                        className="stroke-line"
                        strokeWidth="1"
                    />
                ))}
                {points.filter(inFrame).map((p) => {
                    const [x, y] = xy(p);
                    return (
                        <g key={`${p.name}${p.lat}`}>
                            <circle cx={x} cy={y} r={p.state === 'held' ? 10 : 8} fill={color[p.state]} stroke="#0E1B3D" strokeOpacity="0.3">
                                <title>{`${p.name}: ${label[p.state]}`}</title>
                            </circle>
                            {points.length <= 40 && (
                                <text x={x + 14} y={y + 5} className="fill-ink text-[15px] font-medium">
                                    {p.name}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <figcaption id="map-summary" className="mt-2 flex flex-wrap gap-4 text-sm">
                {counts.map(([k, n]) => (
                    <span key={k} className="flex items-center gap-2">
                        <span className="size-3 rounded-full" style={{ background: color[k] }} aria-hidden /> {label[k]}: {n}
                    </span>
                ))}
                {outside.length > 0 && <span className="text-muted">Also: {outside.map((p) => p.name).join(', ')}</span>}
            </figcaption>
        </figure>
    );
}
