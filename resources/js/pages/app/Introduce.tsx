import { router, useForm, usePage } from '@inertiajs/react';
import { Handshake } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type FormField = { key: string; label: string; type: string; required?: boolean; options?: string[] };
type Suggestion = { slug: string; name: string; industry: string; team: string | null; fee: boolean; explanation: string; form: FormField[] };
type Props = {
    from: { slug: string; name: string } | null;
    mine: { slug: string; name: string }[];
    city: { id: number; name: string } | null;
    client: { name: string; phone: string | null; email: string | null } | null;
    suggestions: Suggestion[];
    seo: Seo;
};
type AnswerValue = string | string[] | boolean;
type Intro = { group: string; answers: Record<string, AnswerValue>; consent: boolean };

function Answer({ f, value, onChange }: { f: FormField; value: AnswerValue | undefined; onChange: (v: AnswerValue) => void }) {
    const label = `${f.label}${f.required ? '' : ' (optional)'}`;
    if (f.type === 'choice')
        return (
            <Field label={label}>
                <select
                    className="h-10 w-full rounded-md border border-line bg-surface px-2"
                    value={String(value ?? '')}
                    onChange={(e) => onChange(e.target.value)}
                >
                    <option value="">Choose…</option>
                    {(f.options ?? []).map((o) => (
                        <option key={o}>{o}</option>
                    ))}
                </select>
            </Field>
        );
    if (f.type === 'multi_choice')
        return (
            <Field label={label}>
                <div className="flex flex-wrap gap-1.5">
                    {(f.options ?? []).map((o) => {
                        const cur = Array.isArray(value) ? (value as string[]) : [];
                        return (
                            <button
                                key={o}
                                type="button"
                                aria-pressed={cur.includes(o)}
                                className={`h-8 rounded-full border px-3 text-xs ${cur.includes(o) ? 'border-primary bg-primary/10 text-primary' : 'border-line'}`}
                                onClick={() => onChange(cur.includes(o) ? cur.filter((c) => c !== o) : [...cur, o])}
                            >
                                {o}
                            </button>
                        );
                    })}
                </div>
            </Field>
        );
    if (f.type === 'boolean')
        return (
            <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={Boolean(value)} onChange={(e) => onChange(e.target.checked)} /> {f.label}
            </label>
        );
    return (
        <Field label={label}>
            <Input
                type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
            />
        </Field>
    );
}

/** Introduce a client to other industries in the same city: each introduction is a tracked referral. */
export default function Introduce({ from, mine, city, client, suggestions, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const f = useForm<{
        from: string;
        city_id: number;
        client_name: string;
        client_phone: string;
        client_email: string;
        notes: string;
        intros: Intro[];
    }>({
        from: from?.slug ?? '',
        city_id: city?.id ?? 0,
        client_name: client?.name ?? '',
        client_phone: client?.phone ?? '',
        client_email: client?.email ?? '',
        notes: '',
        intros: [],
    });
    const chosen = (slug: string) => f.data.intros.find((i) => i.group === slug);
    const toggle = (slug: string) =>
        f.setData(
            'intros',
            chosen(slug) ? f.data.intros.filter((i) => i.group !== slug) : [...f.data.intros, { group: slug, answers: {}, consent: false }],
        );
    const patch = (slug: string, change: Partial<Intro>) =>
        f.setData(
            'intros',
            f.data.intros.map((i) => (i.group === slug ? { ...i, ...change } : i)),
        );
    const errors = f.errors as Record<string, string>;
    return (
        <PortalLayout title="Introduce a client" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="mx-auto max-w-3xl space-y-4">
                <Card className="space-y-2 p-5">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Handshake className="size-5 text-primary" aria-hidden /> Connect your client with the other pros they need
                    </h2>
                    {mine.length > 1 && (
                        <select
                            aria-label="From your network"
                            className="h-10 rounded-md border border-line bg-surface px-2"
                            value={f.data.from}
                            onChange={(e) => router.get('/app/introduce', { from: e.target.value, city: city?.id })}
                        >
                            {mine.map((g) => (
                                <option key={g.slug} value={g.slug}>
                                    From {g.name}
                                </option>
                            ))}
                        </select>
                    )}
                    {!city && (
                        <p className="text-sm text-muted">
                            Open this from a referral to introduce that client, or choose a city from a referral page.
                        </p>
                    )}
                    {city && <p className="text-sm text-muted">Pros in {city.name}</p>}
                </Card>
                {city && (
                    <>
                        <Card className="grid gap-3 p-5 sm:grid-cols-3">
                            <Field label="Client’s name" error={errors.client_name}>
                                <Input value={f.data.client_name} onChange={(e) => f.setData('client_name', e.target.value)} />
                            </Field>
                            <Field label="Phone" error={errors.client_phone}>
                                <Input type="tel" value={f.data.client_phone} onChange={(e) => f.setData('client_phone', e.target.value)} />
                            </Field>
                            <Field label="or email" error={errors.client_email}>
                                <Input type="email" value={f.data.client_email} onChange={(e) => f.setData('client_email', e.target.value)} />
                            </Field>
                        </Card>
                        {suggestions.length === 0 && (
                            <Card className="p-5 text-sm text-muted">No other networks are suggested for this city yet.</Card>
                        )}
                        {suggestions.map((s) => {
                            const intro = chosen(s.slug);
                            return (
                                <Card key={s.slug} className="space-y-3 p-5">
                                    <label className="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            className="mt-1"
                                            checked={Boolean(intro)}
                                            disabled={!s.team}
                                            onChange={() => toggle(s.slug)}
                                        />
                                        <span className="flex-1">
                                            <span className="font-semibold">{s.name}</span>{' '}
                                            <span className="text-sm text-muted">· {s.team ?? 'No one holds this city yet'}</span>
                                            <span className="mt-1 block">
                                                <Badge tone={s.fee ? 'info' : 'success'}>{s.fee ? 'Fee can apply' : 'Credited introduction'}</Badge>{' '}
                                                <span className="text-xs text-muted">{s.explanation}</span>
                                            </span>
                                        </span>
                                    </label>
                                    {intro && (
                                        <div className="space-y-3 border-t border-line pt-3">
                                            {s.form.map((fld) => (
                                                <Answer
                                                    key={fld.key}
                                                    f={fld}
                                                    value={intro.answers[fld.key]}
                                                    onChange={(v) => patch(s.slug, { answers: { ...intro.answers, [fld.key]: v } })}
                                                />
                                            ))}
                                            <label className="flex items-start gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={intro.consent}
                                                    onChange={(e) => patch(s.slug, { consent: e.target.checked })}
                                                />
                                                {f.data.client_name || 'My client'} agreed to be introduced to {s.team}.
                                            </label>
                                            {Object.entries(errors)
                                                .filter(([k]) => k.startsWith(`intros.${f.data.intros.indexOf(intro)}.`))
                                                .map(([k, m]) => (
                                                    <p key={k} className="text-sm text-danger">
                                                        {m}
                                                    </p>
                                                ))}
                                        </div>
                                    )}
                                </Card>
                            );
                        })}
                        {errors.intros && <p className="text-sm text-danger">{errors.intros}</p>}
                        <Button disabled={f.data.intros.length === 0} loading={f.processing} onClick={() => f.post('/app/introduce')}>
                            Send {f.data.intros.length > 1 ? `${f.data.intros.length} introductions` : 'introduction'}
                        </Button>
                    </>
                )}
            </div>
        </PortalLayout>
    );
}
