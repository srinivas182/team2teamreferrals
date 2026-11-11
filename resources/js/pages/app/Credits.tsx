import { usePage } from '@inertiajs/react';
import { SeoHead } from '@/components/seo/SeoHead';
import { Card } from '@/components/ui/card';
import { PortalLayout } from '@/layouts/PortalLayout';
import type { SharedProps } from '@/types/auth';
import type { Seo } from '@/types/seo';

type Entry = { kind: string; credits: number; balance: number; at: string; referral: string; client: string; group: string };
const words: Record<string, string> = {
    sent_accepted: 'Referral you sent was accepted',
    closed_won: 'Referral you sent closed as Won',
    received: 'You accepted a referral',
};

/** Portal → Credits: what you give and get, entry by entry, each linked to its referral. */
export default function Credits({
    entries,
    giveGet,
    seo,
}: {
    entries: Entry[];
    giveGet: { given: number; received: number; balance: number };
    seo: Seo;
}) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <PortalLayout title="Credits" user={{ name: auth.user?.name ?? '', team: '' }}>
            <SeoHead seo={seo} />
            <div className="max-w-4xl space-y-4">
                <Card className="flex flex-wrap gap-6 p-5 text-sm">
                    <span>
                        <strong className="text-lg tabular">{giveGet.given}</strong> given
                    </span>
                    <span>
                        <strong className="text-lg tabular">{giveGet.received}</strong> received
                    </span>
                    <span>
                        Balance{' '}
                        <strong className={`text-lg tabular ${giveGet.balance < 0 ? 'text-warning' : 'text-success'}`}>{giveGet.balance}</strong>
                    </span>
                    <span className="text-muted">
                        Giving referrals moves you up waitlists, earns the Top giver badge, and gives you extra time before a seat challenge.
                    </span>
                </Card>
                <Card className="overflow-x-auto">
                    <table className="w-full min-w-[620px] text-sm">
                        <thead className="text-left text-muted">
                            <tr>
                                {['Date', 'What happened', 'Network', 'Credits', 'Balance'].map((h) => (
                                    <th key={h} scope="col" className="px-4 py-3 font-medium">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {entries.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-6 text-center text-muted">
                                        Send a referral to earn your first credit.
                                    </td>
                                </tr>
                            )}
                            {entries.map((e, i) => (
                                <tr key={i} className="border-t border-line">
                                    <td className="px-4 py-2.5 text-muted tabular">{e.at}</td>
                                    <td className="px-4 py-2.5">
                                        {words[e.kind] ?? e.kind} ·{' '}
                                        <a href={`/app/referrals/${e.referral}`} className="text-primary hover:underline">
                                            {e.client}
                                        </a>
                                    </td>
                                    <td className="px-4 py-2.5 text-muted">{e.group}</td>
                                    <td className={`px-4 py-2.5 font-medium tabular ${e.credits < 0 ? 'text-warning' : 'text-success'}`}>
                                        {e.credits > 0 ? `+${e.credits}` : e.credits}
                                    </td>
                                    <td className="px-4 py-2.5 tabular">{e.balance}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>
            </div>
        </PortalLayout>
    );
}
