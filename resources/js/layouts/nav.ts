import {
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
} from 'lucide-react';

/** `module`: hidden when that module is switched off in Admin → Settings → Modules. */
export type NavItem = { label: string; href: string; icon: typeof Home; badge?: number; module?: string };

/** Portal navigation (information architecture, docs/design/information-architecture.md). */
// Integrations (Sprints 44-45) and Help (Sprint 48) return to the menu with the sprints that build them.
export const portalNav: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
    { label: 'Sent', href: '/app/sent', icon: Send },
    { label: 'Earnings', href: '/app/earnings', icon: Wallet },
    { label: 'Groups', href: '/app/groups', icon: Users },
    { label: 'Team', href: '/app/team', icon: UserCog },
    { label: 'Company', href: '/app/company', icon: Building2 },
    { label: 'Profile and licenses', href: '/app/profile', icon: BadgeCheck },
    { label: 'Billing', href: '/app/billing', icon: CreditCard },
    { label: 'Dashboard', href: '/app/dashboard', icon: Gauge },
    { label: 'Feed', href: '/app/feed', icon: MessagesSquare, module: 'community' },
    { label: 'Events', href: '/app/events', icon: CalendarDays, module: 'events' },
    { label: 'Directory', href: '/app/directory', icon: BookUser, module: 'community' },
    { label: 'Leaderboards', href: '/app/leaderboards', icon: Trophy, module: 'community' },
    { label: 'Badges', href: '/app/badges', icon: Award, module: 'community' },
    { label: 'Drafts', href: '/app/drafts', icon: Inbox, module: 'ai_intake' },
    { label: 'Nurture', href: '/app/nurture', icon: Sprout },
    { label: 'Credits', href: '/app/credits', icon: HandHeart },
    { label: 'Share profile', href: '/app/share', icon: QrCode },
    { label: 'Invites', href: '/app/invites', icon: UserPlus },
    { label: 'Profile leads', href: '/app/profile-leads', icon: Inbox },
    { label: 'Performance', href: '/app/performance', icon: Gauge },
    { label: 'Training', href: '/app/training', icon: GraduationCap, module: 'training' },
    { label: 'Notifications', href: '/app/notifications', icon: Bell },
];

/** Owner pages go through /app/owner/…, which opens the owner's network (or asks which, if they own several). */
export const ownerNav: NavItem[] = [
    { label: 'Owner dashboard', href: '/app/owner/dashboard', icon: Gauge },
    { label: 'Group overview', href: '/app/owner', icon: Gauge },
    { label: 'Seat map', href: '/app/owner/seat-map', icon: Map },
    { label: 'Applications', href: '/app/owner/applications', icon: UserPlus },
    { label: 'Members and enforcement', href: '/app/owner/challenges', icon: ShieldAlert },
    { label: 'Reports', href: '/app/owner/reports', icon: Gauge },
];

export const mobileTabs: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Send', href: '/app/send', icon: Send },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
    { label: 'Alerts', href: '/app/notifications', icon: Bell },
];
