import type { Seo } from '@/types/seo';
import { type Item, ReferralList } from './List';

export default function Inbox({ items, status, seo }: { items: Item[]; status?: string; seo: Seo }) {
    return (
        <ReferralList
            title="Inbox"
            items={items}
            mode="in"
            status={status}
            seo={seo}
            empty="No referrals yet. They’ll appear here the moment one is sent to you."
        />
    );
}
