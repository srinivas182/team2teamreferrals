import { Link } from '@inertiajs/react';
import { CheckCircle2, MinusCircle, XCircle } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { AdminLayout } from '@/layouts/AdminLayout';
import { cn } from '@/lib/utils';
import type { Seo } from '@/types/seo';

type Check = { ok: boolean | null; detail: string };
type Row = { slug: string; name: string; tier: string; family: string; status: string; checks: Record<string, Check>; ready: boolean };

const cols: [string, string, string][] = [
    ['terms', 'Terms', ''],
    ['agreement', 'Agreement', 'agreement'],
    ['licensing', 'Licensing', ''],
    ['compliance', 'Compliance', ''],
    ['group', 'Group', '/admin/groups'],
];

function Cell({ c, href }: { c: Check; href?: string }) {
    const Icon = c.ok === true ? CheckCircle2 : c.ok === false ? XCircle : MinusCircle;
    const body = (
        <span
            className={cn(
                'flex items-start gap-1.5',
                c.ok === true ? 'text-success' : c.ok === false ? 'text-danger dark:text-[#FDA4AF]' : 'text-muted',
            )}
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span className="text-xs">{c.detail}</span>
        </span>
    );
    return href ? (
        <Link href={href} className="hover:underline">
            {body}
        </Link>
    ) : (
        body
    );
}

/** Launch readiness: what is left before each of the 46 industries can go live. */
export default function Launch({ board, summary, seo }: { board: Row[]; summary: { ready: number; total: number }; seo: Seo }) {
    const [show, setShow] = useState<'all' | 'blocked' | 'ready'>('blocked');
    const rows = board.filter((r) => (show === 'all' ? true : show === 'ready' ? r.ready : !r.ready));
    const pct = summary.total ? Math.round((summary.ready / summary.total) * 100) : 0;
    return (
        <AdminLayout title="Launch readiness">
            <SeoHead seo={seo} />
            <Card className="mb-6 flex flex-wrap items-center gap-6 p-6">
                <div>
                    <div className="font-display text-4xl font-extrabold tabular">
                        {summary.ready} <span className="text-xl font-semibold text-muted">of {summary.total}</span>
                    </div>
                    <div className="text-sm text-muted">industries ready to go live</div>
                </div>
                <div className="min-w-48 flex-1">
                    <div className="h-3 rounded-full bg-bg">
                        <div className="h-3 rounded-full" style={{ width: `${pct}%`, background: 'linear-gradient(90deg,#2451F5,#FF7A1A)' }} />
                    </div>
                    <div className="mt-1 text-sm text-muted tabular">{pct}%</div>
                </div>
                <div className="flex flex-wrap gap-2 text-sm">
                    {(['a', 'b', 'c'] as const).map((t) => (
                        <a
                            key={t}
                            href={`/admin/industries/counsel-pack/tier-${t}.pdf`}
                            className="rounded-md border border-line px-3 py-1.5 font-medium hover:border-primary"
                        >
                            Tier {t.toUpperCase()} counsel pack
                        </a>
                    ))}
                </div>
                <Segmented
                    label="Show"
                    value={show}
                    onChange={setShow}
                    options={[
                        ['blocked', 'Still to do'],
                        ['ready', 'Ready'],
                        ['all', 'All'],
                    ]}
                />
            </Card>
            <Card className="overflow-x-auto">
                <table className="w-full min-w-[980px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            <th scope="col" className="px-4 py-3 font-medium">
                                Industry
                            </th>
                            {cols.map(([k, l]) => (
                                <th key={k} scope="col" className="px-3 py-3 font-medium">
                                    {l}
                                </th>
                            ))}
                            <th scope="col" className="px-4 py-3 font-medium">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.slug} className="border-t border-line align-top">
                                <td className="px-4 py-3">
                                    <Link href={`/admin/industries/${r.slug}`} className="font-medium hover:text-primary">
                                        {r.name}
                                    </Link>
                                    <div>
                                        <Badge tone={r.tier === 'A' ? 'success' : r.tier === 'B' ? 'warning' : 'neutral'}>Tier {r.tier}</Badge>
                                    </div>
                                </td>
                                {cols.map(([k, , path]) => (
                                    <td key={k} className="px-3 py-3">
                                        {r.checks[k] && (
                                            <Cell
                                                c={r.checks[k]!}
                                                href={path ? `/admin/industries/${r.slug}/${path}` : `/admin/industries/${r.slug}`}
                                            />
                                        )}
                                    </td>
                                ))}
                                <td className="px-4 py-3">
                                    {r.status === 'live' ? (
                                        <Badge tone="success">Live</Badge>
                                    ) : r.ready ? (
                                        <Badge tone="info">Ready</Badge>
                                    ) : (
                                        <Badge>Draft</Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-muted">
                                    Nothing here.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
