import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type App = {
    id: number;
    status: string;
    outcome: string | null;
    license: string | null;
    reason: string | null;
    when: string;
    team: string;
    territory: string;
    wouldBe: string;
};

const would: Record<string, string> = { primary: 'Gets the seat', backup: 'Becomes backup', waitlist: 'Joins the waitlist' };

export default function Applications({
    group,
    basePath,
    applications,
    status,
    seo,
}: {
    group: { slug: string; name: string };
    basePath: string;
    applications: App[];
    status?: string;
    seo: Seo;
}) {
    const [declining, setDeclining] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    return (
        <AdminLayout title={`${group.name}: applications`}>
            <SeoHead seo={seo} />
            {basePath.startsWith('/admin') && (
                <Link href={`/admin/groups/${group.slug}`} className="text-sm text-muted hover:text-ink">
                    {group.name}
                </Link>
            )}
            {status && (
                <Alert tone="success" className="my-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <Card className="mt-4 overflow-x-auto">
                <table className="w-full min-w-[820px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Team', 'Territory', 'License', 'Applied', 'Status', ''].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {applications.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-muted">
                                    No applications yet.
                                </td>
                            </tr>
                        )}
                        {applications.map((a) => (
                            <tr key={a.id} className="border-t border-line align-top">
                                <td className="px-4 py-3 font-medium">{a.team}</td>
                                <td className="px-4 py-3">{a.territory}</td>
                                <td className="px-4 py-3 font-mono text-xs">{a.license ?? '—'}</td>
                                <td className="px-4 py-3 text-muted">{new Date(a.when.replace(' ', 'T') + 'Z').toLocaleDateString()}</td>
                                <td className="px-4 py-3">
                                    {a.status === 'pending' ? (
                                        <Badge tone="warning">Pending · {would[a.wouldBe]}</Badge>
                                    ) : a.status === 'approved' ? (
                                        <Badge tone="success">Approved · {a.outcome}</Badge>
                                    ) : (
                                        <Badge>{a.status}</Badge>
                                    )}
                                    {a.reason && <div className="mt-1 text-xs text-muted">{a.reason}</div>}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    {a.status === 'pending' &&
                                        (declining === a.id ? (
                                            <div className="flex items-center justify-end gap-2">
                                                <Input
                                                    aria-label="Reason"
                                                    placeholder="Reason the team will see"
                                                    value={reason}
                                                    onChange={(e) => setReason(e.target.value)}
                                                    className="h-9 w-56"
                                                />
                                                <Button
                                                    size="sm"
                                                    variant="danger"
                                                    disabled={!reason.trim()}
                                                    onClick={() =>
                                                        router.post(
                                                            `${basePath}/${a.id}/decline`,
                                                            { reason },
                                                            { preserveScroll: true, onSuccess: () => (setDeclining(null), setReason('')) },
                                                        )
                                                    }
                                                >
                                                    Decline
                                                </Button>
                                            </div>
                                        ) : (
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    size="sm"
                                                    onClick={() => router.post(`${basePath}/${a.id}/approve`, {}, { preserveScroll: true })}
                                                >
                                                    Approve
                                                </Button>
                                                <Button size="sm" variant="ghost" onClick={() => setDeclining(a.id)}>
                                                    Decline
                                                </Button>
                                            </div>
                                        ))}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
        </AdminLayout>
    );
}
