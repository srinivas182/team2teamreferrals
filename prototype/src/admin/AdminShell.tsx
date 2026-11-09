import { LayoutDashboard, Network, ArrowLeftRight, DollarSign, Rocket, PanelsTopLeft, GraduationCap, Sparkles, LifeBuoy, Settings2, Search, Bell, BadgeCheck, Factory } from "lucide-react";
import { Logo, Avatar, cn } from "../ui/kit";

const nav = [
  [LayoutDashboard, "Dashboard", "admin"], [Factory, "Industries", "industry-builder"], [Network, "Groups and seats", "admin-groups"], [BadgeCheck, "License queue", "license-queue"],
  [ArrowLeftRight, "Network CRM", "network-crm"], [DollarSign, "Revenue", "admin-revenue"], [Rocket, "Growth", "admin-growth"], [PanelsTopLeft, "Website builder", "site-builder"],
  [GraduationCap, "Training", "admin-training"], [Sparkles, "AI", "admin-ai"], [LifeBuoy, "Support", "admin-support"], [Settings2, "System", "admin-system"],
] as const;

export function AdminShell({ current, title, actions, children, bare }: { current: string; title: string; actions?: React.ReactNode; children: React.ReactNode; bare?: boolean }) {
  return (
    <div className="min-h-screen bg-bg lg:grid lg:grid-cols-[248px_1fr]">
      <aside className="sticky top-0 hidden h-screen flex-col bg-navy-900 text-white lg:flex">
        <div className="flex items-center gap-2 px-5 py-4"><Logo markOnly className="h-8 w-9 text-white" /><span className="text-sm font-semibold">Super admin</span></div>
        <nav className="mt-2 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Admin">
          {nav.map(([I, label, href]) => (
            <a key={href} href={`#${href}`} aria-current={current === href ? "page" : undefined} className={cn("flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-[#AAB4CE] hover:bg-white/5 hover:text-white", current === href && "bg-white/10 text-white")}>
              <I size={18} />{label}{href === "license-queue" && <span className="ml-auto rounded-full bg-orange-500 px-2 text-xs font-bold text-navy-900">12</span>}
            </a>))}
        </nav>
        <div className="flex items-center gap-3 border-t border-white/10 p-4"><Avatar name="Srini Admin" size={32} tone="orange" /><div className="text-sm"><div className="font-semibold">Platform admin</div><div className="text-[#AAB4CE]">2FA on</div></div></div>
      </aside>
      <div className="min-w-0">
        <header className="sticky top-[env(safe-area-inset-top,0px)] z-30 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
          <span className="lg:hidden"><Logo markOnly className="h-8 w-9" /></span>
          <button className="flex h-10 flex-1 items-center gap-2 rounded-md border border-line bg-bg px-3 text-left text-sm text-subtle sm:max-w-md"><Search size={16} />Search teams, groups, referrals…</button>
          <button className="ml-auto rounded-md p-2 hover:bg-bg" aria-label="Notifications"><Bell size={20} /></button>
        </header>
        {bare ? children : (
          <div className="px-4 py-6 sm:px-6 lg:px-8">
            <div className="mb-6 flex flex-wrap items-center gap-3"><h1 className="display font-display text-2xl font-bold sm:text-[28px]">{title}</h1><div className="ml-auto flex flex-wrap gap-2">{actions}</div></div>
            {children}
          </div>)}
      </div>
    </div>
  );
}
