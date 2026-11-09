import * as P from '@radix-ui/react-popover';
import { cn } from '@/lib/utils';

export const Popover = P.Root;
export const PopoverTrigger = P.Trigger;

export function PopoverContent({ className, align = 'end', ...props }: P.PopoverContentProps) {
    return (
        <P.Portal>
            <P.Content
                align={align}
                sideOffset={8}
                className={cn('z-50 w-[min(380px,calc(100vw-2rem))] rounded-lg border border-line bg-surface shadow-lg outline-none', className)}
                {...props}
            />
        </P.Portal>
    );
}
