import { Link, router, useForm } from '@inertiajs/react';
import { Lock, Paperclip } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo } from '@/types/seo';

type Props = {
    ticket: {
        id: string;
        number: string;
        subject: string;
        name: string;
        email: string;
        status: string;
        priority: string;
        category: string;
        assigneeId: number | null;
        source: string;
        created: string;
        due: { label: string; overdue: boolean } | null;
        rating: string | null;
        ratingComment: string | null;
        firstResponse: string | null;
    };
    messages: {
        id: number;
        author: string;
        name: string;
        body: string;
        internal: boolean;
        via: string;
        at: string;
        files: { name: string; href: string }[];
    }[];
    context: {
        member: {
            name: string;
            joined: string | null;
            team: string | null;
            teamSlug: string | null;
            seats: string[];
            unpaid: number;
            filled: boolean;
        } | null;
        partner: { name: string; status: string; billing: string; slug: string } | null;
        earlier: { id: string; number: string; subject: string; status: string }[];
    };
    staff: { id: number; name: string }[];
    macros: { id: number; title: string; body: string }[];
    categories: Record<string, string>;
    priorities: Record<string, string>;
    statuses: Record<string, string>;
    flash?: string;
    seo: Seo;
};

/** One ticket for staff: the conversation (with internal notes), reply or note, and the customer's context. */
export default function SupportTicketAdmin({ ticket, messages, context, staff, macros, categories, priorities, statuses, flash, seo }: Props) {
    const f = useForm({ body: '', internal: false, status: 'pending', files: [] as File[] });
    const set = (data: Record<string, string | number | null>) => router.post(`/admin/support/${ticket.id}/update`, data, { preserveScroll: true });
    return (
        <AdminLayout
            title={`${ticket.number}: ${ticket.subject}`}
            actions={
                <Button variant="secondary" size="sm" asChild>
                    <Link href="/admin/support">Back to inbox</Link>
                </Button>
            }
        >
            <SeoHead seo={seo} />
            {flash && (
                <Alert tone="success" className="mb-4 max-w-5xl">
                    {statusMessage(flash)}
                </Alert>
            )}
            <div className="grid max-w-6xl gap-6 lg:grid-cols-[1fr_18rem]">
                <div className="space-y-3">
                    {messages.map((m) => (
                        <Card
                            key={m.id}
                            className={`p-4 ${m.internal ? 'border-warning/50 bg-[#FEF9E7] dark:bg-warning/10' : m.author === 'staff' ? 'border-primary/40' : ''}`}
                        >
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted">
                                <span className="font-medium text-ink">{m.name}</span>
                                <span>· {m.at}</span>
                                {m.via === 'email' && <span>· by email</span>}
                                {m.internal && (
                                    <Badge tone="warning">
                                        <Lock className="size-3" aria-hidden /> {m.author === 'system' ? 'Change' : 'Internal note'}
                                    </Badge>
                                )}
                            </div>
                            <p className="mt-2 whitespace-pre-line">{m.body}</p>
                            {m.files.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-3 text-sm">
                                    {m.files.map((x) => (
                                        <li key={x.href}>
                                            <a href={x.href} className="flex items-center gap-1 text-primary">
                                                <Paperclip className="size-3.5" aria-hidden /> {x.name}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    ))}
                    <Card className="space-y-3 p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Button size="sm" variant={f.data.internal ? 'secondary' : 'primary'} onClick={() => f.setData('internal', false)}>
                                Reply to customer
                            </Button>
                            <Button size="sm" variant={f.data.internal ? 'primary' : 'secondary'} onClick={() => f.setData('internal', true)}>
                                <Lock /> Internal note
                            </Button>
                            {macros.length > 0 && (
                                <Select
                                    aria-label="Insert a saved reply"
                                    className="h-9 w-56"
                                    value=""
                                    onChange={(e) => {
                                        const m = macros.find((x) => String(x.id) === e.target.value);
                                        if (m) f.setData('body', (f.data.body ? `${f.data.body}\n\n` : '') + m.body);
                                    }}
                                >
                                    <option value="">Insert a saved reply…</option>
                                    {macros.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.title}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </div>
                        <Field
                            label={f.data.internal ? 'Note (only staff see this)' : `Reply to ${ticket.name}`}
                            hint="{first_name} and {ticket} are filled in."
                            error={f.errors.body}
                        >
                            <Textarea rows={6} value={f.data.body} onChange={(e) => f.setData('body', e.target.value)} />
                        </Field>
                        <Input
                            type="file"
                            multiple
                            aria-label="Attach files"
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="py-2"
                            onChange={(e) => f.setData('files', Array.from(e.target.files ?? []).slice(0, 3))}
                        />
                        <div className="flex flex-wrap items-end gap-2">
                            {!f.data.internal && (
                                <Field label="Then set to">
                                    <Select value={f.data.status} onChange={(e) => f.setData('status', e.target.value)} className="h-9">
                                        <option value="pending">Waiting on customer</option>
                                        <option value="open">Open</option>
                                        <option value="solved">Solved</option>
                                    </Select>
                                </Field>
                            )}
                            <Button
                                loading={f.processing}
                                disabled={!f.data.body.trim()}
                                onClick={() =>
                                    f.post(`/admin/support/${ticket.id}/reply`, {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => f.reset('body', 'files'),
                                    })
                                }
                            >
                                {f.data.internal ? 'Add note' : 'Send reply'}
                            </Button>
                        </div>
                    </Card>
                </div>
                <aside className="space-y-4">
                    <Card className="space-y-3 p-4 text-sm">
                        <div>
                            <div className="font-medium">{ticket.name}</div>
                            <a href={`mailto:${ticket.email}`} className="text-primary">
                                {ticket.email}
                            </a>
                        </div>
                        <div className="text-muted">
                            Opened {ticket.created} {ticket.source !== 'web' ? `by ${ticket.source}` : ''}
                            {ticket.firstResponse ? ` · first reply ${ticket.firstResponse}` : ''}
                        </div>
                        {ticket.due && <div className={ticket.due.overdue ? 'font-medium text-danger' : ''}>{ticket.due.label}</div>}
                        <Field label="Status">
                            <Select value={ticket.status} onChange={(e) => set({ status: e.target.value })} className="h-9">
                                {Object.entries(statuses).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Priority">
                            <Select value={ticket.priority} onChange={(e) => set({ priority: e.target.value })} className="h-9">
                                {Object.entries(priorities).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Topic">
                            <Select value={ticket.category} onChange={(e) => set({ category: e.target.value })} className="h-9">
                                {Object.entries(categories).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Assigned to">
                            <Select
                                value={ticket.assigneeId ?? ''}
                                onChange={(e) => set({ assignee_id: e.target.value ? Number(e.target.value) : null })}
                                className="h-9"
                            >
                                <option value="">Nobody</option>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {ticket.rating && (
                            <div>
                                Rated <Badge tone={ticket.rating === 'good' ? 'success' : 'danger'}>{ticket.rating}</Badge>
                                {ticket.ratingComment && <p className="mt-1 text-muted">“{ticket.ratingComment}”</p>}
                            </div>
                        )}
                    </Card>
                    {context.member && (
                        <Card className="space-y-1 p-4 text-sm">
                            <h2 className="font-semibold">Member</h2>
                            <p>
                                {context.member.name}
                                {context.member.joined ? `, joined ${context.member.joined}` : ''}
                            </p>
                            {context.member.team && <p>Team: {context.member.team}</p>}
                            {context.member.seats.map((s) => (
                                <p key={s} className="text-muted">
                                    {s}
                                </p>
                            ))}
                            {context.member.unpaid > 0 && <p className="text-danger">{context.member.unpaid} unpaid invoice(s)</p>}
                        </Card>
                    )}
                    {context.partner && (
                        <Card className="space-y-1 p-4 text-sm">
                            <h2 className="font-semibold">Partner</h2>
                            <p>{context.partner.name}</p>
                            <p className="text-muted">
                                {context.partner.status} · billing {context.partner.billing}
                            </p>
                        </Card>
                    )}
                    {context.earlier.length > 0 && (
                        <Card className="space-y-1 p-4 text-sm">
                            <h2 className="font-semibold">Earlier tickets</h2>
                            {context.earlier.map((e) => (
                                <p key={e.id}>
                                    <Link href={`/admin/support/${e.id}`} className="text-primary">
                                        {e.number}
                                    </Link>{' '}
                                    {e.subject} <span className="text-muted">({e.status})</span>
                                </p>
                            ))}
                        </Card>
                    )}
                </aside>
            </div>
        </AdminLayout>
    );
}
