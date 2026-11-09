import { useEffect, useState } from "react";
/** Counts up once on mount; jumps straight to the value when the user prefers reduced motion. */
export function CountUp({ to, ms = 1200, format = (n: number) => n.toLocaleString() }: { to: number; ms?: number; format?: (n: number) => string }) {
  const [v, setV] = useState(0);
  useEffect(() => {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { setV(to); return; }
    const start = performance.now(); let raf = 0;
    const tick = (t: number) => { const p = Math.min(1, (t - start) / ms); setV(Math.round(to * (1 - Math.pow(1 - p, 3)))); if (p < 1) raf = requestAnimationFrame(tick); };
    raf = requestAnimationFrame(tick); return () => cancelAnimationFrame(raf);
  }, [to, ms]);
  return <span className="tabular">{format(v)}</span>;
}
