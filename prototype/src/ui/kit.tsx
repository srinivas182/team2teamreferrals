import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";
import React from "react";
import horizontal from "../data/t2t-logo-horizontal.svg?raw";
import mark from "../data/t2t-mark.svg?raw";
import { Flame, Sun, Sprout, Check } from "lucide-react";

export const cn = (...c: ClassValue[]) => twMerge(clsx(c));

/* ---------- Brand ---------- */
const recolour = (svg: string) =>
  svg.replace(/#0E1B3D/g, "currentColor").replace(/#5B6785/g, "rgb(var(--muted))").replace(/width="\d+(\.\d+)?" height="\d+(\.\d+)?"/, 'width="100%" height="100%"');
export function Logo({ className, markOnly }: { className?: string; markOnly?: boolean }) {
  return <span role="img" aria-label="Team to Team Referrals" className={cn("inline-block text-ink", className)}
    dangerouslySetInnerHTML={{ __html: recolour(markOnly ? mark : horizontal) }} />;
}

/* ---------- Layout ---------- */
export const Container = ({ className, ...p }: React.HTMLAttributes<HTMLDivElement>) =>
  <div className={cn("mx-auto w-full max-w-content px-5 sm:px-8", className)} {...p} />;

export const Card = ({ className, ...p }: React.HTMLAttributes<HTMLDivElement>) =>
  <div className={cn("rounded-lg border border-line bg-surface", className)} {...p} />;

/* ---------- Buttons ---------- */
type BtnVariant = "primary" | "accent" | "secondary" | "ghost" | "danger";
const btnStyles: Record<BtnVariant, string> = {
  primary: "bg-primary text-on-primary hover:bg-primary-hover",
  accent: "bg-accent text-on-accent hover:brightness-95",
  secondary: "border border-line bg-surface text-ink hover:bg-bg",
  ghost: "text-ink hover:bg-bg",
  danger: "bg-danger text-white hover:brightness-95",
};
const btnSizes = { sm: "h-9 px-3 text-sm", md: "h-11 px-4 text-[15px]", lg: "h-12 px-6 text-base" };
export function Button({ variant = "primary", size = "md", className, href, ...p }:
  React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: BtnVariant; size?: keyof typeof btnSizes; href?: string }) {
  const cls = cn("inline-flex items-center justify-center gap-2 rounded-md font-semibold transition-colors duration-150 disabled:opacity-50 disabled:pointer-events-none whitespace-nowrap", btnStyles[variant], btnSizes[size], className);
  if (href) return <a href={href} className={cls}>{p.children}</a>;
  return <button className={cls} {...p} />;
}

/* ---------- Form ---------- */
export function Field({ label, hint, error, children, className }: { label: string; hint?: string; error?: string; children: React.ReactNode; className?: string }) {
  return (
    <label className={cn("block", className)}>
      <span className="mb-1.5 block text-sm font-medium text-ink">{label}</span>
      {children}
      {error ? <span className="mt-1.5 block text-sm text-danger">{error}</span> : hint ? <span className="mt-1.5 block text-sm text-muted">{hint}</span> : null}
    </label>
  );
}
const inputCls = "w-full h-11 rounded-md border border-line bg-surface px-3 text-[15px] text-ink placeholder:text-subtle focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/25";
export const Input = (p: React.InputHTMLAttributes<HTMLInputElement>) => <input {...p} className={cn(inputCls, p.className)} />;
export const Select = (p: React.SelectHTMLAttributes<HTMLSelectElement>) => <select {...p} className={cn(inputCls, "pr-8", p.className)} />;
export const Textarea = (p: React.TextareaHTMLAttributes<HTMLTextAreaElement>) => <textarea {...p} className={cn(inputCls, "h-auto min-h-[96px] py-2.5", p.className)} />;

/* ---------- Data display ---------- */
export function Badge({ tone = "neutral", className, children }: { tone?: "neutral" | "success" | "warning" | "danger" | "info" | "cobalt" | "orange"; className?: string; children: React.ReactNode }) {
  const t = {
    neutral: "bg-navy-100 text-navy-700 dark:bg-navy-800 dark:text-navy-200",
    success: "bg-[#ECFDF3] text-success dark:bg-success/20 dark:text-[#6EE7A0]",
    warning: "bg-[#FEF9E7] text-warning dark:bg-warning/20 dark:text-[#FCD34D]",
    danger: "bg-[#FDECEE] text-danger dark:bg-danger/20 dark:text-[#FDA4AF]",
    info: "bg-[#EDF4FF] text-info dark:bg-info/20 dark:text-[#93C5FD]",
    cobalt: "bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200",
    orange: "bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-200",
  }[tone];
  return <span className={cn("inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold", t, className)}>{children}</span>;
}

export type Temp = "hot" | "warm" | "future";
const temps = {
  hot: { label: "Hot", icon: Flame, cls: "text-hot bg-[#FDECEE] dark:bg-hot/20 dark:text-[#FDA4AF]" },
  warm: { label: "Warm", icon: Sun, cls: "text-warm bg-[#FEF3E2] dark:bg-warm/25 dark:text-[#FCD34D]" },
  future: { label: "Future", icon: Sprout, cls: "text-future bg-[#F3EEFF] dark:bg-future/25 dark:text-[#C4B5FD]" },
};
export function TempBadge({ t, className }: { t: Temp; className?: string }) {
  const { label, icon: I, cls } = temps[t];
  return <span className={cn("inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold", cls, className)}><I size={13} aria-hidden />{label}</span>;
}

export function Avatar({ name, size = 36, tone = "cobalt" }: { name: string; size?: number; tone?: "cobalt" | "orange" | "navy" }) {
  const initials = name.split(" ").map(w => w[0]).slice(0, 2).join("");
  const bg = { cobalt: "bg-cobalt-500 text-white", orange: "bg-orange-500 text-navy-900", navy: "bg-navy-700 text-white" }[tone];
  return <span aria-hidden className={cn("inline-flex shrink-0 items-center justify-center rounded-full font-semibold", bg)} style={{ width: size, height: size, fontSize: size * 0.38 }}>{initials}</span>;
}

export function TrustRing({ score, size = 44 }: { score: number | "new"; size?: number }) {
  const r = (size - 6) / 2, c = 2 * Math.PI * r, pct = score === "new" ? 0 : score / 100;
  return (
    <span className="relative inline-flex items-center justify-center" style={{ width: size, height: size }} aria-label={score === "new" ? "New member" : `Trust score ${score} out of 100`}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgb(var(--line))" strokeWidth={4} />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="#15803D" strokeWidth={4} strokeLinecap="round" strokeDasharray={`${c * pct} ${c}`} />
      </svg>
      <span className="absolute text-xs font-bold tabular">{score === "new" ? "New" : score}</span>
    </span>
  );
}

export function Checkmark() { return <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-success text-white"><Check size={13} strokeWidth={3} /></span>; }

export const money = (n: number) => n.toLocaleString("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 0 });
