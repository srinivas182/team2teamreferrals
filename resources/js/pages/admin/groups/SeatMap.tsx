import { Link, useForm } from '@inertiajs/react';
import { Plus, Send, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Segmented } from '@/components/ui/data';
import { Input } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import { xsrf } from '@/lib/xsrf';
import type { Seo } from '@/types/seo';

type Point = {
    id: number;
    name: string;
    state: string | null;
    lat: number;
    lon: number;
    state_of: 'held' | 'backup' | 'waitlist' | 'open';
    holder: string | null;
    backup: string | null;
    waitlist: number;
};
type ListRow = {
    line: number;
    email: string;
    name: string;
    city: string;
    state: string;
    city_id: number | null;
    place: string | null;
    seat: 'open' | 'taken' | 'invited' | null;
    problem: string | null;
};
const seatLabel = { open: 'Open seat', taken: 'Taken: backup or waitlist', invited: 'Already invited' } as const;

/** Invite from a list you already have: upload a CSV, check what each row will do, then send the ones that are fine. */
function InviteFromList({ basePath }: { basePath: string }) {
    const [list, setList] = useState<ListRow[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const send = useForm({ rows: [] as { email: string; city_id: number }[] });
    const good = (list ?? []).filter((r) => r.problem === null && r.city_id !== null);
    const upload = async (file: File) => {
        setBusy(true);
        setError(null);
        const body = new FormData();
        body.append('file', file);
        try {
            const res = await fetch(`${basePath}/invites/preview`, {
                method: 'POST',
                body,
                headers: { 'X-XSRF-TOKEN': xsrf(), Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = (await res.json()) as { rows?: ListRow[]; error?: string | null; message?: string };
            if (!res.ok) setError(data.message ?? 'The file could not be read.');
            else if (data.error) setError(data.error);
            else setList(data.rows ?? []);
        } catch {
            setError('The file could not be uploaded. Try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <div className="space-y-3 border-t border-line pt-3">
            <h3 className="text-sm font-semibold">Or upload a list</h3>
            <p className="text-xs text-muted">
                A CSV file (from Excel or Google Sheets: File → Download → CSV) with a first row naming the columns: <code>email</code>,{' '}
                <code>city</code>, <code>state</code>, and optionally <code>name</code>. Up to 500 rows. Nothing is sent until you check the list.
            </p>
            <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-line px-3 py-2 text-sm font-medium hover:bg-bg">
                <Upload className="size-4" aria-hidden /> {busy ? 'Reading…' : 'Choose a CSV file'}
                <input
                    type="file"
                    accept=".csv,text/csv"
                    className="sr-only"
                    onChange={(e) => {
                        const f = e.target.files?.[0];
                        e.target.value = '';
                        if (f) void upload(f);
                    }}
                />
            </label>
            {error && <Alert tone="danger">{error}</Alert>}
            {list && (
                <div className="space-y-2">
                    <p className="text-sm" aria-live="polite">
                        {good.length} ready to invite{list.length - good.length > 0 ? `, ${list.length - good.length} to fix` : ''}.
                    </p>
                    <div className="max-h-80 overflow-y-auto rounded-md border border-line">
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-surface text-left text-muted">
                                <tr>
                                    <th className="px-2 py-1.5">Line</th>
                                    <th className="px-2 py-1.5">Email</th>
                                    <th className="px-2 py-1.5">Seat</th>
                                </tr>
                            </thead>
                            <tbody>
                                {list.map((r) => (
                                    <tr key={r.line} className="border-t border-line align-top">
                                        <td className="px-2 py-1.5 text-muted tabular">{r.line}</td>
                                        <td className="px-2 py-1.5">
                                            <div className="break-all">{r.email || '—'}</div>
                                            {r.name && <div className="text-muted">{r.name}</div>}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <div>{r.place ?? [r.city, r.state].filter(Boolean).join(', ')}</div>
                                            {r.problem ? (
                                                <span className="text-danger dark:text-[#FDA4AF]">{r.problem}</span>
                                            ) : (
                                                r.seat && (
                                                    <span className={r.seat === 'open' ? 'text-success' : 'text-muted'}>{seatLabel[r.seat]}</span>
                                                )
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            disabled={good.length === 0}
                            loading={send.processing}
                            onClick={() => {
                                send.transform(() => ({ rows: good.map((r) => ({ email: r.email, city_id: r.city_id as number })) }));
                                send.post(`${basePath}/invites`, { preserveScroll: true, onSuccess: () => setList(null) });
                            }}
                        >
                            <Send /> Send {good.length} invitation{good.length === 1 ? '' : 's'}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setList(null)}>
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

type Props = {
    group: { slug: string; name: string; territory: string };
    basePath: string;
    points: Point[];
    counts: Record<string, number>;
    status?: string;
    note?: string | null;
    seo: Seo;
};

const color: Record<Point['state_of'], string> = { held: '#2451F5', backup: '#7C93F8', waitlist: '#FF7A1A', open: '#94A3B8' };
const label: Record<Point['state_of'], string> = { held: 'Held', backup: 'Backup only', waitlist: 'Waitlisted', open: 'Open' };
// Simple projection for the lower 48 (lon −125…−66, lat 24…50); Alaska and Hawaii appear in the list.
const inFrame = (p: Point) => p.lon > -125 && p.lon < -66 && p.lat > 24 && p.lat < 50;
const xy = (p: Point) => [((p.lon + 125) / 59) * 960, ((50 - p.lat) / 26) * 520 * (1 + (p.lat - 37) * 0.004)] as const;

/** Seat map for owners and staff, with bulk invitations to specific territories. */
export default function SeatMap({ group, basePath, points, counts, status, note, seo }: Props) {
    const [view, setView] = useState<'map' | 'list'>('map');
    const [hover, setHover] = useState<Point | null>(null);
    const [rows, setRows] = useState<{ email: string; city_id: number; city: string }[]>([{ email: '', city_id: 0, city: '' }]);
    const form = useForm({ rows: [] as { email: string; city_id: number }[] });
    const ready = rows.filter((r) => r.email && r.city_id);
    return (
        <AdminLayout title={`${group.name}: seat map`}>
            <SeoHead seo={seo} />
            <Link href={basePath} className="text-sm text-muted hover:text-ink">
                {group.name}
            </Link>
            {status && (
                <Alert tone="success" className="my-4 max-w-4xl">
                    {statusMessage(status)} {note}
                </Alert>
            )}
            <div className="mt-4 mb-4 flex flex-wrap items-center gap-3">
                {(Object.keys(label) as Point['state_of'][]).map((k) => (
                    <span key={k} className="flex items-center gap-2 text-sm">
                        <span className="size-3 rounded-full" style={{ background: color[k] }} aria-hidden /> {label[k]}{' '}
                        <span className="text-muted tabular">{counts[k] ?? 0}</span>
                    </span>
                ))}
                <div className="ml-auto">
                    <Segmented
                        label="View"
                        value={view}
                        onChange={setView}
                        options={[
                            ['map', 'Map'],
                            ['list', 'List'],
                        ]}
                    />
                </div>
            </div>
            <div className="grid gap-6 xl:grid-cols-[1fr_380px]">
                {view === 'map' ? (
                    <Card className="relative p-3">
                        <svg viewBox="0 0 960 540" className="h-auto w-full" role="img" aria-label={`Seat map with ${points.length} territories`}>
                            <rect width="960" height="540" rx="16" className="fill-bg" />
                            {/* Light grid every 5 degrees so locations read at a glance. */}
                            {Array.from({ length: 12 }, (_, i) => -120 + i * 5).map((lon) => (
                                <line
                                    key={`v${lon}`}
                                    x1={((lon + 125) / 59) * 960}
                                    x2={((lon + 125) / 59) * 960}
                                    y1="0"
                                    y2="540"
                                    className="stroke-line"
                                    strokeWidth="1"
                                />
                            ))}
                            {[30, 35, 40, 45].map((lat) => (
                                <line
                                    key={`h${lat}`}
                                    x1="0"
                                    x2="960"
                                    y1={((50 - lat) / 26) * 520}
                                    y2={((50 - lat) / 26) * 520}
                                    className="stroke-line"
                                    strokeWidth="1"
                                />
                            ))}
                            {points.filter(inFrame).map((p) => {
                                const [x, y] = xy(p);
                                return (
                                    <g
                                        key={p.id}
                                        onMouseEnter={() => setHover(p)}
                                        onMouseLeave={() => setHover(null)}
                                        onFocus={() => setHover(p)}
                                        tabIndex={0}
                                        aria-label={`${p.name}: ${label[p.state_of]}`}
                                    >
                                        <circle
                                            cx={x}
                                            cy={y}
                                            r={p.state_of === 'held' ? 11 : 9}
                                            fill={color[p.state_of]}
                                            stroke="#0E1B3D"
                                            strokeOpacity="0.35"
                                            strokeWidth="1.5"
                                        />
                                        {points.length <= 60 && (
                                            <text x={x + 14} y={y + 4} className="fill-ink text-[13px] font-medium">
                                                {p.name}
                                            </text>
                                        )}
                                    </g>
                                );
                            })}
                            {points.length === 0 && (
                                <text x="480" y="270" textAnchor="middle" className="fill-muted text-sm">
                                    No seats yet. They appear as teams apply or accept invitations.
                                </text>
                            )}
                        </svg>
                        {hover && (
                            <div className="absolute top-4 left-4 rounded-md border border-line bg-surface p-3 text-sm shadow">
                                <div className="font-semibold">{hover.name}</div>
                                <div className="text-muted">
                                    {label[hover.state_of]}
                                    {hover.holder ? ` · ${hover.holder}` : ''}
                                    {hover.backup ? ` · backup ${hover.backup}` : ''}
                                    {hover.waitlist ? ` · ${hover.waitlist} waiting` : ''}
                                </div>
                            </div>
                        )}
                    </Card>
                ) : (
                    <Card className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <tbody>
                                {points.map((p) => (
                                    <tr key={p.id} className="border-t border-line first:border-0">
                                        <td className="px-4 py-2.5 font-medium">{p.name}</td>
                                        <td className="px-4 py-2.5">
                                            <Badge>{label[p.state_of]}</Badge>
                                        </td>
                                        <td className="px-4 py-2.5 text-muted">{p.holder ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-muted tabular">{p.waitlist ? `${p.waitlist} waiting` : ''}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Card>
                )}
                <Card className="h-fit space-y-3 p-5">
                    <h2 className="font-semibold">Invite teams</h2>
                    <p className="text-sm text-muted">
                        Invite pros to specific {group.territory === 'city' ? 'cities' : `${group.territory}s`}. They get an email valid for 14 days;
                        if the seat is taken by then they become backup or join the waitlist.
                    </p>
                    {rows.map((r, i) => (
                        <div key={i} className="space-y-2 rounded-md border border-line p-3">
                            <Input
                                type="email"
                                placeholder="Email"
                                aria-label={`Email ${i + 1}`}
                                value={r.email}
                                onChange={(e) => setRows(rows.map((x, k) => (k === i ? { ...x, email: e.target.value } : x)))}
                            />
                            {r.city ? (
                                <div className="flex items-center justify-between text-sm">
                                    <span>{r.city}</span>
                                    <button
                                        className="text-primary"
                                        onClick={() => setRows(rows.map((x, k) => (k === i ? { ...x, city: '', city_id: 0 } : x)))}
                                    >
                                        Change
                                    </button>
                                </div>
                            ) : (
                                <CityPicker onPick={(c) => setRows(rows.map((x, k) => (k === i ? { ...x, city_id: c.id, city: c.label } : x)))} />
                            )}
                            {rows.length > 1 && (
                                <Button variant="ghost" size="sm" onClick={() => setRows(rows.filter((_, k) => k !== i))}>
                                    <Trash2 /> Remove
                                </Button>
                            )}
                        </div>
                    ))}
                    <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" size="sm" onClick={() => setRows([...rows, { email: '', city_id: 0, city: '' }])}>
                            <Plus /> Add another
                        </Button>
                        <Button
                            size="sm"
                            disabled={ready.length === 0}
                            loading={form.processing}
                            onClick={() => {
                                form.transform(() => ({ rows: ready.map(({ email, city_id }) => ({ email, city_id })) }));
                                form.post(`${basePath}/invites`, {
                                    preserveScroll: true,
                                    onSuccess: () => setRows([{ email: '', city_id: 0, city: '' }]),
                                });
                            }}
                        >
                            <Send /> Send {ready.length || ''} invitation{ready.length === 1 ? '' : 's'}
                        </Button>
                    </div>
                    <InviteFromList basePath={basePath} />
                </Card>
            </div>
        </AdminLayout>
    );
}
