import { router } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { Seo } from '@/types/seo';

type Props = {
    summary: { network: string; city: string; client: string; contact: string; notes: string | null };
    action: string | null;
    done: string | null;
    team?: string | null;
    duplicate?: boolean;
    problem?: string | null;
    fullForm: string | null;
    seo: Seo;
};

/** One-tap confirmation from WhatsApp or SMS: check the summary, confirm consent, send. */
export default function BotConfirm({ summary, action, done, team, duplicate, problem, fullForm, seo }: Props) {
    const send = (confirmDuplicate: boolean) => action && router.post(action, { confirm_duplicate: confirmDuplicate });
    return (
        <main className="mx-auto max-w-md space-y-4 p-4">
            <SeoHead seo={seo} />
            <p className="font-display text-lg font-bold">Team to Team Referrals</p>
            {done ? (
                <Card className="space-y-2 p-6 text-center">
                    <CheckCircle2 className="mx-auto size-10 text-success" aria-hidden />
                    <h1 className="text-xl font-semibold">Referral sent{team ? ` to ${team}` : ''}</h1>
                    <p className="text-sm text-muted">We’ll message you when they accept.</p>
                    <a href={`/app/referrals/${done}`} className="text-sm font-medium text-primary">
                        Open it in the app
                    </a>
                </Card>
            ) : (
                <Card className="space-y-4 p-6">
                    <h1 className="text-xl font-semibold">Confirm your referral</h1>
                    <dl className="space-y-1 text-sm">
                        {(
                            [
                                ['Network', summary.network],
                                ['City', summary.city],
                                ['Client', summary.client],
                                ['Contact', summary.contact],
                            ] as const
                        ).map(([k, v]) => (
                            <div key={k} className="flex gap-2">
                                <dt className="w-20 text-muted">{k}</dt>
                                <dd className="font-medium">{v}</dd>
                            </div>
                        ))}
                    </dl>
                    {summary.notes && <p className="text-sm">{summary.notes}</p>}
                    {problem && (
                        <Alert tone="danger">
                            {problem}{' '}
                            {fullForm && (
                                <a href={fullForm} className="font-medium underline">
                                    Open the full form
                                </a>
                            )}
                        </Alert>
                    )}
                    {duplicate ? (
                        <>
                            <Alert tone="info">This client was referred recently. Send it anyway?</Alert>
                            <Button className="h-12 w-full text-base" onClick={() => send(true)}>
                                Yes, send it anyway
                            </Button>
                        </>
                    ) : (
                        !problem &&
                        action && (
                            <Button className="h-12 w-full text-base" onClick={() => send(false)}>
                                My client agreed. Send it
                            </Button>
                        )
                    )}
                    <p className="text-xs text-muted">
                        By sending, you confirm your client agreed to be referred. The link works once and for 30 minutes.
                    </p>
                    {fullForm && !problem && (
                        <a href={fullForm} className="block text-center text-sm text-primary">
                            Edit details in the full form
                        </a>
                    )}
                </Card>
            )}
        </main>
    );
}
