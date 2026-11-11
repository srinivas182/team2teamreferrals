import { router, usePage } from '@inertiajs/react';
import { Copy, Download, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Props = {
    link: string;
    profile: string;
    qr: string;
    public: boolean;
    stats: { view: number; qr: number; link: number; referral: number };
    signature: string;
    canEdit: boolean;
    status?: string;
    seo: Seo;
};

/** Portal → Share your profile: the link, QR code, counts, email signature and printable card. */
export default function ShareProfile({ link, profile, qr, public: isPublic, stats, signature, canEdit, status, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [copied, setCopied] = useState<string | null>(null);
    const copy = (what: string, text: string) => void navigator.clipboard.writeText(text).then(() => setCopied(what));
    return (
        <PortalLayout title="Share your profile" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {status && (
                <Alert tone="success" className="mb-4 max-w-4xl">
                    {statusMessage(status)}
                </Alert>
            )}
            <div className="grid max-w-4xl gap-6 md:grid-cols-[1fr_auto]">
                <Card className="space-y-4 p-6">
                    <h2 className="font-semibold">Your “refer to me” link</h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="rounded bg-bg px-2 py-1 text-sm">{link}</code>
                        <Button size="sm" variant="secondary" onClick={() => copy('link', link)}>
                            <Copy /> {copied === 'link' ? 'Copied' : 'Copy'}
                        </Button>
                        <a
                            href={profile}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary"
                        >
                            View <ExternalLink className="size-3.5" />
                        </a>
                    </div>
                    <div className="grid grid-cols-4 gap-3 text-center">
                        {[
                            ['Visits', stats.view],
                            ['QR scans', stats.qr],
                            ['Link clicks', stats.link],
                            ['Referrals', stats.referral],
                        ].map(([l, v]) => (
                            <div key={l as string}>
                                <div className="font-display text-2xl font-bold tabular">{v}</div>
                                <div className="text-xs text-muted">{l} (30 days)</div>
                            </div>
                        ))}
                    </div>
                    <div>
                        <h3 className="text-sm font-semibold">Email signature</h3>
                        <div className="mt-2 rounded-md border border-line p-3" dangerouslySetInnerHTML={{ __html: signature }} />
                        <Button size="sm" variant="secondary" className="mt-2" onClick={() => copy('sig', signature)}>
                            <Copy /> {copied === 'sig' ? 'Copied' : 'Copy signature HTML'}
                        </Button>
                    </div>
                    {canEdit && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={isPublic}
                                onChange={(e) => router.post('/app/share/public', { public: e.target.checked }, { preserveScroll: true })}
                            />{' '}
                            Show my profile publicly
                        </label>
                    )}
                </Card>
                <Card className="flex flex-col items-center gap-3 p-6">
                    <img src={qr} alt="QR code for your profile" className="size-48" />
                    <a href="/app/share/card.pdf" className="inline-flex items-center gap-1 text-sm font-medium text-primary">
                        <Download className="size-4" /> Printable QR card
                    </a>
                </Card>
            </div>
        </PortalLayout>
    );
}
