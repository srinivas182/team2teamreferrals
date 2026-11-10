import { router } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/field';

export type ClosingInfo = {
    status: string | null;
    dispute: string | null;
    canConfirm: boolean;
    mySide: 'sender' | 'receiver' | null;
    myRating: { stars: number; comment: string | null; editable: boolean } | null;
    clientRated: boolean;
    thanks: { note: string; public: boolean } | null;
    canThank: boolean;
};

export function Stars({ value, onChange, label }: { value: number; onChange?: (n: number) => void; label: string }) {
    return (
        <div className="flex gap-0.5" role={onChange ? 'radiogroup' : undefined} aria-label={label}>
            {[1, 2, 3, 4, 5].map((n) => (
                <button
                    key={n}
                    type="button"
                    role={onChange ? 'radio' : undefined}
                    aria-checked={onChange ? n === value : undefined}
                    aria-label={`${n} star${n > 1 ? 's' : ''}`}
                    disabled={!onChange}
                    onClick={() => onChange?.(n)}
                >
                    <Star className={n <= value ? 'size-6 fill-orange-500 text-orange-500' : 'size-6 text-subtle'} aria-hidden />
                </button>
            ))}
        </div>
    );
}

const words: Record<string, string> = {
    awaiting: 'Waiting for the sender to confirm',
    confirmed: 'Close confirmed',
    auto_confirmed: 'Close confirmed (automatically)',
    disputed: 'Close disputed',
};

/** After closing: confirm or dispute, rate each other, and say thank you. */
export function ClosingPanel({ id, c }: { id: string; c: ClosingInfo }) {
    const [reason, setReason] = useState('');
    const [stars, setStars] = useState(c.myRating?.stars ?? 0);
    const [aspects, setAspects] = useState<Record<string, number>>({});
    const [comment, setComment] = useState(c.myRating?.comment ?? '');
    const [note, setNote] = useState('');
    const post = (url: string, data: Record<string, unknown>) => router.post(url, data as never, { preserveScroll: true });
    const aspectNames: Record<string, string> =
        c.mySide === 'sender' ? { communication: 'Communication', speed: 'Speed', outcome: 'Client outcome' } : { quality: 'Referral quality' };
    return (
        <Card className="space-y-4 p-6">
            <div className="flex items-center gap-2">
                <h2 className="font-semibold">Closing and ratings</h2>
                {c.status && (
                    <Badge tone={c.status === 'disputed' ? 'danger' : c.status === 'awaiting' ? 'warning' : 'success'}>{words[c.status]}</Badge>
                )}
            </div>
            {c.dispute && <p className="text-sm">Dispute: “{c.dispute}”</p>}
            {c.canConfirm && (
                <div className="space-y-2">
                    <Button onClick={() => post(`/app/referrals/${id}/close/confirm`, {})}>Confirm the close</Button>
                    {c.status === 'awaiting' && (
                        <div className="flex flex-wrap gap-2">
                            <Input
                                aria-label="What isn’t right?"
                                placeholder="Something not right? Tell the group owner"
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="min-w-64 flex-1"
                            />
                            <Button
                                variant="ghost"
                                disabled={reason.trim().length < 5}
                                onClick={() => post(`/app/referrals/${id}/close/dispute`, { reason })}
                            >
                                Dispute
                            </Button>
                        </div>
                    )}
                </div>
            )}
            {c.mySide && (!c.myRating || c.myRating.editable) && (
                <div className="space-y-2 border-t border-line pt-4">
                    <p className="text-sm font-medium">{c.mySide === 'sender' ? 'Rate the receiving team' : 'Rate the referring team'}</p>
                    <Stars value={stars} onChange={setStars} label="Overall rating" />
                    {Object.entries(aspectNames).map(([k, label]) => (
                        <div key={k} className="flex items-center gap-3 text-sm">
                            <span className="w-32 text-muted">{label}</span>
                            <Stars value={aspects[k] ?? 0} onChange={(n) => setAspects({ ...aspects, [k]: n })} label={label} />
                        </div>
                    ))}
                    <Input
                        aria-label="Comment (optional)"
                        placeholder="A few words (optional)"
                        value={comment}
                        onChange={(e) => setComment(e.target.value)}
                    />
                    <Button
                        size="sm"
                        variant="secondary"
                        disabled={stars === 0}
                        onClick={() => post(`/app/referrals/${id}/rate`, { stars, aspects, comment })}
                    >
                        {c.myRating ? 'Update rating' : 'Save rating'}
                    </Button>
                    {c.myRating && <p className="text-xs text-muted">You can change your rating for 7 days.</p>}
                </div>
            )}
            {c.myRating && !c.myRating.editable && (
                <div className="flex items-center gap-2 text-sm">
                    Your rating: <Stars value={c.myRating.stars} label="Your rating" />
                </div>
            )}
            {c.clientRated && <p className="text-sm text-muted">The client has rated their experience.</p>}
            {c.canThank && (
                <div className="flex flex-wrap gap-2 border-t border-line pt-4">
                    <Input
                        aria-label="Thank-you note"
                        placeholder="Say thank you to the referring team"
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        className="min-w-64 flex-1"
                    />
                    <Button size="sm" disabled={!note.trim()} onClick={() => post(`/app/referrals/${id}/thanks`, { note })}>
                        Send thanks
                    </Button>
                </div>
            )}
            {c.thanks && (
                <div className="rounded-md bg-bg p-3 text-sm">
                    <p>“{c.thanks.note}”</p>
                    {c.mySide === 'sender' && (
                        <label className="mt-2 flex items-center gap-2 text-xs text-muted">
                            <input
                                type="checkbox"
                                checked={c.thanks.public}
                                onChange={(e) => post(`/app/referrals/${id}/thanks/public`, { public: e.target.checked })}
                            />{' '}
                            Show this on our profile
                        </label>
                    )}
                </div>
            )}
        </Card>
    );
}
