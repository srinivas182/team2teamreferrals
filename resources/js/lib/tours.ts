/**
 * First-run guided tours (Sprint 52a). Each step points at an element marked with data-tour="…". Steps whose
 * element isn't on screen (a phone layout, a switched-off feature) are skipped. The visual checks in CI open each
 * tour's page and fail if a target is missing, so a tour never points at a button that's gone.
 */
export type TourStep = { target: string; title: string; body: string };
export type TourDef = { id: string; path: RegExp; steps: TourStep[] };

export const TOURS: TourDef[] = [
    {
        id: 'member-home',
        path: /^\/app\/?$/,
        steps: [
            {
                target: 'checklist',
                title: 'Your next steps',
                body: 'This list takes you from sign-up to your first seat. Tick through it at your own pace.',
            },
            {
                target: 'search',
                title: 'Find anything',
                body: 'Search referrals, teams and cities, or jump to any page. Press Ctrl K (⌘ K on a Mac) from anywhere.',
            },
            { target: 'nav-inbox', title: 'Referrals sent to you', body: 'New referrals land in your Inbox, with the time you have to answer.' },
            {
                target: 'notifications',
                title: 'Updates',
                body: 'Referral updates, payments and messages from your network appear here. Choose how you hear about each in Settings.',
            },
            {
                target: 'ally',
                title: 'Ask Ally',
                body: 'Ally answers questions about your account and how the network works, any time. If it can’t help, it passes you to a person.',
            },
        ],
    },
    {
        id: 'admin-home',
        path: /^\/admin\/?$/,
        steps: [
            { target: 'admin-search', title: 'Search the admin', body: 'Find people, teams and settings. Ctrl K works here too.' },
            { target: 'admin-nav-groups', title: 'Networks and seats', body: 'Create networks, open cities and approve applications.' },
            { target: 'admin-nav-support', title: 'Support', body: 'Tickets from members, partners and visitors, with response-time targets.' },
            {
                target: 'admin-nav-system',
                title: 'System',
                body: 'Company details, providers, modules and the footer credit. Changes apply straight away, with no deploy.',
            },
        ],
    },
];

export const PARTNER_TOUR: TourStep[] = [
    {
        target: 'partner-tabs',
        title: 'Everything in one place',
        body: 'Your results, listing, cities, billing and enquiries. Add a card under Billing before your free trial ends to stay listed.',
    },
    { target: 'partner-listing', title: 'See it as visitors do', body: 'Open your public listing to check how it looks.' },
];
