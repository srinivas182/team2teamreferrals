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
    receiver: { name: string; licenses: string[]; trained: boolean } | null;
    contactHours: number;
    seo: Seo;
};

/** What the client sees: who will contact them, why they can trust them, and where things stand. */
export default function ClientStatus({ status, firstName, territory, sender, pro, receiver, contactHours, seo }: Props) {
    const accepted = status === 'accepted';
    const steps: [string, boolean][] = [
        [`${sender} introduced you to a verified ${pro} in ${territory}`, true],
        [receiver ? `${receiver.name} accepted` : `A verified ${pro} is reviewing your request`, accepted],
        [accepted ? `They’ll contact you within ${contactHours} hours` : 'They’ll contact you soon after accepting', false],
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
                        <h2 className="font-semibold">Who will contact you</h2>
                        <p className="mt-1 text-lg font-semibold">{receiver.name}</p>
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
                    </Card>
                )}
                {(status === 'declined' || status === 'expired') && (
                    <p className="mt-4 text-sm text-muted">{sender} will be in touch about next steps.</p>
                )}
            </section>
        </PublicLayout>
    );
}
