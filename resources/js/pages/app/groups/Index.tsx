import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { money } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type G = {
    slug: string;
    name: string;
    industry: string;
    family: string;
    territory: string;
    price: number;
    trial: number;
    inviteOnly: boolean;
    live: boolean;
    mine: string | null;
};

/** Find a network to join: one seat per territory, apply for yours. */
export default function MemberGroups({ groups, seo }: { groups: G[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState('');
    const shown = groups.filter((g) => `${g.name} ${g.industry} ${g.family}`.toLowerCase().includes(q.toLowerCase()));
    return (
        <PortalLayout title="Join new groups" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <p className="mb-4 max-w-2xl text-muted">
                Pick a network for your industry and apply for your city. Your applications and seats are in{' '}
                <Link href="/app/groups/mine" className="font-medium text-primary hover:underline">
                    My groups
                </Link>
                .
            </p>
            <Input
                aria-label="Search networks"
                placeholder="Search by industry, e.g. solar, mortgage, roofing"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                className="mb-5 max-w-lg"
            />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {shown.map((g) => (
                    <Card key={g.slug} className="flex flex-col p-5">
                        <div className="text-xs font-medium text-subtle uppercase">{g.family}</div>
                        <h2 className="mt-1 font-semibold">{g.name}</h2>
                        <p className="text-sm text-muted">{g.industry}</p>
                        <p className="mt-3 text-sm">
                            {money(g.price)} per {g.territory === 'metro' ? 'metro area' : g.territory} / month · {g.trial} months free
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {!g.live && <Badge tone="sender">Founding seats</Badge>}
                            {g.inviteOnly && <Badge>Invite only</Badge>}
                            {g.mine && (
                                <Link href="/app/groups/mine" className="hover:underline">
                                    <Badge tone={g.mine === 'approved' ? 'success' : 'warning'}>
                                        {g.mine === 'pending' ? 'Applied, waiting for approval' : g.mine === 'approved' ? 'Approved' : 'Declined'}
                                    </Badge>
                                </Link>
                            )}
                        </div>
                        <div className="mt-auto pt-4">
                            {!g.inviteOnly && !g.mine && (
                                <Button size="sm" asChild>
                                    <Link href={`/app/groups/${g.slug}/apply`}>
                                        Apply for your {g.territory === 'metro' ? 'metro area' : g.territory}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
            {shown.length === 0 && <p className="text-muted">No networks match “{q}”.</p>}
        </PortalLayout>
    );
}
