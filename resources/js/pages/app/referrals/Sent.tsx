import type { Seo } from '@/types/seo';
import { type Item, ReferralList } from './List';

export default function Sent({ items, status, seo }: { items: Item[]; status?: string; seo: Seo }) {
    return <ReferralList title="Sent" items={items} mode="out" status={status} seo={seo} empty="You haven’t sent a referral yet." />;
}
