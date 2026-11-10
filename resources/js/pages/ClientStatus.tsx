import { router } from '@inertiajs/react';
import { BadgeCheck, CheckCircle2, Circle, GraduationCap } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { Seo } from '@/types/seo';

type Props = {
    status: string;
    firstName: string;
    territory: string;
    sender: string;
    pro: string;
    receiver: {
        name: string;
        leader: string;
        bio: string | null;
        serviceArea: string | null;
        phone: string | null;
        website: string | null;
        logo: string | null;
        licenses: string[];
        trained: boolean;
    } | null;
    token: string;
    consentAt: string | null;
    temperature: string | null;
    canCheckIn: boolean;
    flash: string | null;
    contactHours: number;
    stage: { label: string; kind: string } | null;
    seo: Seo;
};

/** What the client sees: who will contact them, why they can trust them, and where things stand. */
export default function ClientStatus({
    status,
    firstName,
    territory,
    sender,
    pro,
    receiver,
    contactHours,
    stage,
    token,
    consentAt,
    temperature,
    canCheckIn,
    flash,
    seo,
}: Props) {
    const accepted = status === 'accepted';
    const steps: [string, boolean][] = [
        [`${sender} introduced you to a verified ${pro} in ${territory}`, true],
        [receiver ? `${receiver.name} accepted` : `A verified ${pro} is reviewing your request`, accepted],
        stage && stage.kind !== 'open'
            ? [stage.kind === 'won' ? `${stage.label}: all done` : stage.label, true]
            : stage && stage.label !== 'New'
              ? [`Now: ${stage.label}`, true]
              : [accepted ? `They’ll contact you within ${contactHours} hours` : 'They’ll contact you soon after accepting', false],
    ];
    return (
        <PublicLayout>
            <SeoHead seo={seo} />
            <section className="mx-auto max-w-xl px-5 py-14">
                <h1 className="font-display text-3xl font-extrabold">Hi {firstName},</h1>
                <p className="mt-2 text-muted">Here’s where your introduction stands. We’ll keep this page up to date.</p>
                <Card className="mt-6 p-6">
                    <ol className="space-y-4">
                        {steps.map(([t, done]) => (
                            <li key={t} className="flex gap-3">
                                {done ? (
                                    <CheckCircle2 className="size-5 shrink-0 text-success" aria-hidden />
                                ) : (
                                    <Circle className="size-5 shrink-0 text-subtle" aria-hidden />
                                )}
                                <span className={done ? '' : 'text-muted'}>{t}</span>
                            </li>
                        ))}
                    </ol>
                </Card>
                {receiver && (
                    <Card className="mt-4 p-6">
                        <h2 className="font-semibold">Meet your {pro}</h2>
                        <div className="mt-2 flex items-center gap-3">
                            {receiver.logo && <img src={receiver.logo} alt="" className="size-12 rounded-lg object-cover" />}
                            <div>
                                <p className="text-lg font-semibold">{receiver.name}</p>
                                {receiver.leader && (
                                    <p className="text-sm text-muted">
                                        Led by {receiver.leader}
                                        {receiver.serviceArea ? ` · serving ${receiver.serviceArea}` : ''}
                                    </p>
                                )}
                            </div>
                        </div>
                        {receiver.bio && <p className="mt-3 text-sm">{receiver.bio}</p>}
                        <div className="mt-2 flex flex-wrap gap-2">
                            {receiver.licenses.map((l) => (
                                <Badge key={l} tone="success">
                                    <BadgeCheck className="size-3" aria-hidden /> {l} license verified
                                </Badge>
                            ))}
                            {receiver.trained && (
                                <Badge tone="info">
                                    <GraduationCap className="size-3" aria-hidden /> Network trained
                                </Badge>
                            )}
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {receiver.phone && (
                                <a
                                    href={`tel:${receiver.phone}`}
                                    className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-semibold text-white"
                                >
                                    Call {receiver.leader || receiver.name}
                                </a>
                            )}
                            {receiver.website && (
                                <a
                                    href={receiver.website}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex h-10 items-center rounded-md border border-line px-4 text-sm font-semibold"
                                >
                                    Website
                                </a>
                            )}
                        </div>
                    </Card>
                )}
                {canCheckIn && (
                    <Card className="mt-4 p-6">
                        {flash === 'client-checkin' ? (
                            <p className="font-medium text-success">Thanks, we’ve let {receiver?.name ?? 'your ' + pro} know.</p>
                        ) : (
                            <>
                                <h2 className="font-semibold">{temperature === 'future' ? 'How are your plans coming along?' : 'Quick check-in'}</h2>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {[
                                        ['ready', 'I’m ready now'],
                                        ['later', 'Still planning'],
                                        ['not_needed', 'I no longer need help'],
                                    ].map(([a, l]) => (
                                        <button
                                            key={a}
                                            className="h-10 rounded-md border border-line px-4 text-sm font-medium hover:bg-bg"
                                            onClick={() => router.post(`/r/${token}/checkin`, { answer: a }, { preserveScroll: true })}
                                        >
                                            {l}
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}
                    </Card>
                )}
                {consentAt && (
                    <p className="mt-4 text-xs text-muted">You agreed to this introduction on {consentAt}. Reply STOP to any text to opt out.</p>
                )}
                {(status === 'declined' || status === 'expired') && (
                    <p className="mt-4 text-sm text-muted">{sender} will be in touch about next steps.</p>
                )}
            </section>
        </PublicLayout>
    );
}
