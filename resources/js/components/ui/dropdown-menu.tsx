import * as M from '@radix-ui/react-dropdown-menu';
import { cn } from '@/lib/utils';

export const DropdownMenu = M.Root;
export const DropdownMenuTrigger = M.Trigger;

export function DropdownMenuContent({ className, ...props }: M.DropdownMenuContentProps) {
    return (
        <M.Portal>
            <M.Content sideOffset={6} className={cn('z-50 min-w-48 rounded-lg border border-line bg-surface p-1 shadow-lg', className)} {...props} />
        </M.Portal>
    );
}

export function DropdownMenuItem({ className, ...props }: M.DropdownMenuItemProps) {
    return (
        <M.Item
            className={cn(
                'flex cursor-default items-center gap-2 rounded-md px-3 py-2 text-sm outline-none select-none data-[disabled]:opacity-50 data-[highlighted]:bg-bg',
                className,
            )}
            {...props}
        />
    );
}

export const DropdownMenuSeparator = () => <M.Separator className="my-1 h-px bg-line" />;
