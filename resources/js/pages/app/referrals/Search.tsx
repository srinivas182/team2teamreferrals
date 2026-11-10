import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Result = { referral: string; client: string; territory: string; who: string; excerpt: string; when: string };

/** Search every conversation on the team's referrals (same privacy rules as the referral itself). */
export default function MessageSearch({ q: initial, results, seo }: { q: string; results: Result[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState(initial);
    return (
        <PortalLayout title="Search messages" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <form
                className="mb-4 flex max-w-xl gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.get('/app/messages/search', { q });
                }}
            >
                <Input aria-label="Search messages" placeholder="e.g. HOA, appointment, permit" value={q} onChange={(e) => setQ(e.target.value)} />
                <Button type="submit">Search</Button>
            </form>
            <Card className="max-w-3xl">
                {initial && results.length === 0 && <p className="p-6 text-muted">No messages match “{initial}”.</p>}
                <ul className="divide-y divide-line">
                    {results.map((r, i) => (
                        <li key={i}>
                            <Link href={`/app/referrals/${r.referral}`} className="block p-4 hover:bg-bg">
                                <div className="text-sm font-medium">
                                    {r.client} · {r.territory}
                                </div>
                                <div className="text-sm">{r.excerpt}</div>
                                <div className="text-xs text-muted">
                                    {r.who} · {new Date(r.when.replace(' ', 'T') + 'Z').toLocaleString()}
                                </div>
                            </Link>
                        </li>
                    ))}
                </ul>
            </Card>
        </PortalLayout>
    );
}
