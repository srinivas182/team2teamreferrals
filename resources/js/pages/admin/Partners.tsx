import { Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, TriangleAlert } from 'lucide-react';
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

type St = 'pending' | 'approved' | 'rejected' | 'suspended';
type Row = {
    id: string;
    slug: string;
    name: string;
    category: string;
    contact: string;
    email: string;
    phone: string | null;
    website: string | null;
    description: string;
    logo: string | null;
    licensed: boolean;
    licenseLabel: string | null;
    licenseNumber: string | null;
    licenseState: string | null;
    licenseLookup: string | null;
    licenseVerified: string | null;
    offer: string | null;
    cities: string[];
    reason: string | null;
    applied: string;
    reviewed: string | null;
    endorsements: number;
    enquiries: number;
};
const LABELS: Record<St, string> = { pending: 'Waiting', approved: 'Approved', rejected: 'Not approved', suspended: 'Suspended' };

/** Admin → Partners: review applications (license checked first where the field needs one), then manage live listings. */
export default function AdminPartners({
    status,
    counts,
    rows,
    enabled,
    flash,
    seo,
}: {
    status: St;
    counts: Record<St, number>;
    rows: Row[];
    enabled: boolean;
    flash?: string;
    seo: Seo;
}) {
    const [reasons, setReasons] = useState<Record<string, string>>({});
    const [checked, setChecked] = useState<Record<string, boolean>>({});
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const act = (id: string, action: string, data: Record<string, string | boolean | undefined> = {}) =>
        router.post(`/admin/partners/${id}/${action}`, data, { preserveScroll: true });
    return (
        <AdminLayout
            title="Partners"
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/partners/categories">Categories</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {!enabled && (
                <Alert tone="info" className="mb-4 max-w-4xl">
                    The partner directory is switched off in System → Modules, so approved listings aren’t shown.
                </Alert>
            )}
            {flash && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            {(errors.license || errors.reason) && (
                <Alert tone="danger" className="mb-4 max-w-4xl">
                    {errors.license ?? errors.reason}
                </Alert>
            )}
            <div className="mb-4">
                <Segmented
                    label="Show"
                    value={status}
                    onChange={(v) => router.get('/admin/partners', { status: v })}
                    options={(Object.keys(LABELS) as St[]).map((k) => [k, `${LABELS[k]} (${counts[k] ?? 0})`] as [St, string])}
                />
            </div>
            <div className="max-w-5xl space-y-3">
                {rows.length === 0 && <Card className="p-10 text-center text-muted">Nothing here.</Card>}
                {rows.map((r) => (
                    <Card key={r.id} className="p-5">
                        <div className="flex flex-wrap items-start gap-4">
                            {r.logo && <img src={r.logo} alt="" width={48} height={48} className="size-12 rounded-md object-contain" />}
                            <div className="min-w-0 flex-1">
                                <div className="font-semibold">
                                    {status === 'approved' ? (
                                        <a href={`/partner/${r.slug}`} target="_blank" rel="noreferrer" className="text-primary">
                                            {r.name}
                                        </a>
                                    ) : (
                                        r.name
                                    )}
                                </div>
                                <div className="text-sm text-muted">
                                    {r.category} · applied {r.applied}
                                    {r.reviewed ? ` · reviewed ${r.reviewed}` : ''} · {r.contact}, {r.email}
                                    {r.phone ? `, ${r.phone}` : ''}
                                </div>
                                {r.website && (
                                    <a href={r.website} target="_blank" rel="noreferrer nofollow" className="text-sm text-primary">
                                        {r.website}
                                    </a>
                                )}
                                <p className="mt-2 text-sm whitespace-pre-line">{r.description}</p>
                                <p className="mt-2 text-sm">
                                    <span className="font-medium">Cities:</span> {r.cities.join('; ')}
                                </p>
                                {r.offer && (
                                    <p className="mt-1 text-sm">
                                        <span className="font-medium">Offer:</span> {r.offer}
                                    </p>
                                )}
                                <p className="mt-1 text-sm">
                                    <span className="font-medium">{r.licenseLabel ?? 'License'}:</span>{' '}
                                    {r.licenseNumber ? (
                                        <span className="font-mono">
                                            {r.licenseNumber}
                                            {r.licenseState ? ` (${r.licenseState})` : ''}
                                        </span>
                                    ) : (
                                        'none given'
                                    )}
                                    {r.licenseVerified && <Badge className="ml-2">checked {r.licenseVerified}</Badge>}
                                    {r.licensed && !r.licenseNumber && (
                                        <Badge tone="danger" className="ml-2">
                                            <TriangleAlert className="size-3" aria-hidden /> required for this category
                                        </Badge>
                                    )}
                                </p>
                                {status !== 'pending' && (
                                    <p className="mt-1 text-sm text-muted">
                                        {r.enquiries} enquiries · {r.endorsements} endorsements
                                    </p>
                                )}
                                {r.reason && <p className="mt-2 text-sm text-muted">Reason given: {r.reason}</p>}
                            </div>
                            {r.licenseLookup && (
                                <Button variant="secondary" size="sm" asChild>
                                    <a href={r.licenseLookup} target="_blank" rel="noreferrer">
                                        <ExternalLink /> Check with the regulator
                                    </a>
                                </Button>
                            )}
                        </div>
                        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                            {status === 'pending' && r.licenseNumber && !r.licenseVerified && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={checked[r.id] ?? false}
                                        onChange={(e) => setChecked({ ...checked, [r.id]: e.target.checked })}
                                    />
                                    I checked the license
                                </label>
                            )}
                            {(status === 'pending' || status === 'approved') && (
                                <Input
                                    aria-label="Reason"
                                    placeholder={
                                        status === 'pending'
                                            ? 'Reason (required to reject; the business sees it)'
                                            : 'Reason (required to suspend; the business sees it)'
                                    }
                                    value={reasons[r.id] ?? ''}
                                    onChange={(e) => setReasons({ ...reasons, [r.id]: e.target.value })}
                                    className="h-9 min-w-64 flex-1"
                                />
                            )}
                            {status === 'pending' && (
                                <>
                                    <Button size="sm" onClick={() => act(r.id, 'approve', { license_checked: checked[r.id] ?? false })}>
                                        Approve
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="danger"
                                        disabled={!reasons[r.id]?.trim()}
                                        onClick={() => act(r.id, 'reject', { reason: reasons[r.id] })}
                                    >
                                        Reject
                                    </Button>
                                </>
                            )}
                            {status === 'approved' && (
                                <Button
                                    size="sm"
                                    variant="danger"
                                    disabled={!reasons[r.id]?.trim()}
                                    onClick={() => act(r.id, 'suspend', { reason: reasons[r.id] })}
                                >
                                    Suspend
                                </Button>
                            )}
                            {status === 'suspended' && (
                                <Button size="sm" onClick={() => act(r.id, 'reinstate')}>
                                    Reinstate
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </AdminLayout>
    );
}
