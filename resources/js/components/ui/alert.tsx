import { CheckCircle2, Info, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

const tones = {
    success: ['bg-[#ECFDF3] text-success dark:bg-success/15 dark:text-[#6EE7A0]', CheckCircle2],
    info: ['bg-[#EDF4FF] text-info dark:bg-info/15 dark:text-[#93C5FD]', Info],
    danger: ['bg-[#FDECEE] text-danger dark:bg-danger/15 dark:text-[#FDA4AF]', TriangleAlert],
} as const;

/** Inline status message, announced politely to screen readers. */
export function Alert({ tone = 'info', children, className }: { tone?: keyof typeof tones; children: ReactNode; className?: string }) {
    const [cls, Icon] = tones[tone];
    return (
        <div role={tone === 'danger' ? 'alert' : 'status'} className={cn('flex items-start gap-3 rounded-md p-3 text-sm', cls, className)}>
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div>{children}</div>
        </div>
    );
}
