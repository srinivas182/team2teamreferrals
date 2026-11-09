import { Link, useForm } from '@inertiajs/react';
import { Plus, Send, Trash2 } from 'lucide-react';
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
                </Card>
            </div>
        </AdminLayout>
    );
}
