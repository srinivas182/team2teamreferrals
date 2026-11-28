import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge class names, letting later Tailwind classes win. */
export const cn = (...inputs: ClassValue[]) => twMerge(clsx(inputs));

/** US dollars with no cents, e.g. $3,638. */
export const money = (n: number) => n.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

/** "1 month", "3 months": a free trial's length in words. */
export const months = (n: number) => `${n} ${n === 1 ? 'month' : 'months'}`;

/** "First month free", "First 3 months free". */
export const firstFree = (n: number) => (n === 1 ? 'First month free' : `First ${months(n)} free`);

/** The exact sharing reward wording shown on the join, invitation and waitlist offer pages (kept in step with SharingReward::TEXT). */
export const SHARING_REWARD_TEXT =
    'Sharing reward: send at least one referral in a month that the other team accepts and contacts, and your seat is free the next month. One free month at most each month, however many you send.';
