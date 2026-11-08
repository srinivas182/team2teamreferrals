import { LayoutGrid, Sun, Moon, Monitor } from "lucide-react";
import { useRoute } from "./app/router";
import { useTheme } from "./app/theme";
import { PublicLayout } from "./public/Layout";
import Home from "./public/Home";
import { HowItWorks, Pricing, Groups } from "./public/Pages1";
import { GroupPage, CityPage, ProfilePage, AuthPage, BlogPost } from "./public/Pages2";
import { Wizard, TrainingGate, Welcome } from "./onboarding/Onboarding";
import { Shell } from "./app/Shell";
import { Card, Logo, Badge, Button } from "./ui/kit";

type Screen = { route: string; title: string; area: string; part: "2a" | "2b" | "2c" };
export const screens: Screen[] = [
  ["home", "Home"], ["how-it-works", "How it works"], ["pricing", "Pricing"], ["groups", "Group directory"], ["group", "Group page"],
  ["city", "City page and consumer request"], ["pro", "“Refer to me” team profile"], ["signup", "Sign up"], ["login", "Log in"], ["blog", "Blog post"],
].map(([route, title]) => ({ route, title, area: "Public site", part: "2a" as Screen["part"] }))
  .concat([["onboarding", "Setup wizard (6 steps)"], ["training", "Training video gate"], ["welcome", "First-run dashboard and checklist"]].map(([route, title]) => ({ route, title, area: "Onboarding", part: "2a" as const })))
  .concat([["dashboard", "Home dashboard and Today view"], ["inbox", "Inbox: accept or reject"], ["accept", "Accept and e-sign"], ["send", "Send referral"], ["pipeline", "Pipeline board"], ["referral", "Referral detail"], ["sent", "Sent referrals"], ["earnings", "Earnings"], ["my-groups", "Groups and network map"], ["billing", "Billing"]].map(([route, title]) => ({ route, title, area: "Team leader", part: "2b" as const })))
  .concat([["owner", "Group overview"], ["seat-map", "Seat map"], ["applications", "Applications"], ["enforcement", "Members and enforcement"]].map(([route, title]) => ({ route, title, area: "Group owner", part: "2b" as const })))
  .concat([["admin", "Network dashboard"], ["industry-builder", "Industry builder"], ["site-builder", "Website builder"], ["network-crm", "Network CRM"], ["license-queue", "License verification queue"], ["portal", "Client portal"], ["request-done", "Consumer request confirmation"], ["palette", "Command palette"], ["notifications", "Notification centre"], ["gallery", "Component gallery"]].map(([route, title]) => ({ route, title, area: "Admin, client and system", part: "2c" as const })));

function ScreenIndex() {
  const areas = Array.from(new Set(screens.map(s => s.area)));
  return (
    <div className="mx-auto max-w-4xl px-5 py-10">
      <Logo className="h-9 w-auto" />
      <h1 className="display mt-8 font-display text-3xl font-extrabold">Prototype screens</h1>
      <p className="mt-2 text-muted">Sprint 2 — high-fidelity, clickable prototype with sample content. Part 2a is ready; 2b and 2c follow.</p>
      {areas.map(a => (
        <section key={a} className="mt-8">
          <h2 className="text-lg font-semibold">{a}</h2>
          <div className="mt-3 grid gap-2 sm:grid-cols-2">
            {screens.filter(s => s.area === a).map(s => (
              <a key={s.route} href={`#${s.route}`} className="flex items-center justify-between rounded-md border border-line bg-surface px-4 py-3 hover:border-primary">
                <span className="font-medium">{s.title}</span>{s.part === "2a" ? <Badge tone="success">Ready</Badge> : <Badge>Part {s.part}</Badge>}
              </a>))}
          </div>
        </section>))}
    </div>
  );
}

function Upcoming({ route }: { route: string }) {
  const s = screens.find(x => x.route === route);
  return <Shell current={route} title={s?.title ?? "Coming soon"}><Card className="p-10 text-center"><h2 className="text-lg font-semibold">Designed in Sprint 2 part {s?.part}</h2><p className="mt-1 text-muted">This screen is next in the build. Everything marked Ready on the screen list can be reviewed now.</p><Button className="mt-5" variant="secondary" href="#screens">Back to screen list</Button></Card></Shell>;
}

function Controls() {
  const { theme, next } = useTheme();
  const Icon = theme === "dark" ? Moon : theme === "light" ? Sun : Monitor;
  return (
    <div className="fixed bottom-24 right-2 z-50 flex flex-col gap-2 opacity-60 transition-opacity hover:opacity-100 focus-within:opacity-100 lg:bottom-6 lg:right-4 lg:opacity-100">
      <button onClick={next} className="flex h-9 w-9 lg:h-11 lg:w-11 items-center justify-center rounded-full border border-line bg-surface shadow-lg" aria-label={`Theme: ${theme}. Change theme`}><Icon size={18} /></button>
      <a href="#screens" className="flex h-9 w-9 lg:h-11 lg:w-11 items-center justify-center rounded-full bg-navy-900 text-white shadow-lg dark:bg-white dark:text-navy-900" aria-label="All prototype screens"><LayoutGrid size={18} /></a>
    </div>
  );
}

export default function App() {
  const route = useRoute("home");
  const publicPages: Record<string, JSX.Element> = {
    home: <Home />, "how-it-works": <HowItWorks />, pricing: <Pricing />, groups: <Groups />, group: <GroupPage />, city: <CityPage />, pro: <ProfilePage />, blog: <BlogPost />,
  };
  let view: JSX.Element;
  if (route === "screens") view = <ScreenIndex />;
  else if (publicPages[route]) view = <PublicLayout current={route}>{publicPages[route]}</PublicLayout>;
  else if (route === "login" || route === "signup") view = <AuthPage mode={route} />;
  else if (route === "onboarding") view = <Wizard />;
  else if (route === "training") view = <TrainingGate />;
  else if (route === "welcome") view = <Welcome />;
  else view = <Upcoming route={route} />;
  return <>{view}<Controls /></>;
}
