import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';
import { usePage } from '@inertiajs/react';

type Req = { id: string; client: string; city: string; group: string; timing: string; answers: Record<string, unknown>; since: string };

/** Website requests that no seat holder took: the first team in the network and state to take one gets it. */
export default function RequestBoard({ requests, status, seo }: { requests: Req[]; status?: string; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Open request board" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <p className="text-sm text-muted">
                    Requests from the website that the seat holder and backup couldn’t take. Contact details are shared once you take one.
                </p>
                {requests.length === 0 && <Card className="p-6 text-muted">Nothing on the board right now.</Card>}
                {requests.map((r) => (
                    <Card key={r.id} className="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div>
                            <p className="font-semibold">
                                {r.client} · {r.city}
                            </p>
                            <p className="text-sm text-muted">
                                {r.group} · {r.timing}
                            </p>
                            <p className="mt-1 text-sm">
                                {Object.entries(r.answers)
                                    .map(([, v]) => (Array.isArray(v) ? v.join(', ') : String(v)))
                                    .join(' · ')}
                            </p>
                        </div>
                        <Button onClick={() => router.post(`/app/requests/${r.id}/take`)}>Take this request</Button>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
