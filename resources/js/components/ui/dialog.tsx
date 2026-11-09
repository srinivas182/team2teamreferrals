import * as D from '@radix-ui/react-dialog';
import { X } from 'lucide-react';
import * as React from 'react';
import { cn } from '@/lib/utils';

export const Dialog = D.Root;
export const DialogTrigger = D.Trigger;
export const DialogClose = D.Close;

/** Modal with focus trap, Esc to close and focus returned to the trigger. Bottom sheet on phones. */
export function DialogContent({
    title,
    description,
    children,
    className,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <D.Portal>
            <D.Overlay className="data-[state=open]:animate-in fixed inset-0 z-50 bg-navy-950/50" />
            <D.Content
                className={cn(
                    'fixed inset-x-0 bottom-0 z-50 max-h-[90vh] overflow-y-auto rounded-t-xl border border-line bg-surface p-6 shadow-lg sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-xl',
                    className,
                )}
            >
                <div className="flex items-start gap-4">
                    <div className="min-w-0 flex-1">
                        <D.Title className="text-lg font-semibold">{title}</D.Title>
                        {description && <D.Description className="mt-1 text-sm text-muted">{description}</D.Description>}
                    </div>
                    <D.Close className="rounded-md p-1 text-muted hover:bg-bg hover:text-ink" aria-label="Close">
                        <X className="size-5" />
                    </D.Close>
                </div>
                <div className="mt-4">{children}</div>
            </D.Content>
        </D.Portal>
    );
}
