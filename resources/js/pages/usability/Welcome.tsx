import { router } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = { name: string; industry: string | null; tasks: { title: string; task: string }[]; finished: boolean; beginUrl: string; seo: Seo };

/** A tester's welcome page, from the emailed link: what the test involves and what's recorded, then the button to begin. */
export default function UsabilityWelcome({ name, industry, tasks, finished, beginUrl, seo }: Props) {
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <article className="mx-auto max-w-2xl px-4 py-10">
                <h1 className="font-display text-3xl font-bold">Thanks for testing, {name}</h1>
                <p className="mt-4 text-lg">
                    You’ll do {tasks.length} short tasks{industry ? ` written for ${industry}` : ''}, the way you would at work. It takes about 20
                    minutes. If something is confusing, that’s the product’s fault, not yours, and it’s exactly what we need to find.
                </p>
                <section aria-labelledby="how" className="mt-8">
                    <h2 id="how" className="font-display text-xl font-bold">
                        How it works
                    </h2>
                    <ul className="mt-3 list-disc space-y-1 pl-5">
                        <li>A box in the bottom corner shows the task you’re on. Press “I’ve done it” when you’re done.</li>
                        <li>If you can’t see how to carry on, press “I’m stuck”, or “Give up” to move to the next task.</li>
                        <li>Please think aloud if someone is with you; otherwise just work as you normally would.</li>
                    </ul>
                </section>
                <section aria-labelledby="recorded" className="mt-8">
                    <h2 id="recorded" className="font-display text-xl font-bold">
                        What we record
                    </h2>
                    <p className="mt-3">
                        The pages you visit and how long you spend on each, buttons you click repeatedly without anything happening, the names of form
                        fields that show an error, and any note you write. We never record what you type into forms. It stops when you finish or press
                        “Stop the test”.
                    </p>
                </section>
                <section aria-labelledby="tasks" className="mt-8">
                    <h2 id="tasks" className="font-display text-xl font-bold">
                        Your tasks
                    </h2>
                    <ol className="mt-3 list-decimal space-y-2 pl-5">
                        {tasks.map((t) => (
                            <li key={t.title}>
                                <span className="font-semibold">{t.title}.</span> {t.task}
                            </li>
                        ))}
                    </ol>
                </section>
                <div className="mt-8">
                    {finished ? (
                        <p className="font-semibold">You’ve already finished this test. Thank you!</p>
                    ) : (
                        <Button size="lg" onClick={() => router.post(beginUrl)}>
                            I agree, start the test
                        </Button>
                    )}
                </div>
            </article>
        </PublicLayout>
    );
}
