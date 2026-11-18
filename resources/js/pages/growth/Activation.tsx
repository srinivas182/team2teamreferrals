import { router } from '@inertiajs/react';
import { GrowthTabs } from '@/components/growth/GrowthTabs';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { Field, Select } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import type { Seo } from '@/types/seo';

type Step = { key: string; label: string; teams: number; share: number; medianHours: number | null };
type Stuck = { team: number; name: string; leader: string; email: string; step: string; next: string; days: number };
type Props = {
    funnel: Step[];
    stuck: Stuck[];
    total: number;
    days: number;
    group: number | null;
    periods: { days: number; label: string }[];
    groups: { id: number; name: string }[];
    stuckAfter: number;
    seo: Seo;
};

const time = (h: number | null) => (h === null ? '' : h < 1 ? 'under an hour' : h < 48 ? `${Math.round(h)} h` : `${Math.round(h / 24)} days`);

/** Admin → Growth → Activation: from sign-up to first accepted referral, where teams stop, and who to help. */
export default function Activation({ funnel, stuck, total, days, group, periods, groups, stuckAfter, seo }: Props) {
    const go = (next: { days?: number; group?: number | null }) =>
        router.get(
            '/admin/growth/activation',
            { days: next.days ?? days, group: (next.group === undefined ? group : next.group) ?? undefined },
            { preserveScroll: true },
        );
    return (
        <AdminLayout title="Growth: activation">
            <SeoHead seo={seo} />
            <div className="max-w-5xl space-y-6">
                <GrowthTabs current="/admin/growth/activation" />
                <div className="flex flex-wrap items-end gap-3">
                    <Field label="Teams that signed up">
                        <Select value={String(days)} onChange={(e) => go({ days: Number(e.target.value) })}>
                            {periods.map((p) => (
                                <option key={p.days} value={p.days}>
                                    {p.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Network">
                        <Select
                            value={group === null ? '' : String(group)}
                            onChange={(e) => go({ group: e.target.value === '' ? null : Number(e.target.value) })}
                        >
                            <option value="">All networks</option>
                            {groups.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <p className="pb-2 text-sm text-muted">{total} teams. Staff, demo and load-test accounts aren’t counted.</p>
                </div>
                <Card className="p-5">
                    <h2 className="font-semibold">From sign-up to first accepted referral</h2>
                    <ol className="mt-4 space-y-3">
                        {funnel.map((s, i) => (
                            <li key={s.key}>
                                <div className="flex flex-wrap items-baseline gap-2 text-sm">
                                    <span className="font-medium">
                                        {i + 1}. {s.label}
                                    </span>
                                    <span className="ml-auto tabular">
                                        {s.teams} · {Math.round(s.share * 100)}%
                                    </span>
                                    {s.medianHours !== null && (
                                        <span className="w-40 text-right text-muted">typically {time(s.medianHours)} after sign-up</span>
                                    )}
                                </div>
                                <div className="mt-1 h-2 rounded bg-bg" aria-hidden>
                                    <div className="h-2 rounded bg-primary" style={{ width: `${Math.max(1, s.share * 100)}%` }} />
                                </div>
                            </li>
                        ))}
                    </ol>
                </Card>
                <Card className="p-5">
                    <h2 className="font-semibold">Stuck for {stuckAfter} days or more</h2>
                    <p className="text-sm text-muted">Longest first. A short personal email or call usually gets a team moving again.</p>
                    {stuck.length === 0 ? (
                        <p className="mt-3 text-sm text-muted">No team is stuck. Good.</p>
                    ) : (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted">
                                    <tr>
                                        <th className="py-2">Team</th>
                                        <th>Done</th>
                                        <th>Next step</th>
                                        <th className="text-right">Days</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {stuck.map((t) => (
                                        <tr key={t.team} className="border-t border-line">
                                            <td className="py-2">
                                                <div className="font-medium">{t.name}</div>
                                                <a href={`mailto:${t.email}`} className="text-xs text-primary">
                                                    {t.leader}
                                                </a>
                                            </td>
                                            <td>{t.step}</td>
                                            <td>{t.next}</td>
                                            <td className="text-right tabular">{t.days}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
