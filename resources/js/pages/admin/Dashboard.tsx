import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, DollarSign, LifeBuoy, ScrollText, Sparkles, Store, UserCog } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { Kpi } from '@/components/ui/data';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

/** Where staff most often go next, shown when they may open it. */
const SHORTCUTS: [string, string, string, typeof UserCog, string][] = [
    ['Network dashboard', 'Referrals, response times and trends across every network.', '/admin/network', ArrowLeftRight, 'referrals.view'],
    ['Revenue', 'Seats, partner listings and plans, by month.', '/admin/revenue', DollarSign, 'revenue.view'],
    ['Support', 'Tickets waiting for a reply, with response-time targets.', '/admin/support', LifeBuoy, 'support.manage'],
    ['Partners', 'Applications and listing changes to review.', '/admin/partners', Store, 'partners.manage'],
    ['Ally', 'What the AI assistant answered, and what it couldn’t.', '/admin/ally', Sparkles, 'support.manage'],
    ['Staff', 'Invite people and choose what each can do.', '/admin/staff', UserCog, 'staff.manage'],
    ['Audit log', 'Every sign-in, security change and admin action.', '/admin/audit', ScrollText, 'audit.view'],
];

type Stats = { members: number; staff: number; signInsToday: number; failedToday: number };

export default function Dashboard({ stats, launch, seo }: { stats: Stats; launch: { ready: number; total: number } | null; seo: Seo }) {
    const can = usePage<SharedProps>().props.auth.user?.permissions ?? [];
    return (
        <AdminLayout title="Admin">
            <SeoHead seo={seo} />
            {launch && (
                <Link href="/admin/industries/launch" className="mb-4 block">
                    <Card className="flex flex-wrap items-center gap-4 p-5 hover:shadow-md">
                        <div className="font-display text-2xl font-bold tabular">
                            {launch.ready} of {launch.total}
                        </div>
                        <div className="text-sm text-muted">industries ready to go live</div>
                        <div className="h-2 min-w-40 flex-1 rounded-full bg-bg">
                            <div
                                className="h-2 rounded-full bg-primary"
                                style={{ width: `${launch.total ? (launch.ready / launch.total) * 100 : 0}%` }}
                            />
                        </div>
                    </Card>
                </Link>
            )}
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi label="Members" value={stats.members.toLocaleString()} />
                <Kpi label="Staff and super admins" value={stats.staff.toLocaleString()} />
                <Kpi label="Sign-ins today" value={stats.signInsToday.toLocaleString()} />
                <Kpi label="Failed sign-ins today" value={stats.failedToday.toLocaleString()} hint="Spikes can mean someone is guessing passwords." />
            </div>
            <div className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {SHORTCUTS.filter(([, , , , p]) => can.includes(p)).map(([t, d, h, Icon]) => (
                    <Link key={h} href={h} className="block">
                        <Card className="flex h-full items-start gap-4 p-5 hover:shadow-md">
                            <Icon className="mt-0.5 size-5 text-primary" aria-hidden />
                            <div>
                                <h2 className="font-semibold">{t}</h2>
                                <p className="text-sm text-muted">{d}</p>
                            </div>
                        </Card>
                    </Link>
                ))}
            </div>
        </AdminLayout>
    );
}
