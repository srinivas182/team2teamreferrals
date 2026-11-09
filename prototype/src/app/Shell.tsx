import { Gauge, Map, UserPlus, ShieldAlert, Home, Inbox, KanbanSquare, Send, Wallet, Users, MessagesSquare, UserCog, BadgeCheck, CreditCard, Plug, LifeBuoy, Bell, Search, Plus, MoreHorizontal } from "lucide-react";
import { useEffect, useState } from "react";
import { Logo, Avatar, cn } from "../ui/kit";
import { CommandPalette, NotificationList } from "../system/System";

const nav = [
  [Home, "Home", "dashboard"], [Inbox, "Inbox", "inbox"], [KanbanSquare, "Pipeline", "pipeline"], [Send, "Sent", "sent"],
  [Wallet, "Earnings", "earnings"], [Users, "Groups", "my-groups"], [MessagesSquare, "Community", "community"],
  [UserCog, "Team", "team"], [BadgeCheck, "Profile and licenses", "pro"], [CreditCard, "Billing", "billing"], [Plug, "Integrations", "integrations"], [LifeBuoy, "Help", "help"],
] as const;
const ownerNav = [[Gauge, "Group overview", "owner"], [Map, "Seat map", "seat-map"], [UserPlus, "Applications", "applications"], [ShieldAlert, "Members and enforcement", "enforcement"]] as const;

export function Shell({ current, title, actions, children, startPalette }: { current: string; title: string; actions?: React.ReactNode; children: React.ReactNode; startPalette?: boolean }) {
  const [palette, setPalette] = useState(!!startPalette);
  const [bell, setBell] = useState(false);
  useEffect(() => {
    const on = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") { e.preventDefault(); setPalette(p => !p); } if (e.key === "Escape") setBell(false); };
    window.addEventListener("keydown", on); return () => window.removeEventListener("keydown", on);
  }, []);
  return (
    <div className="min-h-screen bg-bg lg:grid lg:grid-cols-[264px_1fr]">
      <aside className="sticky top-0 hidden h-screen flex-col border-r border-line bg-surface lg:flex">
        <div className="px-5 py-4"><a href="#dashboard"><Logo className="h-8 w-auto" /></a></div>
        <div className="px-3"><a href="#send" className="flex h-11 items-center justify-center gap-2 rounded-md bg-primary font-semibold text-on-primary hover:bg-primary-hover"><Plus size={18} />Send referral</a></div>
        <nav className="mt-4 flex-1 space-y-0.5 overflow-y-auto px-3" aria-label="Portal">
          {nav.map(([I, label, href]) => (
            <a key={href} href={`#${href}`} aria-current={current === href ? "page" : undefined}
              className={cn("flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-muted hover:bg-bg hover:text-ink", current === href && "bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200")}>
              <I size={18} />{label}
              {href === "inbox" && <span className="ml-auto rounded-full bg-orange-500 px-2 text-xs font-bold text-navy-900">3</span>}
            </a>
          ))}
          <div className="px-3 pb-1 pt-5 text-xs font-semibold text-subtle">My group · Mountain West Alliance</div>
          {ownerNav.map(([I, label, href]) => (
            <a key={href} href={`#${href}`} aria-current={current === href ? "page" : undefined}
              className={cn("flex items-center gap-3 rounded-md px-3 py-2 text-[15px] font-medium text-muted hover:bg-bg hover:text-ink", current === href && "bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/15 dark:text-cobalt-200")}>
              <I size={18} />{label}{href === "applications" && <span className="ml-auto rounded-full bg-navy-100 px-2 text-xs font-bold text-navy-700 dark:bg-navy-700 dark:text-white">3</span>}
            </a>
          ))}
        </nav>
        <div className="flex items-center gap-3 border-t border-line p-4"><Avatar name="Jordan Hale" size={34} /><div className="min-w-0 text-sm"><div className="truncate font-semibold">Jordan Hale</div><div className="truncate text-muted">Red Rock Realty Group</div></div></div>
      </aside>
      <div className="min-w-0 pb-24 lg:pb-0">
        <header className="sticky top-[env(safe-area-inset-top,0px)] z-30 flex h-16 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur sm:px-6">
          <a href="#dashboard" className="lg:hidden" aria-label="Home"><Logo markOnly className="h-8 w-9" /></a>
          <button onClick={() => setPalette(true)} className="flex h-10 flex-1 items-center gap-2 rounded-md border border-line bg-bg px-3 text-left text-sm text-subtle sm:max-w-md" aria-label="Search or jump to (Ctrl K)">
            <Search size={16} /><span className="truncate">Search referrals, teams, cities…</span><kbd className="ml-auto hidden rounded border border-line px-1.5 text-xs sm:inline">Ctrl K</kbd>
          </button>
          <div className="relative ml-auto">
            <button onClick={() => setBell(!bell)} aria-expanded={bell} className="relative rounded-md p-2 hover:bg-bg" aria-label="Notifications, 4 unread"><Bell size={20} /><span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-orange-500" /></button>
            {bell && <div className="absolute right-0 top-12 w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-lg border border-line bg-surface shadow-lg">
              <div className="flex items-center justify-between border-b border-line px-4 py-3"><span className="font-semibold">Notifications</span><button className="text-sm text-primary">Mark all read</button></div>
              <div className="max-h-[60vh] overflow-y-auto pb-2"><NotificationList compact /></div>
              <a href="#notifications" className="block border-t border-line px-4 py-3 text-center text-sm font-semibold text-primary">See all notifications</a></div>}
          </div>
        </header>
        <div className="px-4 py-6 sm:px-6 lg:px-8">
          <div className="mb-6 flex flex-wrap items-center gap-3"><h1 className="display font-display text-2xl font-bold sm:text-[28px]">{title}</h1><div className="ml-auto flex gap-2">{actions}</div></div>
          {children}
        </div>
      </div>
      {palette && <CommandPalette onClose={() => setPalette(false)} />}
      <nav className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-line bg-surface pb-[env(safe-area-inset-bottom,0px)] lg:hidden" aria-label="Portal">
        {[[Home, "Home", "dashboard"], [Inbox, "Inbox", "inbox"], [Plus, "Send", "send"], [KanbanSquare, "Pipeline", "pipeline"], [MoreHorizontal, "More", "screens"]].map(([I, l, h]) => {
          const Icon = I as typeof Home;
          return <a key={h as string} href={`#${h}`} className={cn("flex flex-col items-center gap-1 py-2 text-xs font-medium text-muted", current === h && "text-primary")}>
            {h === "send" ? <span className="-mt-5 flex h-12 w-12 items-center justify-center rounded-full bg-primary text-on-primary shadow-md"><Icon size={22} /></span> : <Icon size={20} />}{l as string}</a>;
        })}
      </nav>
    </div>
  );
}
