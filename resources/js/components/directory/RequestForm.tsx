import { useForm } from '@inertiajs/react';
import { Clock, Lock, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CityPicker } from '@/components/CityPicker';
import { article } from '@/components/directory/TeamCard';
import { AnswerInput, type Answer, type FormField } from '@/components/referrals/AnswerInput';
import { Button } from '@/components/ui/button';
import { Field, Input, Select } from '@/components/ui/field';

export type RequestBlock = { group: string; cityId: number | null; questions: FormField[]; pro: string | null; responseHours: number | null };

const TIMING: [string, string][] = [
    ['now', 'As soon as possible'],
    ['weeks', 'In the next few weeks'],
    ['months', 'In the next few months'],
    ['exploring', 'Just exploring'],
];
const CONTACT: [string, string][] = [
    ['call', 'Phone call'],
    ['text', 'Text message'],
    ['email', 'Email'],
];
const BEST: [string, string][] = [
    ['anytime', 'Any time'],
    ['morning', 'Morning'],
    ['afternoon', 'Afternoon'],
    ['evening', 'Evening'],
];

/**
 * "Request a pro": three short steps (what you need, when and how to reach you, your details), then a text code
 * checks the phone. The page around it is cached at the edge, so the form fetches its start token when it opens.
 */
export function RequestForm({ block, pro, place }: { block: RequestBlock; pro: string; place: string }) {
    const [step, setStep] = useState(0);
    const [cityId, setCityId] = useState<number | null>(block.cityId);
    const form = useForm({
        name: '',
        phone: '',
        email: '',
        contact_method: 'call',
        best_time: 'anytime',
        timing: 'now',
        answers: {} as Record<string, Answer>,
        consent: false,
        website_url: '',
        started: '',
    });
    useEffect(() => {
        fetch('/request/token', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((d: { started: string }) => form.setData('started', d.started))
            .catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const errors = form.errors as Record<string, string>;
    const steps = ['What you need', 'When and how', 'Your details'];
    const missing = block.questions.some((q) => q.required && [undefined, '', null].includes(form.data.answers[q.key] as string));
    return (
        <section id="request" aria-labelledby="request-title" className="mt-8 scroll-mt-20 rounded-lg border border-line bg-surface p-5">
            <h2 id="request-title" className="font-display text-2xl font-bold">
                Request {article(pro)} {pro} in {place}
            </h2>
            <ul className="mt-2 space-y-1 text-sm text-muted">
                <li className="flex items-center gap-2">
                    <ShieldCheck className="size-4 text-success" aria-hidden /> Goes to {block.pro ?? `the verified ${pro} here`} alone: never sold to
                    several.
                </li>
                {block.responseHours !== null && (
                    <li className="flex items-center gap-2">
                        <Clock className="size-4 text-primary" aria-hidden /> Usually responds in about {block.responseHours}{' '}
                        {block.responseHours === 1 ? 'hour' : 'hours'}.
                    </li>
                )}
                <li className="flex items-center gap-2">
                    <Lock className="size-4" aria-hidden /> Your number is shared only once a pro accepts. Free for you.
                </li>
            </ul>
            <ol className="mt-4 flex gap-2 text-xs" aria-label="Steps">
                {steps.map((s, i) => (
                    <li
                        key={s}
                        aria-current={i === step ? 'step' : undefined}
                        className={`flex-1 rounded-full px-2 py-1 text-center ${i <= step ? 'bg-primary text-white' : 'bg-bg text-muted'}`}
                    >
                        {i + 1}. {s}
                    </li>
                ))}
            </ol>
            <form
                className="mt-4 space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (step < 2) {
                        setStep(step + 1);
                        return;
                    }
                    form.post(`/request/${block.group}/${cityId ?? 0}`, { preserveScroll: true });
                }}
            >
                {step === 0 && (
                    <>
                        {block.cityId === null && (
                            <Field label="Your city" error={errors.city_id}>
                                <CityPicker onPick={(c) => setCityId(c.id)} />
                            </Field>
                        )}
                        {block.questions.map((q) => (
                            <Field key={q.key} label={`${q.label}${q.required ? '' : ' (optional)'}`} error={errors[`answers.${q.key}`]}>
                                <AnswerInput
                                    f={q}
                                    value={form.data.answers[q.key]}
                                    onChange={(v) => form.setData('answers', { ...form.data.answers, [q.key]: v })}
                                />
                            </Field>
                        ))}
                    </>
                )}
                {step === 1 && (
                    <>
                        <Field label="How soon do you need help?" error={errors.timing}>
                            <Select value={form.data.timing} onChange={(e) => form.setData('timing', e.target.value)}>
                                {TIMING.map(([k, l]) => (
                                    <option key={k} value={k}>
                                        {l}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Best way to reach you">
                                <Select value={form.data.contact_method} onChange={(e) => form.setData('contact_method', e.target.value)}>
                                    {CONTACT.map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Best time">
                                <Select value={form.data.best_time} onChange={(e) => form.setData('best_time', e.target.value)}>
                                    {BEST.map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </>
                )}
                {step === 2 && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="Your name" error={errors.name}>
                                <Input autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                            </Field>
                            <Field label="Mobile number (we’ll text a code)" error={errors.phone}>
                                <Input
                                    type="tel"
                                    autoComplete="tel"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    required
                                />
                            </Field>
                            <Field label="Email (optional)" error={errors.email} className="sm:col-span-2">
                                <Input
                                    type="email"
                                    autoComplete="email"
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                            </Field>
                        </div>
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.consent}
                                onChange={(e) => form.setData('consent', e.target.checked)}
                            />
                            <span>
                                I agree to be contacted about this request by one verified {pro}, by phone, text or email. Message and data rates may
                                apply; reply STOP to opt out.
                            </span>
                        </label>
                        {errors.consent && <p className="text-sm text-danger">{errors.consent}</p>}
                        <input
                            type="text"
                            name="website_url"
                            tabIndex={-1}
                            autoComplete="off"
                            aria-hidden
                            className="hidden"
                            value={form.data.website_url}
                            onChange={(e) => form.setData('website_url', e.target.value)}
                        />
                    </>
                )}
                {(errors.name || errors.group || errors.city_id) && step !== 2 && (
                    <p className="text-sm text-danger">{errors.group ?? errors.city_id}</p>
                )}
                <div className="flex gap-2">
                    {step > 0 && (
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                            Back
                        </Button>
                    )}
                    <Button
                        type="submit"
                        loading={form.processing}
                        disabled={(step === 0 && (missing || cityId === null)) || (step === 2 && !form.data.consent)}
                    >
                        {step < 2 ? 'Next' : 'Send my request'}
                    </Button>
                </div>
            </form>
        </section>
    );
}
