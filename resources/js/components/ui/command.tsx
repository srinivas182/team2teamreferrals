import { Command as Cmdk } from 'cmdk';
import { CornerDownLeft, Search } from 'lucide-react';
import * as React from 'react';
import { Dialog, DialogContent } from './dialog';

export type CommandItem = {
    group: string;
    label: string;
    icon?: React.ComponentType<{ className?: string }>;
    onSelect: () => void;
    keywords?: string[];
    shortcut?: string;
};

/** Command palette (Ctrl/⌘ K): jump anywhere or start any action, entirely from the keyboard. */
export function CommandPalette({ open, onOpenChange, items }: { open: boolean; onOpenChange: (o: boolean) => void; items: CommandItem[] }) {
    const groups = Array.from(new Set(items.map((i) => i.group)));
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                title="Search or jump to"
                className="p-0 sm:top-[18vh] sm:translate-y-0 [&>div:first-child]:sr-only [&>div:last-child]:mt-0"
            >
                <Cmdk label="Command palette" className="overflow-hidden">
                    <div className="flex items-center gap-3 border-b border-line px-4">
                        <Search className="size-[18px] text-subtle" aria-hidden />
                        <Cmdk.Input
                            placeholder="Search or jump to…"
                            className="h-14 flex-1 bg-transparent text-base outline-none placeholder:text-subtle"
                        />
                    </div>
                    <Cmdk.List className="max-h-[50vh] overflow-y-auto p-2">
                        <Cmdk.Empty className="p-6 text-center text-sm text-muted">No results. Try a client name, team or city.</Cmdk.Empty>
                        {groups.map((g) => (
                            <Cmdk.Group
                                key={g}
                                heading={g}
                                className="[&_[cmdk-group-heading]]:px-3 [&_[cmdk-group-heading]]:pt-3 [&_[cmdk-group-heading]]:pb-1 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:font-semibold [&_[cmdk-group-heading]]:text-subtle"
                            >
                                {items
                                    .filter((i) => i.group === g)
                                    .map((i) => (
                                        <Cmdk.Item
                                            key={i.label}
                                            value={i.label}
                                            keywords={i.keywords}
                                            onSelect={() => {
                                                onOpenChange(false);
                                                i.onSelect();
                                            }}
                                            className="group flex cursor-default items-center gap-3 rounded-md px-3 py-2.5 data-[selected=true]:bg-cobalt-50 dark:data-[selected=true]:bg-cobalt-500/15"
                                        >
                                            {i.icon && <i.icon className="size-4 text-muted" />}
                                            <span className="flex-1">{i.label}</span>
                                            <CornerDownLeft className="hidden size-4 text-muted group-data-[selected=true]:block" aria-hidden />
                                            {i.shortcut && (
                                                <kbd className="rounded border border-line px-1.5 text-xs text-muted group-data-[selected=true]:hidden">
                                                    {i.shortcut}
                                                </kbd>
                                            )}
                                        </Cmdk.Item>
                                    ))}
                            </Cmdk.Group>
                        ))}
                    </Cmdk.List>
                    <div className="flex gap-4 border-t border-line px-4 py-2 text-xs text-muted">
                        <span>↑↓ to move</span>
                        <span>Enter to open</span>
                        <span>Esc to close</span>
                    </div>
                </Cmdk>
            </DialogContent>
        </Dialog>
    );
}
