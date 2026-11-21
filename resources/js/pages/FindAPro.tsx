import { useForm } from '@inertiajs/react';
import { BadgeCheck, MapPinned, MessageSquareText, Search, ShieldCheck, Star } from 'lucide-react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { FeatureCard, PageHero, Section } from '@/components/site/Site';
import { Button } from '@/components/ui/button';
import { Field, Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

const steps = [
    { icon: Search, title: 'Tell us what you need', text: 'Pick the service and your city.' },
    { icon: ShieldCheck, title: 'Confirm it’s you', text: 'A quick code to your phone keeps requests real.' },
    {
        icon: BadgeCheck,
        title: 'One verified pro',
        text: 'Your request goes to the one verified pro for your city, never sold to several. If they can’t help in time, their backup gets it.',
    },
    { icon: Star, title: 'Follow and rate', text: 'Track your request from your phone, and rate the pro when the job is done.' },
];

/** "Find a trusted pro": pick what you need and your city, then make your request on that city's page. */
export default function FindAPro({ answer, industries, seo }: { answer: string; industries: { slug: string; name: string }[]; seo: Seo }) {
    const form = useForm({ industry: '', city_id: 0 });
    const errors = form.errors as Record<string, string>;
    return (
        <PublicLayout current="/find-a-pro">
            <SeoHead seo={seo} />
            <PageHero
                eyebrow="For homeowners and clients"
                title="Find a trusted pro"
                lead="Tell us what you need and where. Your request goes to the one verified pro for your city: licensed where required, held to response deadlines, and never sold to several."
            >
                <form
                    className="grid gap-4 rounded-2xl border border-line bg-surface p-5 shadow-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/find-a-pro');
                    }}
                >
                    <Field label="What do you need?" error={errors.industry}>
                        <Select value={form.data.industry} onChange={(e) => form.setData('industry', e.target.value)} required>
                            <option value="">Choose a service…</option>
                            {industries.map((i) => (
                                <option key={i.slug} value={i.slug}>
                                    {i.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Your city" error={errors.city_id}>
                        <CityPicker onPick={(c) => form.setData('city_id', c.id)} />
                    </Field>
                    <Button type="submit" disabled={!form.data.industry || !form.data.city_id} loading={form.processing}>
                        <Search /> Find my pro
                    </Button>
                </form>
            </PageHero>

            <Section title="How it works" id="how">
                <ol className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {steps.map((s, i) => (
                        <li key={s.title}>
                            <FeatureCard icon={s.icon} title={s.title} step={i + 1}>
                                {s.text}
                            </FeatureCard>
                        </li>
                    ))}
                </ol>
            </Section>

            {/* The question answer engines ask, shown with its answer (it is also the page's FAQ structured data). */}
            <Section tinted narrow>
                <div className="flex gap-4">
                    <span className="hidden sm:block">
                        <MessageSquareText className="size-8 text-primary" aria-hidden />
                    </span>
                    <div>
                        <h2 className="font-display text-2xl font-bold">How do I find a trusted pro near me?</h2>
                        <p className="mt-2 text-lg text-muted">{answer}</p>
                        <p className="mt-4 inline-flex items-center gap-2 text-sm text-muted">
                            <MapPinned className="size-4" aria-hidden /> Available across the US, one verified pro per city.
                        </p>
                    </div>
                </div>
            </Section>
        </PublicLayout>
    );
}
