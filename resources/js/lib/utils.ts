import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/** Merge class names, letting later Tailwind classes win. */
export const cn = (...inputs: ClassValue[]) => twMerge(clsx(inputs));

/** US dollars with no cents, e.g. $3,638. */
export const money = (n: number) => n.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
