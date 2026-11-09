import { router } from '@inertiajs/react';
import { ExternalLink, FileText, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Row = {
    id: number;
    team: string;
    industry: string;
    state: string;
    number: string;
    holder: string | null;
    expires: string | null;
    submitted: string;
    note: string | null;
    issuingBody: string | null;
    lookupUrl: string | null;
    document: string | null;
    duplicates: number;
};
type St = 'pending' | 'verified' | 'rejected' | 'expired';

export default function LicenseQueue({
    status,
    counts,
    rows,
    flash,
    seo,
}: {
    status: St;
    counts: Record<string, number>;
    rows: Row[];
    flash?: string;
    seo: Seo;
}) {
    const [notes, setNotes] = useState<Record<number, string>>({});
    return (
        <AdminLayout title="License queue">
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="mb-4">
                <Segmented
                    label="Show"
                    value={status}
                    onChange={(v) => router.get('/admin/licenses', { status: v })}
                    options={(['pending', 'verified', 'rejected', 'expired'] as const).map(
                        (k) => [k, `${k[0]!.toUpperCase()}${k.slice(1)} (${counts[k] ?? 0})`] as [St, string],
                    )}
                />
            </div>
            <div className="max-w-5xl space-y-3">
                {rows.length === 0 && <Card className="p-10 text-center text-muted">Nothing here.</Card>}
                {rows.map((r) => (
                    <Card key={r.id} className="p-5">
                        <div className="flex flex-wrap items-start gap-4">
                            <div className="min-w-0 flex-1">
                                <div className="font-semibold">{r.team}</div>
                                <div className="text-sm text-muted">
                                    {r.industry} · {r.state} · <span className="font-mono text-ink">{r.number}</span>
                                    {r.holder ? ` · name on license: ${r.holder}` : ''}
                                    {r.expires ? ` · expires ${r.expires}` : ''}
                                </div>
                                {r.issuingBody && <div className="mt-1 text-xs text-subtle">Issued by {r.issuingBody}</div>}
                                {r.duplicates > 0 && (
                                    <Badge tone="danger" className="mt-2">
                                        <TriangleAlert className="size-3" aria-hidden /> Used by {r.duplicates} other team
                                        {r.duplicates > 1 ? 's' : ''}
                                    </Badge>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {r.lookupUrl && (
                                    <Button variant="secondary" size="sm" asChild>
                                        <a href={r.lookupUrl} target="_blank" rel="noreferrer">
                                            <ExternalLink /> Check on the state’s site
                                        </a>
                                    </Button>
                                )}
                                {r.document && (
                                    <Button variant="secondary" size="sm" asChild>
                                        <a href={r.document} target="_blank" rel="noreferrer">
                                            <FileText /> Document
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </div>
                        {status === 'pending' && (
                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                <Input
                                    aria-label="Note"
                                    placeholder="Note (required to reject; the team sees it)"
                                    value={notes[r.id] ?? ''}
                                    onChange={(e) => setNotes({ ...notes, [r.id]: e.target.value })}
                                    className="h-9 min-w-64 flex-1"
                                />
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            `/admin/licenses/${r.id}`,
                                            { decision: 'verify', note: notes[r.id] ?? null },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Verify
                                </Button>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    disabled={!notes[r.id]?.trim()}
                                    onClick={() =>
                                        router.post(`/admin/licenses/${r.id}`, { decision: 'reject', note: notes[r.id] }, { preserveScroll: true })
                                    }
                                >
                                    Reject
                                </Button>
                            </div>
                        )}
                        {r.note && status !== 'pending' && <p className="mt-3 text-sm text-muted">Note: {r.note}</p>}
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
