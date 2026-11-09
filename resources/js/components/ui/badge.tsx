import { Flame, Sprout, Sun } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

const tones = {
    neutral: 'bg-navy-100 text-navy-700 dark:bg-navy-800 dark:text-navy-200',
    success: 'bg-[#ECFDF3] text-success dark:bg-success/20 dark:text-[#6EE7A0]',
    warning: 'bg-[#FEF9E7] text-warning dark:bg-warning/20 dark:text-[#FCD34D]',
    danger: 'bg-[#FDECEE] text-danger dark:bg-danger/20 dark:text-[#FDA4AF]',
    info: 'bg-[#EDF4FF] text-info dark:bg-info/20 dark:text-[#93C5FD]',
    sender: 'bg-cobalt-50 text-cobalt-700 dark:bg-cobalt-500/20 dark:text-cobalt-200',
    receiver: 'bg-orange-50 text-orange-700 dark:bg-orange-500/20 dark:text-orange-200',
} as const;

export type BadgeTone = keyof typeof tones;

export function Badge({ tone = 'neutral', className, children }: { tone?: BadgeTone; className?: string; children: React.ReactNode }) {
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold', tones[tone], className)}>
            {children}
        </span>
    );
}

export type Temperature = 'hot' | 'warm' | 'future';
const temps = {
    hot: { label: 'Hot', Icon: Flame, cls: 'bg-[#FDECEE] text-hot dark:bg-hot/20 dark:text-[#FDA4AF]' },
    warm: { label: 'Warm', Icon: Sun, cls: 'bg-[#FEF3E2] text-warm dark:bg-warm/25 dark:text-[#FCD34D]' },
    future: { label: 'Future', Icon: Sprout, cls: 'bg-[#F3EEFF] text-future dark:bg-future/25 dark:text-[#C4B5FD]' },
};

/** Lead temperature: always icon + label, never colour alone. */
export function TempBadge({ t, className }: { t: Temperature; className?: string }) {
    const { label, Icon, cls } = temps[t];
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold', cls, className)}>
            <Icon className="size-3.5" aria-hidden />
            {label}
        </span>
    );
}
