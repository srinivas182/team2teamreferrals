import { router, useForm, usePage } from '@inertiajs/react';
import { Copy, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import { statusMessage } from '@/lib/status';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Token = {
    id: string;
    name: string;
    mode: 'live' | 'test';
    prefix: string;
    scopes: string[];
    createdBy: string;
    lastUsed: string | null;
    expires: string | null;
    created: string | null;
    state: 'active' | 'expired' | 'owner_left';
};
type Props = {
    tokens: Token[];
    scopes: Record<string, string>;
    canManage: boolean;
    limit: number;
    newToken: { id: string; plain: string } | null;
    status?: string;
    seo: Seo;
};

/** Portal → Developers: the team's API tokens. A new token is shown once. */
export default function Developers({ tokens, scopes, canManage, limit, newToken, status, seo }: Props) {
    const f = useForm<{ name: string; mode: 'live' | 'test'; scopes: string[]; expires_at: string }>({
        name: '',
        mode: 'test',
        scopes: ['referrals:read', 'referrals:write', 'groups:read'],
        expires_at: '',
    });
    const [copied, setCopied] = useState(false);
    const { auth } = usePage<SharedProps>().props;
    const toggle = (s: string) => f.setData('scopes', f.data.scopes.includes(s) ? f.data.scopes.filter((x) => x !== s) : [...f.data.scopes, s]);
    return (
        <PortalLayout title="Developers (API)" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-6">
                {statusMessage(status) && <Alert tone="success">{statusMessage(status)}</Alert>}
                {newToken && (
                    <Card className="space-y-2 border-primary p-5">
                        <h2 className="font-semibold">Your new token</h2>
                        <p className="text-sm text-muted">Copy it now and keep it somewhere safe: for your security it won’t be shown again.</p>
                        <code className="block rounded-md bg-bg p-3 text-sm break-all">{newToken.plain}</code>
                        <Button
                            size="sm"
                            variant="secondary"
                            onClick={() => void navigator.clipboard.writeText(newToken.plain).then(() => setCopied(true))}
                        >
                            <Copy /> {copied ? 'Copied' : 'Copy token'}
                        </Button>
                    </Card>
                )}
                <Card className="space-y-2 p-5 text-sm">
                    <p>
                        Use the API to send and track referrals from your own systems. Read the{' '}
                        <a href="/developers" className="font-medium text-primary">
                            API documentation
                        </a>
                        . Each token can make {limit} requests a minute.
                    </p>
                    <p className="text-muted">
                        <strong>Test tokens</strong> work in a private sandbox network with sample teams: nothing reaches a real member or client.{' '}
                        <strong>Live tokens</strong> act as you, on your team’s real referrals.
                    </p>
                </Card>
                {canManage && (
                    <Card className="space-y-4 p-5">
                        <h2 className="font-semibold">Create a token</h2>
                        <div className="grid gap-4 sm:grid-cols-[2fr_1fr_1fr]">
                            <Field label="Name" hint="Where it will be used, e.g. “Our CRM”." error={f.errors.name}>
                                <Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} maxLength={80} />
                            </Field>
                            <fieldset>
                                <legend className="mb-1.5 text-sm font-medium">Mode</legend>
                                <div className="flex gap-3 text-sm">
                                    {(['test', 'live'] as const).map((m) => (
                                        <label key={m} className="flex items-center gap-1.5">
                                            <input type="radio" name="mode" checked={f.data.mode === m} onChange={() => f.setData('mode', m)} />{' '}
                                            {m === 'test' ? 'Test' : 'Live'}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            <Field label="Expires (optional)" error={f.errors.expires_at}>
                                <Input type="date" value={f.data.expires_at} onChange={(e) => f.setData('expires_at', e.target.value)} />
                            </Field>
                        </div>
                        <fieldset>
                            <legend className="mb-1.5 text-sm font-medium">Permissions</legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {Object.entries(scopes).map(([k, label]) => (
                                    <label key={k} className="flex items-start gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            className="mt-1 size-4"
                                            checked={f.data.scopes.includes(k)}
                                            onChange={() => toggle(k)}
                                        />
                                        <span>
                                            <code className="text-xs">{k}</code>
                                            <span className="block text-muted">{label}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {f.errors.scopes && <p className="mt-1 text-sm text-danger">{f.errors.scopes}</p>}
                        </fieldset>
                        <Button
                            loading={f.processing}
                            onClick={() => f.post('/app/developers/tokens', { preserveScroll: true, onSuccess: () => f.reset('name', 'expires_at') })}
                        >
                            <KeyRound /> Create token
                        </Button>
                    </Card>
                )}
                <Card className="space-y-3 p-5">
                    <h2 className="font-semibold">Your team’s tokens</h2>
                    {tokens.length === 0 && <p className="text-sm text-muted">None yet.</p>}
                    <ul className="divide-y divide-line">
                        {tokens.map((t) => (
                            <li key={t.id} className="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {t.name} <Badge tone={t.mode === 'live' ? 'success' : 'info'}>{t.mode}</Badge>{' '}
                                        {t.state === 'expired' && <Badge tone="warning">expired</Badge>}
                                        {t.state === 'owner_left' && <Badge tone="warning">stopped: its creator left the team</Badge>}
                                    </p>
                                    <p className="text-muted">
                                        <code>{t.prefix}…</code> · created by {t.createdBy} ·{' '}
                                        {t.lastUsed ? `last used ${new Date(t.lastUsed).toLocaleString()}` : 'never used'}
                                        {t.expires ? ` · expires ${t.expires}` : ''}
                                    </p>
                                    <p className="text-xs break-words text-muted">{t.scopes.join(', ')}</p>
                                </div>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => {
                                            if (window.confirm(`Revoke “${t.name}”? Anything using it stops working straight away.`)) {
                                                router.delete(`/app/developers/tokens/${t.id}`, { preserveScroll: true });
                                            }
                                        }}
                                    >
                                        Revoke
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Card>
            </div>
        </PortalLayout>
    );
}
