import { useEffect, useMemo, useState } from "react";
import { geoAlbersUsa, geoPath } from "d3-geo";
import { feature, mesh } from "topojson-client";
import us from "us-atlas/states-albers-10m.json";
import { metros, seatStatus, type SeatStatus } from "../data/industries";

export const seatColors: Record<SeatStatus, [string, string]> = { claimed: ["#2451F5", "Claimed"], reserved: ["#FF7A1A", "Reserved, founding member"], open: ["#15803D", "Open, reserve it"] };

/** National map: every metro's seat for one industry. Dots fill in on load like a network lighting up. */
export function NationalMap({ industry = "real-estate", share = 0.62, onPick, compact = false }: { industry?: string; share?: number; onPick?: (city: string, st: string) => void; compact?: boolean }) {
  const { paths, borders } = useMemo(() => {
    const topo = us as any; const p = geoPath();
    return { paths: (feature(topo, topo.objects.states) as any).features.map((f: any) => p(f) as string), borders: p(mesh(topo, topo.objects.states, (a: any, b: any) => a !== b) as any) as string };
  }, []);
  const proj = useMemo(() => geoAlbersUsa().scale(1300).translate([487.5, 305]), []);
  const [shown, setShown] = useState(0);
  useEffect(() => {
    setShown(0);
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { setShown(metros.length); return; }
    const t = window.setInterval(() => setShown(s => (s >= metros.length ? s : s + 3)), 28);
    return () => window.clearInterval(t);
  }, [industry]);
  return (
    <svg viewBox="0 0 975 610" className="h-auto w-full" role="img" aria-label="Map of claimed, reserved and open cities">
      {paths.map((d: string, i: number) => <path key={i} d={d} fill="rgb(var(--line))" opacity={0.5} />)}
      <path d={borders} fill="none" stroke="rgb(var(--surface))" strokeWidth={1} />
      {metros.slice(0, shown).map((m, i) => {
        const xy = proj([m.lon, m.lat]); if (!xy) return null;
        const status = seatStatus(m.city + m.st, industry, share); const [col, label] = seatColors[status];
        return (
          <g key={m.city + m.st + i} transform={`translate(${xy[0]} ${xy[1]})`} className={onPick ? "cursor-pointer" : undefined} onClick={() => onPick?.(m.city, m.st)}>
            {status === "open" && !compact && <circle r={11} fill={col} opacity={0.16} className="pulse" />}
            <circle r={compact ? 4.5 : 6} fill={col} stroke="rgb(var(--surface))" strokeWidth={1.5} className="pop" />
            <title>{m.city}, {m.st}: {label}</title>
          </g>
        );
      })}
    </svg>
  );
}

export function MapLegend() {
  return (
    <ul className="flex flex-wrap gap-4 text-sm">
      {Object.entries(seatColors).map(([k, [c, l]]) => <li key={k} className="flex items-center gap-2"><span className="h-3 w-3 rounded-full" style={{ background: c }} />{l}</li>)}
    </ul>
  );
}
