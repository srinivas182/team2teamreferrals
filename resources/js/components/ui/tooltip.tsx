import * as T from '@radix-ui/react-tooltip';
import * as React from 'react';

export const TooltipProvider = T.Provider;

/** Short label on hover and keyboard focus. Required for icon-only buttons. */
export function Tooltip({ label, children }: { label: string; children: React.ReactElement }) {
    return (
        <T.Root delayDuration={300}>
            <T.Trigger asChild>{children}</T.Trigger>
            <T.Portal>
                <T.Content
                    sideOffset={6}
                    className="z-50 rounded-md bg-navy-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-md dark:bg-white dark:text-navy-900"
                >
                    {label}
                </T.Content>
            </T.Portal>
        </T.Root>
    );
}
