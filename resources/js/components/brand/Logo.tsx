import horizontal from '../../../../brand/logo/t2t-logo-horizontal.svg?raw';
import mark from '../../../../brand/logo/t2t-mark.svg?raw';
import { cn } from '@/lib/utils';

// Navy and slate in the artwork follow the theme, so one file works in light and dark mode.
const themed = (svg: string) =>
    svg
        .replace(/#0E1B3D/g, 'currentColor')
        .replace(/#5B6785/g, 'var(--t-text-muted)')
        .replace(/width="[\d.]+" height="[\d.]+"/, 'width="100%" height="100%"');

export function Logo({ className, markOnly = false }: { className?: string; markOnly?: boolean }) {
    return (
        <span
            role="img"
            aria-label="Team to Team Referrals"
            className={cn('inline-block text-ink', className)}
            dangerouslySetInnerHTML={{ __html: themed(markOnly ? mark : horizontal) }}
        />
    );
}
