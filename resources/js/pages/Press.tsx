import { Download } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    answer: string;
    boilerplate: string;
    facts: string[];
    figures: { industries: number; networks: number; seats: number; states: number };
    contact: { email: string; company: string; address: string | null; phone: string | null };
    logos: { name: string; svg: string | null; png: string | null; dark: boolean }[];
    screens: { src: string; caption: string; name: string }[];
    video: {
        src: string;
        poster: string;
        captions: string;
        seconds: number;
        social: string | null;
        transcript: { at: number; text: string }[];
    } | null;
    faq: { q: string; a: string }[];
    updated: string;
    seo: Seo;
};

const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

/** The press kit: logos, screenshots, the product tour, facts, the company boilerplate and who to contact. */
export default function Press({ answer, boilerplate, facts, figures, contact, logos, screens, video, faq, updated, seo }: Props) {
    return (
        <PublicLayout current="/press">
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-5xl px-4 py-10">
                <h1 className="font-display text-4xl font-bold">Press kit</h1>
                <p className="mt-4 max-w-3xl text-lg">{answer}</p>
                <p className="mt-2 text-sm text-muted">
                    Updated <time dateTime={updated}>{updated}</time>
                </p>

                <section aria-labelledby="figures" className="mt-10">
                    <h2 id="figures" className="font-display text-2xl font-bold">
                        In numbers
                    </h2>
                    <dl className="mt-4 grid gap-3 sm:grid-cols-4">
                        {(
                            [
                                ['Industries served', figures.industries],
                                ['Networks open', figures.networks],
                                ['Seats held by a verified team', figures.seats],
                                ['States with a held seat', figures.states],
                            ] as [string, number][]
                        ).map(([label, n]) => (
                            <div key={label} className="rounded-lg border border-line p-4">
                                <dt className="text-sm text-muted">{label}</dt>
                                <dd className="mt-1 font-display text-3xl font-bold">{n.toLocaleString('en-US')}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-2 text-xs text-muted">Counted live from the platform.</p>
                </section>

                <section aria-labelledby="about" className="mt-10 grid gap-8 md:grid-cols-[3fr_2fr]">
                    <div>
                        <h2 id="about" className="font-display text-2xl font-bold">
                            About us
                        </h2>
                        <p className="mt-3">{boilerplate}</p>
                        <h3 className="mt-6 font-semibold">Key facts</h3>
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            {facts.map((f) => (
                                <li key={f}>{f}</li>
                            ))}
                        </ul>
                    </div>
                    <div className="rounded-lg border border-line p-5" aria-labelledby="contact">
                        <h2 id="contact" className="font-display text-xl font-bold">
                            Press contact
                        </h2>
                        <p className="mt-3">
                            <a href={`mailto:${contact.email}`} className="font-semibold text-primary">
                                {contact.email}
                            </a>
                        </p>
                        {contact.phone && <p className="mt-1">{contact.phone}</p>}
                        <p className="mt-3 text-sm text-muted">{contact.company}</p>
                        {contact.address && <p className="text-sm text-muted">{contact.address}</p>}
                        <p className="mt-3 text-sm">We usually reply within one business day and can set up a demo account or an interview.</p>
                    </div>
                </section>

                {video && (
                    <section aria-labelledby="tour" className="mt-10">
                        <h2 id="tour" className="font-display text-2xl font-bold">
                            Product tour
                        </h2>
                        <p className="mt-2 text-muted">A one-minute tour, with captions. It uses sample data, not real members.</p>
                        <video
                            controls
                            preload="none"
                            poster={video.poster}
                            className="mt-4 aspect-video w-full rounded-lg border border-line bg-navy-900"
                            width={1280}
                            height={720}
                        >
                            <source src={video.src} type="video/mp4" />
                            <track kind="captions" src={video.captions} srcLang="en" label="English" default />
                        </video>
                        <p className="mt-2 text-sm">
                            <a href={video.src} download className="inline-flex items-center gap-1 text-primary">
                                <Download className="size-4" aria-hidden /> Download the video (MP4)
                            </a>
                            {video.social && (
                                <a href={video.social} download className="ml-4 inline-flex items-center gap-1 text-primary">
                                    <Download className="size-4" aria-hidden /> With captions burnt in, for social media
                                </a>
                            )}
                        </p>
                        <details className="mt-3">
                            <summary className="cursor-pointer font-semibold">Transcript</summary>
                            <ol className="mt-2 space-y-1 text-sm">
                                {video.transcript.map((t) => (
                                    <li key={t.at}>
                                        <span className="mr-2 text-muted tabular-nums">{clock(t.at)}</span>
                                        {t.text}
                                    </li>
                                ))}
                            </ol>
                        </details>
                    </section>
                )}

                {screens.length > 0 && (
                    <section aria-labelledby="screens" className="mt-10">
                        <h2 id="screens" className="font-display text-2xl font-bold">
                            Screenshots
                        </h2>
                        <p className="mt-2 text-muted">High resolution. Click one to download it. They use sample data.</p>
                        <ul className="mt-4 grid gap-6 sm:grid-cols-2">
                            {screens.map((s) => (
                                <li key={s.src}>
                                    <a
                                        href={s.src}
                                        download={`team-to-team-referrals-${s.name}.jpg`}
                                        className="block overflow-hidden rounded-lg border border-line"
                                    >
                                        <img
                                            src={s.src}
                                            alt={s.caption}
                                            loading="lazy"
                                            width={s.name === 'phone' ? 390 : 1280}
                                            height={s.name === 'phone' ? 844 : 800}
                                            className={s.name === 'phone' ? 'mx-auto h-auto max-h-[420px] w-auto' : 'h-auto w-full'}
                                        />
                                    </a>
                                    <p className="mt-2 text-sm">{s.caption}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {logos.length > 0 && (
                    <section aria-labelledby="logos" className="mt-10">
                        <div className="flex flex-wrap items-baseline gap-3">
                            <h2 id="logos" className="font-display text-2xl font-bold">
                                Logos
                            </h2>
                            <a href="/press/logos.zip" className="inline-flex items-center gap-1 text-primary">
                                <Download className="size-4" aria-hidden /> Download all (ZIP)
                            </a>
                        </div>
                        <p className="mt-2 text-muted">Please keep the colours and proportions as they are, and leave clear space around the logo.</p>
                        <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {logos.map((l) => (
                                <li key={l.name} className="rounded-lg border border-line">
                                    <div className={`flex h-32 items-center justify-center rounded-t-lg p-4 ${l.dark ? 'bg-navy-900' : 'bg-white'}`}>
                                        <img src={l.svg ?? l.png ?? ''} alt={l.name} loading="lazy" className="max-h-20 max-w-full" />
                                    </div>
                                    <div className="p-3 text-sm">
                                        <div className="font-medium">{l.name}</div>
                                        <div className="mt-1 flex gap-3">
                                            {l.svg && (
                                                <a href={l.svg} download className="text-primary">
                                                    SVG
                                                </a>
                                            )}
                                            {l.png && (
                                                <a href={l.png} download className="text-primary">
                                                    PNG
                                                </a>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="faq" className="mt-10">
                    <h2 id="faq" className="font-display text-2xl font-bold">
                        Questions from the press
                    </h2>
                    <dl className="mt-4 space-y-4">
                        {faq.map((f) => (
                            <div key={f.q}>
                                <dt className="font-semibold">{f.q}</dt>
                                <dd className="mt-1">{f.a}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            </article>
        </PublicLayout>
    );
}
