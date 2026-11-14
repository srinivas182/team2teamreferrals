import { router, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Lead = {
    id: number;
    status: string;
    at: string;
    client: string;
    phone: string | null;
    email: string | null;
    need: string;
    referrer: string;
    referrerEmail: string;
    source: string;
};

/** Portal → Profile leads: clients referred from your public profile by people outside the network. */
export default function ProfileLeads({ leads, seo }: { leads: Lead[]; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Profile leads" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-3">
                {leads.length === 0 && (
                    <Card className="p-8 text-center text-muted">Share your profile link and QR code to start receiving leads here.</Card>
                )}
                {leads.map((l) => (
                    <Card key={l.id} className="space-y-1 p-5 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold">{l.client}</span>
                            {l.phone && (
                                <a href={`tel:${l.phone}`} className="text-primary">
                                    {l.phone}
                                </a>
                            )}
                            {l.email && (
                                <a href={`mailto:${l.email}`} className="text-primary">
                                    {l.email}
                                </a>
                            )}
                            <select
                                aria-label="Status"
                                className="ml-auto h-8 rounded-md border border-line bg-surface px-2"
                                value={l.status}
                                onChange={(e) => router.post(`/app/profile-leads/${l.id}`, { status: e.target.value }, { preserveScroll: true })}
                            >
                                <option value="new">New</option>
                                <option value="contacted">Contacted</option>
                                <option value="closed">Closed</option>
                            </select>
                        </div>
                        <p>{l.need}</p>
                        <p className="text-muted">
                            {l.source !== 'profile' && (
                                <span className="mr-1 rounded bg-bg px-1.5 py-0.5 text-xs font-medium">From your website</span>
                            )}
                            From {l.referrer} (<a href={`mailto:${l.referrerEmail}`}>{l.referrerEmail}</a>) · {l.at}
                        </p>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
