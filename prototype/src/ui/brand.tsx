import * as Icons from "lucide-react";
import { families, type Industry } from "../data/industries";
import { cn } from "./kit";

/** Industry icon tile: one glyph style, family accent, the t2t relay corner. Brand-kit placeholder until the custom icon family lands. */
export function IndustryIcon({ industry, size = 44, className }: { industry: Industry; size?: number; className?: string }) {
  const Icon = (Icons as unknown as Record<string, Icons.LucideIcon>)[industry.icon] ?? Icons.Briefcase;
  const f = families[industry.family];
  return (
    <span className={cn("relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-xl", className)} style={{ width: size, height: size, background: f.soft, color: f.accent }} aria-hidden>
      <Icon size={size * 0.48} strokeWidth={1.9} />
      <span className="absolute -bottom-1 -right-1 h-3 w-3 rounded-full" style={{ background: "linear-gradient(135deg,#2451F5,#FF7A1A)" }} />
    </span>
  );
}

export function FamilyDot({ family }: { family: keyof typeof families }) {
  return <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ background: families[family].accent }} aria-hidden />;
}
