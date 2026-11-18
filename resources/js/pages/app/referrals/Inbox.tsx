import { Link } from '@inertiajs/react';
import { FileText, Globe, Inbox as InboxIcon, UserRound } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import type { Seo } from '@/types/seo';
import { type Item, ReferralList } from './List';

type Other = { kind: 'lead' | 'draft' | 'request'; id: string; title: string; detail: string; at: string; href: string };
type Kind = { kind: Other['kind']; label: string; count: number };
type Show = 'all' | 'referrals' | Other['kind'];

const icons = { lead: UserRound, draft: FileText, request: Globe } as const;
const labels = { lead: 'Profile lead', draft: 'Draft', request: 'Open request' } as const;

/**
 * One inbox for everything that comes in: referrals sent to the team, new leads from the public profile, drafts made
 * from forwarded emails, voice notes and photos, and requests from the public the team can take.
 */
export default function Inbox({
    items,
    others = [],
    kinds = [],
    status,
    seo,
}: {
    items: Item[];
    others?: Other[];
    kinds?: Kind[];
    status?: string;
    seo: Seo;
}) {
    const [show, setShow] = useState<Show>('all');
    const waiting = items.filter((i) => i.status === 'sent').length;
    const filters: { key: Show; label: string; count: number }[] = [
        { key: 'all', label: 'All', count: items.length + others.length },
        { key: 'referrals', label: 'Referrals', count: items.length },
        ...kinds.filter((k) => k.count > 0).map((k) => ({ key: k.kind, label: k.label, count: k.count })),
    ];
    const visible = show === 'all' ? others : show === 'referrals' ? [] : others.filter((o) => o.kind === show);
    const before = (
        <div className="mb-4 max-w-4xl space-y-4">
            {filters.length > 2 && (
                <div role="group" aria-label="Show" className="flex flex-wrap gap-2">
                    {filters.map((f) => (
                        <button
                            key={f.key}
                            type="button"
                            aria-pressed={show === f.key}
                            onClick={() => setShow(f.key)}
                            className={`rounded-full border px-3 py-1 text-sm font-medium ${show === f.key ? 'border-primary bg-primary text-on-primary' : 'border-line bg-surface text-muted hover:text-ink'}`}
                        >
                            {f.label} <span className="tabular">{f.count}</span>
                        </button>
                    ))}
                </div>
            )}
            {visible.length > 0 && (
                <Card>
                    <h2 className="border-b border-line px-4 py-3 text-sm font-semibold">
                        {show === 'all' ? 'Also waiting for you' : filters.find((f) => f.key === show)?.label}
                    </h2>
                    <ul className="divide-y divide-line">
                        {visible.map((o) => {
                            const Icon = icons[o.kind];
                            return (
                                <li key={o.id}>
                                    <Link href={o.href} className="flex items-center gap-3 p-4 hover:bg-bg">
                                        <Icon className="size-5 shrink-0 text-muted" aria-hidden />
                                        <div className="min-w-0 flex-1">
                                            <div className="font-medium">{o.title}</div>
                                            <div className="text-sm text-muted">{o.detail}</div>
                                        </div>
                                        <Badge>{labels[o.kind]}</Badge>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </Card>
            )}
            {show === 'all' && others.length > 0 && (
                <h2 className="flex items-center gap-2 pt-2 text-sm font-semibold">
                    <InboxIcon className="size-4 text-muted" aria-hidden /> Referrals{waiting > 0 ? ` · ${waiting} waiting for you` : ''}
                </h2>
            )}
        </div>
    );
    return (
        <ReferralList
            title="Inbox"
            items={items}
            mode="in"
            status={status}
            seo={seo}
            before={before}
            showList={show === 'all' || show === 'referrals'}
            empty="No referrals yet. They’ll appear here the moment one is sent to you."
        />
    );
}
