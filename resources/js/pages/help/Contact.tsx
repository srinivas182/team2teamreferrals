import { Link, useForm } from '@inertiajs/react';
import { Honeypot, useStartToken } from '@/components/partners/FormExtras';
import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
import { Button } from '@/components/ui/button';
import { Field, Input, Select, Textarea } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    me: { name: string; email: string } | null;
    categories: { key: string; label: string }[];
    category: string;
    subject: string;
    suggestions: { title: string; href: string }[];
    seo: Seo;
};

/** Contact support: a short form; attachments optional. Members are already known; everyone else gives a name and email. */
export default function SupportContact({ me, categories, category, subject, suggestions, seo }: Props) {
    const f = useForm({
        name: me?.name ?? '',
        email: me?.email ?? '',
        category: category || 'other',
        subject,
        body: '',
        files: [] as File[],
        website_url: '',
        started: '',
    });
    useStartToken((t) => f.setData('started', t));
    const e = f.errors as Record<string, string | undefined>;
    return (
        <PublicLayout current="/help">
            <SeoHead seo={seo} />
            <PageHero
                narrow
                eyebrow={
                    <Link href="/help" className="normal-case hover:underline">
                        Help centre
                    </Link>
                }
                title="Contact support"
                lead="A person reads every message and replies, usually within a business day."
            />
            <div className="mx-auto max-w-3xl px-5 py-10 sm:px-8">
                {suggestions.length > 0 && (
                    <aside className="mt-4 rounded-lg border border-line bg-surface p-4 text-sm">
                        <p className="font-medium">These might already answer it:</p>
                        <ul className="mt-1 list-disc pl-5">
                            {suggestions.map((s) => (
                                <li key={s.href}>
                                    <Link href={s.href} className="text-primary">
                                        {s.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </aside>
                )}
                <form
                    className="mt-6 space-y-4"
                    onSubmit={(ev) => {
                        ev.preventDefault();
                        f.post('/support', { forceFormData: true, preserveScroll: true });
                    }}
                >
                    {me ? (
                        <p className="text-sm text-muted">
                            Sending as {me.name} ({me.email}).
                        </p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Your name" error={e.name}>
                                <Input value={f.data.name} onChange={(ev) => f.setData('name', ev.target.value)} autoComplete="name" required />
                            </Field>
                            <Field label="Email" hint="We reply here." error={e.email}>
                                <Input
                                    type="email"
                                    value={f.data.email}
                                    onChange={(ev) => f.setData('email', ev.target.value)}
                                    autoComplete="email"
                                    required
                                />
                            </Field>
                        </div>
                    )}
                    <Field label="What’s it about?" error={e.category}>
                        <Select value={f.data.category} onChange={(ev) => f.setData('category', ev.target.value)}>
                            {categories.map((c) => (
                                <option key={c.key} value={c.key}>
                                    {c.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Subject" error={e.subject}>
                        <Input value={f.data.subject} onChange={(ev) => f.setData('subject', ev.target.value)} required />
                    </Field>
                    <Field label="Message" hint="What happened, and what you expected. Include any referral or invoice number." error={e.body}>
                        <Textarea rows={6} value={f.data.body} onChange={(ev) => f.setData('body', ev.target.value)} required />
                    </Field>
                    <Field label="Screenshots or files (optional)" hint="Up to 3 images or PDFs, 5 MB each." error={e.files ?? e['files.0']}>
                        <Input
                            type="file"
                            multiple
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="py-2"
                            onChange={(ev) => f.setData('files', Array.from(ev.target.files ?? []).slice(0, 3))}
                        />
                    </Field>
                    {!me && <Honeypot value={f.data.website_url} onChange={(v) => f.setData('website_url', v)} />}
                    <Button type="submit" loading={f.processing}>
                        Send to support
                    </Button>
                </form>
            </div>
        </PublicLayout>
    );
}
