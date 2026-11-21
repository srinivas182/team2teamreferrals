import { SeoHead } from '@/components/seo/SeoHead';
import { PageHero } from '@/components/site/Site';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = { answer: string; reviewed: string; email: string; faq: { q: string; a: string }[]; seo: Seo };

/** The accessibility statement: the standard, how it's checked, known limits and how to report a problem. */
export default function Accessibility({ answer, reviewed, email, faq, seo }: Props) {
    return (
        <PublicLayout current="/accessibility">
            <SeoHead seo={seo} />
            <article>
                <PageHero
                    narrow
                    eyebrow="Accessibility"
                    title="Accessibility statement"
                    lead={answer}
                    meta={
                        <>
                            Last reviewed <time dateTime={reviewed}>{reviewed}</time>
                        </>
                    }
                />
                <div className="mx-auto max-w-4xl px-5 py-10 sm:px-8">
                    <section aria-labelledby="checked" className="mt-10">
                        <h2 id="checked" className="font-display text-2xl font-bold">
                            How we check it
                        </h2>
                        <ul className="mt-3 list-disc space-y-2 pl-5">
                            <li>
                                Automated WCAG 2.1 A and AA checks (axe-core) on every key screen, in light mode, dark mode and on a phone, before any
                                change goes live.
                            </li>
                            <li>
                                Keyboard-only checks of the core journeys: the skip link, visible focus on everything you can tab to, signing in, and
                                dialogs that keep focus and close with Escape.
                            </li>
                            <li>Accessibility checks on every shared component, and contrast checked against our colour tokens.</li>
                            <li>Pages respect your system’s reduced-motion and dark-mode settings, and work when zoomed to 200%.</li>
                        </ul>
                    </section>
                    <section aria-labelledby="limits" className="mt-10">
                        <h2 id="limits" className="font-display text-2xl font-bold">
                            What isn’t perfect yet
                        </h2>
                        <ul className="mt-3 list-disc space-y-2 pl-5">
                            <li>
                                The network map is a picture of where seats are; the same information is always available as a list just below it.
                            </li>
                            <li>Videos have captions and a transcript; they don’t yet have audio description.</li>
                            <li>
                                Documents you download, such as invoices and agreements, are PDFs that may not be fully tagged for screen readers. Ask
                                us for another format.
                            </li>
                        </ul>
                    </section>
                    <section aria-labelledby="report" className="mt-10">
                        <h2 id="report" className="font-display text-2xl font-bold">
                            Tell us about a problem
                        </h2>
                        <p className="mt-3">
                            Email{' '}
                            <a href={`mailto:${email}`} className="text-primary underline underline-offset-2">
                                {email}
                            </a>{' '}
                            with the page and what happened. A person replies within one business day.
                        </p>
                    </section>
                    <section aria-labelledby="faq" className="mt-10">
                        <h2 id="faq" className="font-display text-2xl font-bold">
                            Questions
                        </h2>
                        <dl className="mt-3 space-y-4">
                            {faq.map((f) => (
                                <div key={f.q}>
                                    <dt className="font-semibold">{f.q}</dt>
                                    <dd className="mt-1">{f.a}</dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </div>
            </article>
        </PublicLayout>
    );
}
