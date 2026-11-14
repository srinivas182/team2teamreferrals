import { useForm, usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Field, Input } from '@/components/ui/field';
import { CmsBlock, type Block } from '@/components/cms/Blocks';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    page: { title: string; summary: string; template: string; updated: string; blocks: Block[] };
    preview: { version: number } | null;
    contactToken?: string | null;
    seo: Seo;
};

const TOPICS: Record<string, string> = {
    general: 'General question',
    joining: 'Joining a network',
    billing: 'Billing',
    press: 'Press',
    partnership: 'Partnerships',
};

/** The Contact form: a hidden field and a start time keep bots out. */
function ContactForm({ b, token }: { b: Block; token: string }) {
    const f = useForm({ name: '', email: '', topic: 'general', message: '', started: token, website_url: '' });
    const { status } = usePage<{ status?: string }>().props;
    if (status === 'contact-sent') {
        return <Alert tone="success">Thanks, your message is on its way. We answer within one business day.</Alert>;
    }
    return (
        <section className="max-w-2xl space-y-3 py-6">
            {typeof b.heading === 'string' && b.heading && <h2 className="font-display text-2xl font-bold">{b.heading}</h2>}
            {typeof b.intro === 'string' && b.intro && <p className="text-muted">{b.intro}</p>}
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Your name" error={f.errors.name}>
                    <Input value={f.data.name} onChange={(e) => f.setData('name', e.target.value)} autoComplete="name" />
                </Field>
                <Field label="Email" error={f.errors.email}>
                    <Input type="email" value={f.data.email} onChange={(e) => f.setData('email', e.target.value)} autoComplete="email" />
                </Field>
            </div>
            <Field label="Topic">
                <select
                    className="h-10 w-full rounded-md border border-line bg-surface px-2"
                    value={f.data.topic}
                    onChange={(e) => f.setData('topic', e.target.value)}
                >
                    {Object.entries(TOPICS).map(([k, l]) => (
                        <option key={k} value={k}>
                            {l}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Message" error={f.errors.message}>
                <textarea
                    rows={6}
                    className="w-full rounded-md border border-line bg-surface p-2"
                    value={f.data.message}
                    onChange={(e) => f.setData('message', e.target.value)}
                />
            </Field>
            <div aria-hidden="true" className="absolute -left-[9999px]">
                <label>
                    Leave this empty{' '}
                    <input tabIndex={-1} autoComplete="off" value={f.data.website_url} onChange={(e) => f.setData('website_url', e.target.value)} />
                </label>
            </div>
            <Button loading={f.processing} onClick={() => f.post('/contact', { preserveScroll: true })}>
                Send message
            </Button>
        </section>
    );
}

/** A website page built in Admin → Website: the answer first, then its blocks. */
export default function CmsPage({ page, preview, contactToken, seo }: Props) {
    return (
        <PublicLayout current="">
            <SeoHead seo={seo} />
            {preview && (
                <div className="bg-warning/20 px-4 py-2 text-center text-sm font-medium">
                    Preview of version {preview.version}: not public, not indexed.
                </div>
            )}
            <article className="mx-auto max-w-5xl px-4 py-10">
                <header className="max-w-3xl">
                    <h1 className="font-display text-4xl font-bold">{page.title}</h1>
                    {page.summary && <p className="mt-4 text-lg">{page.summary}</p>}
                    {/* The date matches the page's structured data (dateModified), so readers and answer engines see the same. */}
                    <p className="mt-2 text-sm text-muted">
                        {page.template === 'legal' ? 'Effective' : 'Updated'} <time dateTime={page.updated}>{formatDay(page.updated)}</time>
                    </p>
                </header>
                {page.blocks.map((b, i) =>
                    b.type === 'contact_form' ? contactToken ? <ContactForm key={i} b={b} token={contactToken} /> : null : <CmsBlock key={i} b={b} />,
                )}
            </article>
        </PublicLayout>
    );
}

/** "2027-01-15" → "Jan 15, 2027", the same on the server and in the browser. */
function formatDay(day: string): string {
    const d = new Date(`${day}T00:00:00Z`);
    return Number.isNaN(d.getTime()) ? day : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
}
