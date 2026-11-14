import { router, usePage } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select } from '@/components/ui/field';

export type WidgetType = 'badge' | 'reviews' | 'button';

export type WidgetsProps = {
    slug: string;
    name: string;
    base: string;
    domains: string[];
    site: string | null;
    live: boolean;
    stats: Record<WidgetType, { view: number; click: number; referral: number }>;
};

const TYPES: { id: WidgetType; label: string; hint: string }[] = [
    { id: 'badge', label: 'Verified badge', hint: 'Your seat, licenses and rating, checked live.' },
    { id: 'reviews', label: 'Reviews', hint: 'Your rating and latest verified reviews.' },
    { id: 'button', label: '“Refer a client” button', hint: 'Opens your referral form.' },
];

const HELP: [string, string][] = [
    ['WordPress', 'Add a “Custom HTML” block where you want it and paste the code. (WordPress.com needs a Business plan or higher to run scripts.)'],
    ['Squarespace', 'Edit the page, add a “Code” block, paste the code and turn off “Display source”.'],
    [
        'Wix',
        'Wix puts pasted code inside its own box, which hides the widget. Use Settings → Custom code (paid plans), or paste the code anyway: visitors then see the plain link.',
    ],
    ['Any other website', 'Paste the code into the page’s HTML where the widget should appear. It works on any site that allows a script.'],
];

/** Portal → Share → Website widgets: choose, preview, copy the code, list the websites, and see what they bring in. */
export function WebsiteWidgets({ w, canEdit }: { w: WidgetsProps; canEdit: boolean }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [type, setType] = useState<WidgetType>('badge');
    const [theme, setTheme] = useState<'light' | 'dark'>('light');
    const [accent, setAccent] = useState('#2451F5');
    const [copied, setCopied] = useState(false);
    const [domains, setDomains] = useState<string[]>(w.domains.length ? w.domains : ['']);
    const hex = /^#[0-9a-fA-F]{6}$/.test(accent) ? accent.slice(1) : '2451F5';
    const code = useMemo(
        () =>
            `<div class="t2t-widget" data-team="${w.slug}" data-widget="${type}" data-theme="${theme}" data-accent="${hex}">` +
            `<a href="${w.base}/t/${w.slug}">${escapeHtml(w.name)}: verified on Team to Team Referrals</a></div>\n` +
            `<script async src="${w.base}/embed/v1.js"></script>`,
        [w, type, theme, hex],
    );
    // The preview sizes itself the same way the real widget does: the frame reports its height.
    const preview = useRef<HTMLIFrameElement>(null);
    useEffect(() => {
        const onMessage = (e: MessageEvent) => {
            const data = e.data as { t2t?: number; h?: number } | null;
            if (e.origin === window.location.origin && data?.t2t === 1 && preview.current && e.source === preview.current.contentWindow) {
                preview.current.style.height = `${Math.min(800, Math.max(0, Number(data.h) || 0))}px`;
            }
        };
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);
    const sites = [...new Set([...(w.site ? [w.site] : []), ...w.domains])];
    return (
        <Card className="max-w-4xl space-y-5 p-6">
            <div>
                <h2 className="font-semibold">Website widgets</h2>
                <p className="text-sm text-muted">
                    Show your verified seat on your own website. Each widget checks itself every time it loads, so it’s never out of date.
                </p>
            </div>
            {!w.live && (
                <Alert tone="info">
                    Your widgets are hidden right now. They show while your profile is public and you hold a filled seat in a live group.
                </Alert>
            )}
            <div className="grid gap-4 md:grid-cols-3">
                <Field label="Widget">
                    <Select value={type} onChange={(e) => setType(e.target.value as WidgetType)}>
                        {TYPES.map((t) => (
                            <option key={t.id} value={t.id}>
                                {t.label}
                            </option>
                        ))}
                    </Select>
                </Field>
                <Field label="Background">
                    <Select value={theme} onChange={(e) => setTheme(e.target.value as 'light' | 'dark')}>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </Select>
                </Field>
                <Field label="Accent colour" hint="Matches your brand.">
                    <div className="flex items-center gap-2">
                        <input
                            type="color"
                            aria-label="Pick accent colour"
                            value={`#${hex}`}
                            onChange={(e) => setAccent(e.target.value)}
                            className="h-10 w-12 rounded border border-line"
                        />
                        <Input value={accent} onChange={(e) => setAccent(e.target.value)} maxLength={7} />
                    </div>
                </Field>
            </div>
            <p className="text-sm text-muted">{TYPES.find((t) => t.id === type)?.hint}</p>
            <div className={`rounded-lg border border-line p-4 ${theme === 'dark' ? 'bg-navy-900' : 'bg-bg'}`}>
                <iframe
                    key={`${type}-${theme}-${hex}`}
                    ref={preview}
                    title="Widget preview"
                    src={`/embed/${w.slug}/${type}?theme=${theme}&accent=${hex}`}
                    className="block w-full border-0"
                    style={{ maxWidth: type === 'reviews' ? 420 : 340, height: type === 'reviews' ? 320 : type === 'button' ? 64 : 96 }}
                />
            </div>
            <div>
                <h3 className="text-sm font-semibold">Your code</h3>
                <pre className="mt-2 overflow-x-auto rounded-md bg-bg p-3 text-xs break-all whitespace-pre-wrap">{code}</pre>
                <Button
                    size="sm"
                    variant="secondary"
                    className="mt-2"
                    onClick={() => void navigator.clipboard.writeText(code).then(() => setCopied(true))}
                >
                    <Copy /> {copied ? 'Copied' : 'Copy code'}
                </Button>
                <p className="mt-2 text-xs text-muted">If scripts are blocked, visitors still see a plain link to your profile.</p>
            </div>
            <div>
                <h3 className="text-sm font-semibold">Where widgets may appear</h3>
                <p className="text-sm text-muted">
                    Browsers show your widgets only on these websites, so nobody can copy them onto theirs.
                    {w.site && <> Your profile website ({w.site}) is always included.</>}
                </p>
                {canEdit ? (
                    <div className="mt-2 space-y-2">
                        {domains.map((d, i) => (
                            <Input
                                key={i}
                                aria-label={`Website ${i + 1}`}
                                placeholder="example.com"
                                value={d}
                                onChange={(e) => setDomains(domains.map((x, j) => (j === i ? e.target.value : x)))}
                            />
                        ))}
                        {errors.domains && <p className="text-sm text-danger">{errors.domains}</p>}
                        <div className="flex flex-wrap gap-2">
                            {domains.length < 10 && (
                                <Button size="sm" variant="ghost" onClick={() => setDomains([...domains, ''])}>
                                    Add a website
                                </Button>
                            )}
                            <Button size="sm" onClick={() => router.post('/app/share/widget-domains', { domains }, { preserveScroll: true })}>
                                Save websites
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="mt-2 text-sm">{sites.length ? sites.join(', ') : 'None yet: your team leader can add them.'}</p>
                )}
            </div>
            <div>
                <h3 className="text-sm font-semibold">Last 30 days</h3>
                <div className="mt-2 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                <th className="py-1 font-medium">Widget</th>
                                <th className="py-1 text-right font-medium">Views</th>
                                <th className="py-1 text-right font-medium">Clicks</th>
                                <th className="py-1 text-right font-medium">Referrals</th>
                            </tr>
                        </thead>
                        <tbody>
                            {TYPES.map((t) => (
                                <tr key={t.id} className="border-t border-line">
                                    <td className="py-1">{t.label}</td>
                                    <td className="py-1 text-right tabular">{w.stats[t.id].view}</td>
                                    <td className="py-1 text-right tabular">{w.stats[t.id].click}</td>
                                    <td className="py-1 text-right tabular">{w.stats[t.id].referral}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <details className="text-sm">
                <summary className="cursor-pointer font-semibold">How to add it to your website</summary>
                <dl className="mt-2 space-y-2">
                    {HELP.map(([k, v]) => (
                        <div key={k}>
                            <dt className="font-medium">{k}</dt>
                            <dd className="text-muted">{v}</dd>
                        </div>
                    ))}
                </dl>
            </details>
        </Card>
    );
}

function escapeHtml(s: string): string {
    return s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] ?? c);
}
