import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type SeatRow = {
    id: number;
    territory: string;
    status: string;
    payer: string;
    trialEnds: string | null;
    founding: boolean;
    branch: string;
    group: string;
};
type Props = {
    company: { name: string; id: string } | null;
    branches?: { id: string; name: string; leader: string; email: string }[];
    seats?: SeatRow[];
    waiting?: { territory: string; branch: string; position: number }[];
    privateNetworks?: { slug: string; name: string }[];
    groups?: { slug: string; name: string }[];
    status?: string;
    note?: string | null;
    seo: Seo;
};

type Row = { email: string; group: string; city_id: number; city: string };

export default function Company({ company, branches = [], seats = [], waiting = [], privateNetworks = [], groups = [], status, note, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const create = useForm({ name: '' });
    const [rows, setRows] = useState<Row[]>([{ email: '', group: '', city_id: 0, city: '' }]);
    const invite = useForm({ rows: [] as { email: string; group: string | null; city_id: number | null }[] });
    const ready = rows.filter((r) => r.email);
    if (!company) {
        return (
            <PortalLayout title="Company" user={{ name: auth.user?.name ?? '', team: '' }}>
                <SeoHead seo={seo} />
                <Card className="max-w-xl space-y-4 p-6">
                    <h2 className="font-semibold">Run several cities as one company?</h2>
                    <p className="text-sm text-muted">
                        Create a company to add a city leader for each branch, see every seat in one place, and choose who pays for each seat: the
                        company centrally, or the city team.
                    </p>
                    <Field label="Company name" error={create.errors.name}>
                        <Input value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} />
                    </Field>
                    <Button loading={create.processing} onClick={() => create.post('/app/company')}>
                        Create company
                    </Button>
                </Card>
            </PortalLayout>
        );
    }
    return (
        <PortalLayout title={company.name} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4">
                    {statusMessage(status)} {note}
                </Alert>
            )}
            <div className="mb-6 grid gap-4 sm:grid-cols-4">
                {[
                    [String(branches.length), 'branches'],
                    [String(seats.length), 'seats held'],
                    [String(seats.filter((s) => s.status === 'trial').length), 'on trial'],
                    [String(seats.filter((s) => s.payer === 'company').length), 'paid by the company'],
                ].map(([a, b]) => (
                    <Card key={b} className="p-5">
                        <div className="font-display text-3xl font-bold tabular">{a}</div>
                        <div className="text-sm text-muted">{b}</div>
                    </Card>
                ))}
            </div>
            <Card className="mb-6 overflow-x-auto">
                <table className="w-full min-w-[820px] text-sm">
                    <thead className="text-left text-muted">
                        <tr>
                            {['Territory', 'Group', 'Branch', 'Who pays', 'Trial ends', 'Status'].map((h) => (
                                <th key={h} scope="col" className="px-4 py-3 font-medium">
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {seats.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-muted">
                                    No seats yet. Invite your city leaders below, with a seat offer if you like.
                                </td>
                            </tr>
                        )}
                        {seats.map((s) => (
                            <tr key={s.id} className="border-t border-line">
                                <td className="px-4 py-3 font-medium">
                                    {s.territory} {s.founding && <Badge tone="sender">Founding</Badge>}
                                </td>
                                <td className="px-4 py-3 text-muted">{s.group}</td>
                                <td className="px-4 py-3">{s.branch}</td>
                                <td className="px-4 py-3">
                                    <Select
                                        aria-label={`Who pays for ${s.territory}`}
                                        className="h-9 w-36"
                                        value={s.payer}
                                        onChange={(e) =>
                                            router.post(`/app/company/seats/${s.id}/payer`, { payer: e.target.value }, { preserveScroll: true })
                                        }
                                    >
                                        <option value="company">Company</option>
                                        <option value="team">City team</option>
                                    </Select>
                                </td>
                                <td className="px-4 py-3 text-muted tabular">{s.trialEnds ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <Badge tone={s.status === 'grace' ? 'warning' : s.status === 'inactive' ? 'danger' : 'success'}>{s.status}</Badge>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </Card>
            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="space-y-3 p-6">
                    <h2 className="font-semibold">Branches</h2>
                    <ul className="divide-y divide-line text-sm">
                        {branches.map((b) => (
                            <li key={b.id} className="py-2.5">
                                <div className="font-medium">{b.name}</div>
                                <div className="text-muted">
                                    City leader: {b.leader} · {b.email}
                                </div>
                            </li>
                        ))}
                    </ul>
                    {waiting.length > 0 && (
                        <>
                            <h3 className="pt-2 text-sm font-semibold">Waiting for seats</h3>
                            <ul className="text-sm text-muted">
                                {waiting.map((w) => (
                                    <li key={w.territory + w.branch}>
                                        {w.branch} · {w.territory} ({w.position} waiting)
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    {privateNetworks.length > 0 && (
                        <p className="text-sm text-muted">
                            Private network:{' '}
                            {privateNetworks.map((p) => (
                                <Link key={p.slug} href={`/app/group/${p.slug}`} className="font-medium text-primary">
                                    {p.name}
                                </Link>
                            ))}
                        </p>
                    )}
                </Card>
                <Card className="space-y-3 p-6">
                    <h2 className="font-semibold">Add branches</h2>
                    <p className="text-sm text-muted">Invite city leaders by email. Optionally offer each one a seat in a group at the same time.</p>
                    {rows.map((r, i) => (
                        <div key={i} className="space-y-2 rounded-md border border-line p-3">
                            <Input
                                type="email"
                                placeholder="City leader’s email"
                                aria-label={`Email ${i + 1}`}
                                value={r.email}
                                onChange={(e) => setRows(rows.map((x, k) => (k === i ? { ...x, email: e.target.value } : x)))}
                            />
                            <Select
                                aria-label={`Group ${i + 1}`}
                                value={r.group}
                                onChange={(e) => setRows(rows.map((x, k) => (k === i ? { ...x, group: e.target.value } : x)))}
                            >
                                <option value="">No seat offer</option>
                                {groups.map((g) => (
                                    <option key={g.slug} value={g.slug}>
                                        {g.name}
                                    </option>
                                ))}
                            </Select>
                            {r.group &&
                                (r.city ? (
                                    <div className="text-sm">{r.city}</div>
                                ) : (
                                    <CityPicker onPick={(c) => setRows(rows.map((x, k) => (k === i ? { ...x, city_id: c.id, city: c.label } : x)))} />
                                ))}
                            {rows.length > 1 && (
                                <Button variant="ghost" size="sm" onClick={() => setRows(rows.filter((_, k) => k !== i))}>
                                    <Trash2 /> Remove
                                </Button>
                            )}
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button variant="secondary" size="sm" onClick={() => setRows([...rows, { email: '', group: '', city_id: 0, city: '' }])}>
                            <Plus /> Add another
                        </Button>
                        <Button
                            size="sm"
                            disabled={ready.length === 0}
                            loading={invite.processing}
                            onClick={() => {
                                invite.transform(() => ({
                                    rows: ready.map((r) => ({ email: r.email, group: r.group || null, city_id: r.group ? r.city_id : null })),
                                }));
                                invite.post('/app/company/invites', {
                                    preserveScroll: true,
                                    onSuccess: () => setRows([{ email: '', group: '', city_id: 0, city: '' }]),
                                });
                            }}
                        >
                            <Send /> Send invitations
                        </Button>
                    </div>
                    {Object.values(invite.errors)[0] && <Alert tone="danger">{Object.values(invite.errors)[0]}</Alert>}
                </Card>
            </div>
        </PortalLayout>
    );
}
