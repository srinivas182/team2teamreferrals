import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, XCircle } from 'lucide-react';
import { useState } from 'react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Lesson = { title: string; minutes: number; body: string; video_url: string | null; transcript: string | null };
type Q = { index: number; q: string; options: string[] };
type Result = { score: number; passed: boolean; wrong: { index: number; why: string; q: string }[] };
type Props = {
    industry: { slug: string; name: string };
    lessons: Lesson[];
    done: number[];
    questions: Q[];
    retake: boolean;
    passMark: number;
    passed: boolean;
    result?: Result | null;
    seo: Seo;
};

export default function TrainingShow({ industry, lessons, done, questions, retake, passMark, passed, result, seo }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(() =>
        Math.max(
            0,
            lessons.findIndex((_, i) => !done.includes(i)),
        ),
    );
    const quiz = useForm<{ answers: Record<number, number> }>({ answers: {} });
    const allRead = lessons.every((_, i) => done.includes(i));
    return (
        <PortalLayout title={`${industry.name}: network training`} user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            {passed && (
                <Alert tone="success" className="mb-4 max-w-3xl">
                    You’ve passed. You can send and receive referrals in {industry.name}.{' '}
                    <a href={`/app/training/${industry.slug}/certificate.pdf`} className="font-medium underline">
                        Download your certificate
                    </a>
                </Alert>
            )}
            <div className="max-w-3xl space-y-3">
                <div className="h-2 rounded-full bg-bg" aria-label={`${done.length} of ${lessons.length} lessons done`}>
                    <div className="h-2 rounded-full bg-primary" style={{ width: `${(done.length / Math.max(1, lessons.length)) * 100}%` }} />
                </div>
                {lessons.map((l, i) => (
                    <Card key={i} className="p-0">
                        <button
                            className="flex w-full items-center gap-3 p-4 text-left"
                            aria-expanded={open === i}
                            onClick={() => setOpen(open === i ? -1 : i)}
                        >
                            {done.includes(i) ? (
                                <CheckCircle2 className="size-5 text-success" aria-hidden />
                            ) : (
                                <Circle className="size-5 text-subtle" aria-hidden />
                            )}
                            <span className="flex-1 font-medium">{l.title}</span>
                            <span className="text-sm text-muted">{l.minutes} min</span>
                        </button>
                        {open === i && (
                            <div className="space-y-3 border-t border-line p-4">
                                {l.video_url && <video src={l.video_url} controls className="w-full rounded-md" />}
                                <p>{l.body}</p>
                                {l.transcript && (
                                    <details className="text-sm text-muted">
                                        <summary>Transcript</summary>
                                        <p className="mt-2">{l.transcript}</p>
                                    </details>
                                )}
                                {!done.includes(i) && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                `/app/training/${industry.slug}/lessons/${i}`,
                                                {},
                                                { preserveScroll: true, onSuccess: () => setOpen(i + 1 < lessons.length ? i + 1 : -1) },
                                            )
                                        }
                                    >
                                        Mark done
                                    </Button>
                                )}
                            </div>
                        )}
                    </Card>
                ))}

                <Card className={cn('space-y-4 p-6', !allRead && !passed && 'opacity-70')}>
                    <h2 className="font-semibold">{retake ? 'Retake the questions you missed' : 'Quiz'}</h2>
                    <p className="text-sm text-muted">
                        Pass mark {passMark}%. {allRead ? '' : 'Finish the lessons first.'}
                    </p>
                    {result && (
                        <Alert tone={result.passed ? 'success' : 'info'}>
                            You scored {result.score}%. {result.passed ? 'Passed!' : 'Not quite: here’s why, then retake just those.'}
                            {result.wrong.length > 0 && (
                                <ul className="mt-2 space-y-1 text-sm">
                                    {result.wrong.map((w) => (
                                        <li key={w.index} className="flex gap-2">
                                            <XCircle className="mt-0.5 size-4 shrink-0" aria-hidden />
                                            <span>
                                                <strong>{w.q}</strong> {w.why}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Alert>
                    )}
                    {!passed &&
                        questions.map((q) => (
                            <fieldset key={q.index} disabled={!allRead} className="space-y-2">
                                <legend className="font-medium">{q.q}</legend>
                                {q.options.map((o, k) => (
                                    <label key={k} className="flex items-center gap-2 text-sm">
                                        <input
                                            type="radio"
                                            name={`q${q.index}`}
                                            checked={quiz.data.answers[q.index] === k}
                                            onChange={() => quiz.setData('answers', { ...quiz.data.answers, [q.index]: k })}
                                        />{' '}
                                        {o}
                                    </label>
                                ))}
                            </fieldset>
                        ))}
                    {!passed && (
                        <Button
                            disabled={!allRead || Object.keys(quiz.data.answers).length < questions.length}
                            loading={quiz.processing}
                            onClick={() => quiz.post(`/app/training/${industry.slug}/quiz`, { preserveScroll: true, onSuccess: () => quiz.reset() })}
                        >
                            Submit answers
                        </Button>
                    )}
                </Card>
            </div>
        </PortalLayout>
    );
}
