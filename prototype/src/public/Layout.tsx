import { useState } from "react";
import { Menu, X } from "lucide-react";
import { Logo, Container, Button, cn } from "../ui/kit";

const nav = [["Industries", "industries"], ["How it works", "how-it-works"], ["Find a pro", "city"], ["Pricing", "pricing"], ["Blog", "blog"]] as const;

export function PublicLayout({ children, current }: { children: React.ReactNode; current: string }) {
  const [open, setOpen] = useState(false);
  return (
    <div className="min-h-screen bg-bg">
      <header className="sticky top-[env(safe-area-inset-top,0px)] z-40 border-b border-line bg-surface/90 backdrop-blur">
        <Container className="flex h-16 items-center gap-6">
          <a href="#home" aria-label="Team to Team Referrals home"><Logo className="h-9 w-auto" /></a>
          <nav className="ml-4 hidden items-center gap-1 md:flex" aria-label="Main">
            {nav.map(([label, href]) => (
              <a key={href} href={`#${href}`} className={cn("rounded-md px-3 py-2 text-sm font-medium text-muted hover:text-ink", current === href && "text-ink")}>{label}</a>
            ))}
          </nav>
          <div className="ml-auto hidden items-center gap-2 md:flex">
            <Button variant="ghost" size="sm" href="#login">Log in</Button>
            <Button size="sm" href="#claim">Reserve your city</Button>
          </div>
          <button className="ml-auto rounded-md p-2 md:hidden" aria-label={open ? "Close menu" : "Open menu"} aria-expanded={open} onClick={() => setOpen(!open)}>
            {open ? <X /> : <Menu />}
          </button>
        </Container>
        {open && (
          <div className="border-t border-line bg-surface md:hidden">
            <Container className="flex flex-col py-3">
              {nav.map(([label, href]) => <a key={href} href={`#${href}`} onClick={() => setOpen(false)} className="rounded-md px-2 py-3 font-medium">{label}</a>)}
              <div className="mt-2 grid grid-cols-2 gap-2"><Button variant="secondary" href="#login">Log in</Button><Button href="#signup">Claim your city</Button></div>
            </Container>
          </div>
        )}
      </header>
      <main>{children}</main>
      <footer className="mt-24 border-t border-line bg-surface">
        <Container className="grid gap-10 py-14 sm:grid-cols-2 lg:grid-cols-5">
          <div className="lg:col-span-2">
            <Logo className="h-9 w-auto" />
            <p className="mt-4 max-w-sm text-sm text-muted">The referral network for licensed teams. Hand out-of-area leads to teams you trust, and get paid when they close.</p>
          </div>
          {[
            ["Product", [["How it works", "how-it-works"], ["Pricing", "pricing"], ["Group directory", "groups"], ["Find a pro", "city"]]],
            ["Company", [["About", "home"], ["Blog", "blog"], ["Help centre", "home"], ["Contact", "home"]]],
            ["Legal", [["Terms", "home"], ["Privacy", "home"], ["SMS terms", "home"], ["Cookies", "home"]]],
          ].map(([title, links]) => (
            <div key={title as string}>
              <h3 className="text-sm font-semibold">{title as string}</h3>
              <ul className="mt-3 space-y-2">{(links as string[][]).map(([l, h]) => <li key={l}><a href={`#${h}`} className="text-sm text-muted hover:text-ink">{l}</a></li>)}</ul>
            </div>
          ))}
        </Container>
        <Container className="flex flex-col gap-2 border-t border-line py-6 text-sm text-muted sm:flex-row sm:justify-between">
          <span>© 2026 Team to Team Referrals</span>
          <span>Referral fees are paid broker to broker. We never take a share of your commission.</span>
        </Container>
      </footer>
    </div>
  );
}
