import { useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { CityPicker } from '@/components/CityPicker';
import { SeoHead } from '@/components/seo/SeoHead';
import { Button } from '@/components/ui/button';
import { Field, Select } from '@/components/ui/field';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

/** "Find a trusted pro": pick what you need and your city, then make your request on that city's page. */
export default function FindAPro({ answer, industries, seo }: { answer: string; industries: { slug: string; name: string }[]; seo: Seo }) {
    const form = useForm({ industry: '', city_id: 0 });
    const errors = form.errors as Record<string, string>;
    return (
        <PublicLayout current="/find-a-pro">
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-3xl px-4 py-12">
                <h1 className="font-display text-4xl font-bold">Find a trusted pro</h1>
                <p className="mt-4 text-lg">{answer}</p>
                <form
                    className="mt-8 grid gap-4 rounded-lg border border-line bg-surface p-5 sm:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/find-a-pro');
                    }}
                >
                    <Field label="What do you need?" error={errors.industry}>
                        <Select value={form.data.industry} onChange={(e) => form.setData('industry', e.target.value)} required>
                            <option value="">Choose…</option>
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
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={!form.data.industry || !form.data.city_id} loading={form.processing}>
                            <Search /> Find my pro
                        </Button>
                    </div>
                </form>
                <h2 className="mt-10 font-display text-2xl font-bold">How it works</h2>
                <ol className="mt-3 list-decimal space-y-1 pl-6">
                    <li>Tell us what you need and where.</li>
                    <li>Confirm your phone with a text code.</li>
                    <li>
                        Your request goes to the one verified pro for your city, never sold to several. If they can’t help in time, their backup gets
                        it.
                    </li>
                    <li>Follow your request from your phone, and rate the pro when the job is done.</li>
                </ol>
            </section>
        </PublicLayout>
    );
}
