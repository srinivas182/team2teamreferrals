import { Link } from '@inertiajs/react';
import { ScrollText, UserCog } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { Kpi } from '@/components/ui/data';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Stats = { members: number; staff: number; signInsToday: number; failedToday: number };

export default function Dashboard({ stats, launch, seo }: { stats: Stats; launch: { ready: number; total: number } | null; seo: Seo }) {
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
            <div className="mt-6 grid gap-4 md:grid-cols-2">
                {[
                    ['Staff', 'Invite people and choose what each can do.', '/admin/staff', UserCog],
                    ['Audit log', 'Every sign-in, security change and admin action.', '/admin/audit', ScrollText],
                ].map(([t, d, h, I]) => {
                    const Icon = I as typeof UserCog;
                    return (
                        <Link key={h as string} href={h as string} className="block">
                            <Card className="flex items-start gap-4 p-5 hover:shadow-md">
                                <Icon className="mt-0.5 size-5 text-primary" aria-hidden />
                                <div>
                                    <h2 className="font-semibold">{t as string}</h2>
                                    <p className="text-sm text-muted">{d as string}</p>
                                </div>
                            </Card>
                        </Link>
                    );
                })}
            </div>
            <p className="mt-6 text-sm text-muted">
                The full network dashboard (referrals, revenue, response-time trends) arrives with the reporting sprints.
            </p>
        </AdminLayout>
    );
}
