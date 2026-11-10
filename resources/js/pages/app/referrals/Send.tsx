import { Link, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, ShieldCheck, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CityPicker, type CityOption } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Answer = string | string[] | boolean;
type FormField = { key: string; label: string; type: string; required?: boolean; options?: string[] };
type G = {
    slug: string;
    name: string;
    industry: string;
    terms: Record<string, string>;
    form: FormField[];
    consent: string;
    blocked: boolean;
    trainingUrl: string;
};
type Preview = { territory: string; team: string | null; role: string | null; backup: string | null; avgAcceptMinutes: number | null };

function Input2({ f, value, onChange }: { f: FormField; value: Answer | undefined; onChange: (v: Answer) => void }) {
    if (f.type === 'textarea') return <Textarea rows={3} value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)} />;
    if (f.type === 'choice')
        return (
            <Select value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">Choose…</option>
                {(f.options ?? []).map((o) => (
                    <option key={o}>{o}</option>
                ))}
            </Select>
        );
    if (f.type === 'multi_choice')
        return (
            <div className="flex flex-wrap gap-3">
                {(f.options ?? []).map((o) => {
                    const arr = (value as string[]) ?? [];
                    return (
                        <label key={o} className="flex items-center gap-1.5 text-sm">
                            <input
                                type="checkbox"
                                checked={arr.includes(o)}
                                onChange={(e) => onChange(e.target.checked ? [...arr, o] : arr.filter((x) => x !== o))}
                            />{' '}
                            {o}
                        </label>
                    );
                })}
            </div>
        );
    if (f.type === 'boolean')
        return (
            <Select value={value === undefined ? '' : value ? 'yes' : 'no'} onChange={(e) => onChange(e.target.value === 'yes')}>
                <option value="">Choose…</option>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </Select>
        );
    return (
        <Input
            type={f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}
            value={(value as string) ?? ''}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

export default function Send({ groups, refresher, seo }: { groups: G[]; refresher: string | null; seo: Seo }) {
    const { auth, flash } = usePage<SharedProps & { flash: { duplicate?: boolean } }>().props;
    const form = useForm({
        group: groups[0]?.slug ?? '',
        city_id: 0,
        answers: {} as Record<string, Answer>,
        consent: false,
        notes: '',
        confirm_duplicate: false,
    });
    const [preview, setPreview] = useState<Preview | null>(null);
    const g = groups.find((x) => x.slug === form.data.group);
    const e = form.errors as Record<string, string>;
    useEffect(() => {
        if (!form.data.city_id || !form.data.group) return;
        fetch(`/app/send/preview?group=${form.data.group}&city_id=${form.data.city_id}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then(setPreview)
            .catch(() => setPreview(null));
    }, [form.data.city_id, form.data.group]);
    if (groups.length === 0) {
        return (
            <PortalLayout title="Send a referral" user={{ name: auth.user?.name ?? '', team: '' }}>
                <Card className="max-w-xl p-6 text-muted">
                    Join a network first; you can send referrals once you’re approved.{' '}
                    <Link href="/app/groups" className="font-medium text-primary">
                        Find your network
                    </Link>
                </Card>
            </PortalLayout>
        );
    }
    const submit = (confirm = false) => {
        form.transform((d) => ({ ...d, confirm_duplicate: confirm }));
        form.post('/app/send', { preserveScroll: true });
    };
    return (
        <PortalLayout title="Send a referral" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {refresher && (
                <Alert tone="info" className="mb-4 max-w-3xl">
                    First referral? Take the{' '}
                    <Link href={`/app/training/${refresher}/refresher`} className="font-medium underline">
                        one-minute refresher
                    </Link>{' '}
                    on consent, deadlines and fees.
                </Alert>
            )}
            <div className="grid max-w-5xl gap-6 lg:grid-cols-[1.4fr_1fr]">
                <Card className="space-y-4 p-6">
                    <Field label="Network" error={e.group}>
                        <Select
                            value={form.data.group}
                            onChange={(ev) => {
                                setPreview(null);
                                form.setData({ ...form.data, group: ev.target.value, answers: {} });
                            }}
                        >
                            {groups.map((x) => (
                                <option key={x.slug} value={x.slug}>
                                    {x.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {g?.blocked && (
                        <Alert tone="danger">
                            Finish your network training to send referrals here.{' '}
                            <Link href={g.trainingUrl} className="font-medium underline">
                                Start training
                            </Link>
                        </Alert>
                    )}
                    <Field label={`Where does your ${g?.terms.client ?? 'client'} need help?`} error={e.city_id}>
                        <CityPicker
                            onPick={(c: CityOption) => {
                                setPreview(null);
                                form.setData('city_id', c.id);
                            }}
                        />
                    </Field>
                    {g?.form.map((f) => (
                        <Field key={f.key} label={`${f.label}${f.required ? '' : ' (optional)'}`} error={e[`answers.${f.key}`]}>
                            <Input2
                                f={f}
                                value={form.data.answers[f.key]}
                                onChange={(v) => form.setData('answers', { ...form.data.answers, [f.key]: v })}
                            />
                        </Field>
                    ))}
                    <Field label="Note for the receiving pro (optional)" error={e.notes}>
                        <Textarea rows={2} value={form.data.notes} onChange={(ev) => form.setData('notes', ev.target.value)} />
                    </Field>
                    <label className="flex items-start gap-2 rounded-md bg-bg p-3 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={form.data.consent}
                            onChange={(ev) => form.setData('consent', ev.target.checked)}
                        />
                        <span>
                            <strong>My {g?.terms.client ?? 'client'} agreed.</strong> {g?.consent}
                        </span>
                    </label>
                    {e.consent && <p className="text-sm text-danger">{e.consent}</p>}
                    {flash.duplicate ? (
                        <Alert tone="info">
                            <AlertTriangle className="mr-1 inline size-4" aria-hidden /> This {g?.terms.client ?? 'client'} was referred in this
                            network in the last 90 days. Send anyway?
                            <div className="mt-2">
                                <Button size="sm" loading={form.processing} onClick={() => submit(true)}>
                                    Yes, send it
                                </Button>
                            </div>
                        </Alert>
                    ) : (
                        <Button
                            size="lg"
                            disabled={!form.data.city_id || !form.data.consent || g?.blocked}
                            loading={form.processing}
                            onClick={() => submit(false)}
                        >
                            Send referral <ArrowRight />
                        </Button>
                    )}
                </Card>
                <Card className="h-fit space-y-3 p-6">
                    <h2 className="font-semibold">Who will receive it</h2>
                    {!preview ? (
                        <p className="text-sm text-muted">Pick a city to see the verified pro there.</p>
                    ) : preview.team ? (
                        <>
                            <p className="text-lg font-semibold">{preview.team}</p>
                            <p className="text-sm text-muted">
                                {preview.territory} ·{' '}
                                {preview.role === 'backup' ? 'backup pro (the holder isn’t taking referrals right now)' : 'seat holder'}
                            </p>
                            {preview.avgAcceptMinutes !== null && (
                                <p className="flex items-center gap-2 text-sm">
                                    <Timer className="size-4 text-primary" aria-hidden /> Accepts in about {preview.avgAcceptMinutes} min on average
                                </p>
                            )}
                            {preview.backup && <p className="text-sm text-muted">Backup: {preview.backup}</p>}
                            <p className="flex items-center gap-2 text-sm text-muted">
                                <ShieldCheck className="size-4 text-success" aria-hidden /> Verified and network trained
                            </p>
                        </>
                    ) : (
                        <p className="text-sm text-muted">No verified pro can take referrals in {preview.territory} yet.</p>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
