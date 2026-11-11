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
    LifeBuoy,
    Map,
    MessagesSquare,
    Plug,
    Send,
    ShieldAlert,
    UserCog,
    UserPlus,
    Users,
    Wallet,
    Sprout,
    HandHeart,
    QrCode,
} from 'lucide-react';

export type NavItem = { label: string; href: string; icon: typeof Home; badge?: number };

/** Portal navigation (information architecture, docs/design/information-architecture.md). */
export const portalNav: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
    { label: 'Sent', href: '/app/sent', icon: Send },
    { label: 'Earnings', href: '/app/earnings', icon: Wallet },
    { label: 'Groups', href: '/app/groups', icon: Users },
    { label: 'Community', href: '/app/community', icon: MessagesSquare },
    { label: 'Team', href: '/app/team', icon: UserCog },
    { label: 'Company', href: '/app/company', icon: Building2 },
    { label: 'Profile and licenses', href: '/app/profile', icon: BadgeCheck },
    { label: 'Billing', href: '/app/billing', icon: CreditCard },
    { label: 'Integrations', href: '/app/integrations', icon: Plug },
    { label: 'Nurture', href: '/app/nurture', icon: Sprout },
    { label: 'Credits', href: '/app/credits', icon: HandHeart },
    { label: 'Share profile', href: '/app/share', icon: QrCode },
    { label: 'Profile leads', href: '/app/profile-leads', icon: Inbox },
    { label: 'Performance', href: '/app/performance', icon: Gauge },
    { label: 'Training', href: '/app/training', icon: GraduationCap },
    { label: 'Notifications', href: '/app/notifications', icon: Bell },
    { label: 'Help', href: '/app/help', icon: LifeBuoy },
];

export const ownerNav: NavItem[] = [
    { label: 'Group overview', href: '/app/group', icon: Gauge },
    { label: 'Seat map', href: '/app/group/seats', icon: Map },
    { label: 'Applications', href: '/app/group/applications', icon: UserPlus },
    { label: 'Members and enforcement', href: '/app/group/members', icon: ShieldAlert },
];

export const mobileTabs: NavItem[] = [
    { label: 'Home', href: '/app', icon: Home },
    { label: 'Inbox', href: '/app/inbox', icon: Inbox },
    { label: 'Send', href: '/app/send', icon: Send },
    { label: 'Pipeline', href: '/app/pipeline', icon: KanbanSquare },
    { label: 'Alerts', href: '/app/notifications', icon: Bell },
];
