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
