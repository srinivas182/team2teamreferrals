import horizontal from '../../../../brand/logo/t2t-logo-horizontal.svg?raw';
import mark from '../../../../brand/logo/t2t-mark.svg?raw';
import { useId } from 'react';
import { cn } from '@/lib/utils';

// Navy and slate in the artwork follow the theme, so one file works in light and dark mode.
// Each copy gets its own gradient id: the "2" is drawn with a gradient, and when two logos share an id the browser
// uses the first one, which draws nothing if that logo is hidden (the admin sidebar on phones).
const themed = (svg: string, uid: string) =>
    svg
        .replace(/#0E1B3D/g, 'currentColor')
        .replace(/#5B6785/g, 'var(--t-text-muted)')
        .replace(/width="[\d.]+" height="[\d.]+"/, 'width="100%" height="100%"')
        .replace(/id="([\w-]+)"/g, `id="$1-${uid}"`)
        .replace(/url\(#([\w-]+)\)/g, `url(#$1-${uid})`);

export function Logo({ className, markOnly = false }: { className?: string; markOnly?: boolean }) {
    const uid = useId().replace(/[^\w-]/g, '');
    return (
        <span
            role="img"
            aria-label="Team to Team Referrals"
            className={cn('inline-block text-ink', className)}
            dangerouslySetInnerHTML={{ __html: themed(markOnly ? mark : horizontal, uid) }}
        />
    );
}
