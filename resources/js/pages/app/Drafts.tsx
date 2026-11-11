import { router, usePage } from '@inertiajs/react';
import { Inbox, Mail } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Draft = { id: string; client: string | null; channel: string; subject: string | null; at: string; expires: string; toCheck: number };

/** Portal → Drafts: forward a lead to your private address and it becomes a draft to check and send. */
export default function Drafts({ address, drafts, status, seo }: { address: string | null; drafts: Draft[]; status?: string; seo: Seo }) {
    const { auth } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState(false);
    return (
        <PortalLayout title="Drafts" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl space-y-4">
                {status && <Alert tone="success">{statusMessage(status)}</Alert>}
                <Card className="space-y-2 p-5">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Mail className="size-5 text-primary" aria-hidden /> Forward a lead, send a referral
                    </h2>
                    {address ? (
                        <>
                            <p className="text-sm text-muted">
                                Forward any lead email to your private address from {auth.user?.email}. It becomes a draft here in seconds; nothing is
                                sent until you check it and confirm your client’s consent.
                            </p>
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded bg-bg px-2 py-1 text-sm">{address}</code>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => {
                                        void navigator.clipboard?.writeText(address);
                                        setCopied(true);
                                    }}
                                >
                                    {copied ? 'Copied' : 'Copy'}
                                </Button>
                            </div>
                            <p className="text-xs text-muted">Keep it private: it only accepts email from your own address. 1 AI credit per email.</p>
                        </>
                    ) : (
                        <p className="text-sm text-muted">Email forwarding isn’t switched on yet.</p>
                    )}
                </Card>
                {drafts.length === 0 && (
                    <Card className="flex items-center gap-3 p-6 text-muted">
                        <Inbox className="size-5" aria-hidden /> No drafts waiting.
                    </Card>
                )}
                {drafts.map((d) => (
                    <Card key={d.id} className="flex flex-wrap items-center gap-3 p-4">
                        <div className="min-w-0 flex-1">
                            <p className="font-medium">{d.client ?? 'Client name to check'}</p>
                            <p className="text-sm text-muted">
                                {d.channel} · {d.subject ?? 'no subject'} · {d.at} · expires {d.expires}
                            </p>
                        </div>
                        {d.toCheck > 0 && <Badge tone="warning">{d.toCheck} to check</Badge>}
                        <Button size="sm" onClick={() => router.visit(`/app/send?draft=${d.id}`)}>
                            Check and send
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                window.confirm('Discard this draft? The original is deleted.') &&
                                router.post(`/app/drafts/${d.id}/discard`, {}, { preserveScroll: true })
                            }
                        >
                            Discard
                        </Button>
                    </Card>
                ))}
            </div>
        </PortalLayout>
    );
}
