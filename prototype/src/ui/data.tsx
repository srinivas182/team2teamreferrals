import { TrendingUp, TrendingDown, Clock } from "lucide-react";
import { Card, cn } from "./kit";

export function Kpi({ label, value, change, hint, spark, href, lowerIsBetter }: { label: string; value: string; change?: number; hint?: string; spark?: number[]; href?: string; lowerIsBetter?: boolean }) {
  const up = (change ?? 0) >= 0;
  const good = lowerIsBetter ? !up : up;
  const max = spark ? Math.max(...spark) : 1;
  const body = (
    <Card className="h-full p-5 transition-shadow hover:shadow-md">
      <div className="text-sm text-muted">{label}</div>
      <div className="mt-2 flex items-end justify-between gap-3">
        <div className="font-display text-[28px] font-bold leading-none tabular">{value}</div>
        {spark && <svg viewBox={`0 0 ${(spark.length - 1) * 12} 28`} className="h-7 w-20" aria-hidden>
          <polyline points={spark.map((v, i) => `${i * 12},${28 - (v / max) * 24}`).join(" ")} fill="none" stroke="#2451F5" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
        </svg>}
      </div>
      {change !== undefined && <div className={cn("mt-3 flex items-center gap-1 text-sm font-medium", good ? "text-success" : "text-danger")}>{up ? <TrendingUp size={15} /> : <TrendingDown size={15} />}{up ? "+" : ""}{change}% <span className="font-normal text-muted">vs last month</span></div>}
      {hint && <div className="mt-3 text-sm text-muted">{hint}</div>}
    </Card>
  );
  return href ? <a href={href} className="block">{body}</a> : body;
}

/** Sent (cobalt) vs received (orange) — the relay colours. */
export function SentReceivedChart({ data }: { data: { m: string; sent: number; received: number }[] }) {
  const max = Math.max(...data.flatMap(d => [d.sent, d.received]));
  return (
    <figure>
      <div className="flex h-44 items-end gap-3 sm:gap-5" role="img" aria-label="Referrals sent and received per month">
        {data.map(d => (
          <div key={d.m} className="flex flex-1 flex-col items-center gap-2">
            <div className="flex h-36 w-full items-end justify-center gap-1">
              <div className="w-1/2 max-w-[18px] rounded-t bg-cobalt-500" style={{ height: `${(d.sent / max) * 100}%` }} title={`${d.sent} sent`} />
              <div className="w-1/2 max-w-[18px] rounded-t bg-orange-500" style={{ height: `${(d.received / max) * 100}%` }} title={`${d.received} received`} />
            </div>
            <span className="text-xs text-muted">{d.m}</span>
          </div>
        ))}
      </div>
      <figcaption className="mt-3 flex gap-4 text-sm"><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-sm bg-cobalt-500" />Sent</span><span className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-sm bg-orange-500" />Received</span></figcaption>
      <table className="sr-only"><caption>Referrals by month</caption><thead><tr><th>Month</th><th>Sent</th><th>Received</th></tr></thead><tbody>{data.map(d => <tr key={d.m}><td>{d.m}</td><td>{d.sent}</td><td>{d.received}</td></tr>)}</tbody></table>
    </figure>
  );
}

/** SLA countdown: colour shifts at 50% and 90% of the allowed time. */
export function Countdown({ minutesLeft, total, overdue }: { minutesLeft: number; total?: number; overdue?: boolean }) {
  const used = total ? 1 - minutesLeft / total : 0;
  const tone = overdue || minutesLeft <= 0 ? "bg-[#FDECEE] text-danger dark:bg-danger/20 dark:text-[#FDA4AF]"
    : used >= 0.9 ? "bg-[#FDECEE] text-danger dark:bg-danger/20 dark:text-[#FDA4AF]"
    : used >= 0.5 ? "bg-[#FEF9E7] text-warning dark:bg-warning/20 dark:text-[#FCD34D]"
    : "bg-navy-100 text-navy-700 dark:bg-navy-800 dark:text-navy-200";
  const h = Math.floor(minutesLeft / 60), m = minutesLeft % 60;
  const label = overdue || minutesLeft <= 0 ? "Overdue" : h ? `${h}h ${m}m left` : `${m}m left`;
  return <span className={cn("inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold tabular", tone)}><Clock size={12} aria-hidden />{label}</span>;
}

export function Segmented<T extends string>({ value, options, onChange, label }: { value: T; options: [T, string][]; onChange: (v: T) => void; label: string }) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-md border border-line bg-surface p-0.5">
      {options.map(([v, l]) => <button key={v} role="radio" aria-checked={value === v} onClick={() => onChange(v)} className={cn("rounded px-3 py-1.5 text-sm font-medium", value === v ? "bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200" : "text-muted hover:text-ink")}>{l}</button>)}
    </div>
  );
}
