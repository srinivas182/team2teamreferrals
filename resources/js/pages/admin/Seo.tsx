import { useForm } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/AdminLayout';
import { statusMessage } from '@/lib/status';
import type { Seo as SeoMeta } from '@/types/seo';

type Audit = { at: string; pages: number; failed: number; results: { path: string; type: string; problems: string[] }[] };
type Props = {
    crawlers: { bot: string; purpose: string; allowed: boolean }[];
    indexable: boolean;
    audit: Audit | null;
    status?: string;
    seo: SeoMeta;
};

/** Super admin → SEO and AEO: AI crawler rules and the latest check of every kind of public page. */
export default function Seo({ crawlers, indexable, audit, status, seo }: Props) {
    const f = useForm<{ allowed: string[] }>({ allowed: crawlers.filter((c) => c.allowed).map((c) => c.bot) });
    const toggle = (bot: string) =>
        f.setData('allowed', f.data.allowed.includes(bot) ? f.data.allowed.filter((b) => b !== bot) : [...f.data.allowed, bot]);
    const failing = audit?.results.filter((r) => r.problems.length > 0) ?? [];
    return (
        <AdminLayout title="SEO and AEO">
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {!indexable && (
                    <Alert tone="info">
                        This site is not open to search engines yet (pre-launch), so robots.txt blocks every crawler whatever is set below.
                    </Alert>
                )}
                <Card className="space-y-4 p-6">
                    <div>
                        <h2 className="font-semibold">AI crawlers</h2>
                        <p className="text-sm text-muted">
                            Allowed crawlers can read public pages so AI assistants can quote and link to them. Blocking one only stops future visits;
                            portal, admin and client pages are always blocked.
                        </p>
                    </div>
                    <ul className="divide-y divide-line">
                        {crawlers.map((c) => (
                            <li key={c.bot} className="flex items-start gap-3 py-2">
                                <input
                                    id={`bot-${c.bot}`}
                                    type="checkbox"
                                    className="mt-1 size-4"
                                    checked={f.data.allowed.includes(c.bot)}
                                    onChange={() => toggle(c.bot)}
                                />
                                <label htmlFor={`bot-${c.bot}`} className="text-sm">
                                    <span className="font-medium">{c.bot}</span>
                                    <span className="block text-muted">{c.purpose}</span>
                                </label>
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button loading={f.processing} onClick={() => f.post('/admin/seo/crawlers', { preserveScroll: true })}>
                            Save crawler rules
                        </Button>
                        <a href="/robots.txt" target="_blank" rel="noopener" className="text-sm font-medium text-primary">
                            View robots.txt
                        </a>
                    </div>
                </Card>
                <Card className="space-y-3 p-6">
                    <h2 className="font-semibold">Page check</h2>
                    {audit === null ? (
                        <p className="text-sm text-muted">
                            No check has run on this server yet. It runs on every release; a nightly check comes next.
                        </p>
                    ) : (
                        <>
                            <p className="text-sm">
                                {audit.pages} pages checked on {new Date(audit.at).toLocaleString()}:{' '}
                                {audit.failed === 0 ? (
                                    <span className="font-medium text-success">all passed</span>
                                ) : (
                                    <span className="font-medium text-danger">{audit.failed} with problems</span>
                                )}
                            </p>
                            {failing.length > 0 && (
                                <ul className="space-y-2 text-sm">
                                    {failing.map((r) => (
                                        <li key={r.path}>
                                            <a href={r.path} target="_blank" rel="noopener" className="font-medium text-primary">
                                                {r.path}
                                            </a>
                                            <ul className="ml-4 list-disc text-muted">
                                                {r.problems.map((p) => (
                                                    <li key={p}>{p}</li>
                                                ))}
                                            </ul>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </Card>
            </div>
        </AdminLayout>
    );
}
