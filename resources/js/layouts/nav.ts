import {
    Activity,
    GraduationCap,
    Building2,
    BadgeCheck,
    Bell,
    CreditCard,
    Gauge,
    Home,
    Inbox,
    KanbanSquare,
    Map,
    MessagesSquare,
    Send,
    ShieldAlert,
    UserCog,
    UserPlus,
    Users,
    Wallet,
    Sprout,
    HandHeart,
    QrCode,
    BookUser,
    Trophy,
    Award,
    CalendarDays,
    Code2,
    Plug,
    Store,
    LifeBuoy,
} from 'lucide-react';

/**
 * `module`: hidden when that module is switched off in Admin → Settings → Modules.
 * `leaders`: shown to team leaders only (billing, company, integrations, the API); members still reach their own pages.
 */
export type NavItem = { label: string; href: string; icon: typeof Home; badge?: number; module?: string; leaders?: boolean };
export type NavGroup = { key: string; label: string; items: NavItem[] };

/**
 * The member menu, simplified: five everyday places, then everything else grouped under "More" (closed until opened).
 * Search (Ctrl K) still reaches every page. Information architecture: docs/design/information-architecture.md.
 */
export const primaryNav: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
    { label: 'Sent', href: '/app/sent', icon: Send },
    { label: 'Earnings', href: '/app/earnings', icon: Wallet },
];

export const moreNav: NavGroup[] = [
    {
        key: 'network',
        label: 'Network',
        items: [
            { label: 'My groups', href: '/app/groups/mine', icon: BadgeCheck },
            { label: 'Join new groups', href: '/app/groups', icon: Users },
            { label: 'Directory', href: '/app/directory', icon: BookUser, module: 'community' },
            { label: 'Feed', href: '/app/feed', icon: MessagesSquare, module: 'community' },
            { label: 'Events', href: '/app/events', icon: CalendarDays, module: 'events' },
            { label: 'Leaderboards', href: '/app/leaderboards', icon: Trophy, module: 'community' },
            { label: 'Badges', href: '/app/badges', icon: Award, module: 'community' },
            { label: 'Local partners', href: '/app/partners', icon: Store, module: 'partner_directory' },
            { label: 'Training', href: '/app/training', icon: GraduationCap, module: 'training' },
        ],
    },
    {
        key: 'grow',
        label: 'Grow your business',
        items: [
            { label: 'Dashboard', href: '/app/dashboard', icon: Gauge },
            { label: 'Performance', href: '/app/performance', icon: Gauge },
            { label: 'Share profile', href: '/app/share', icon: QrCode },
            { label: 'Invites', href: '/app/invites', icon: UserPlus },
            { label: 'Nurture', href: '/app/nurture', icon: Sprout },
            { label: 'Credits', href: '/app/credits', icon: HandHeart },
        ],
    },
    {
        key: 'account',
        label: 'Team and account',
        items: [
            { label: 'Team', href: '/app/team', icon: UserCog },
            { label: 'Team performance', href: '/app/team/performance', icon: Activity, leaders: true },
            { label: 'Profile and licenses', href: '/app/profile', icon: BadgeCheck },
            { label: 'Notifications', href: '/app/notifications', icon: Bell },
            { label: 'Company', href: '/app/company', icon: Building2, leaders: true },
            { label: 'Billing', href: '/app/billing', icon: CreditCard, leaders: true },
            { label: 'Integrations', href: '/app/integrations', icon: Plug, leaders: true },
            { label: 'Developers (API)', href: '/app/developers', icon: Code2, leaders: true },
        ],
    },
    { key: 'help', label: 'Help', items: [{ label: 'Help and support', href: '/app/support', icon: LifeBuoy }] },
];

/** Now part of the one inbox (filters there); still reachable from search and from the inbox's own links. */
export const inboxSources: NavItem[] = [
    { label: 'Open requests', href: '/app/requests', icon: Inbox },
    { label: 'Profile leads', href: '/app/profile-leads', icon: Inbox },
    { label: 'Drafts', href: '/app/drafts', icon: Inbox, module: 'ai_intake' },
];

/** Every member page, for search (Ctrl K). */
export const portalNav: NavItem[] = [...primaryNav, ...moreNav.flatMap((g) => g.items), ...inboxSources];

/** Owner pages go through /app/owner/…, which opens the owner's network (or asks which, if they own several). */
export const ownerNav: NavItem[] = [
    { label: 'Owner dashboard', href: '/app/owner/dashboard', icon: Gauge },
    { label: 'Group overview', href: '/app/owner', icon: Gauge },
    { label: 'Seat map', href: '/app/owner/seat-map', icon: Map },
    { label: 'Applications', href: '/app/owner/applications', icon: UserPlus },
    { label: 'Members and enforcement', href: '/app/owner/challenges', icon: ShieldAlert },
    { label: 'Reports', href: '/app/owner/reports', icon: Gauge },
    { label: 'Prospects', href: '/app/owner/prospects', icon: UserPlus },
];

export const mobileTabs: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Send', href: '/app/send', icon: Send },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
];
// The fifth tab on phones is "More": everything else, grouped as in the sidebar (notifications are the bell at the top).
