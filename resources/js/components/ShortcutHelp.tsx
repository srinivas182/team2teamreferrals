import { Dialog, DialogContent } from '@/components/ui/dialog';
import type { Shortcut } from '@/lib/shortcuts';

/** The "?" overlay: every keyboard shortcut available here. */
export function ShortcutHelp({ open, onOpenChange, shortcuts }: { open: boolean; onOpenChange: (o: boolean) => void; shortcuts: Shortcut[] }) {
    const all: Shortcut[] = [
        { keys: 'ctrl k', label: 'Open the command palette (Cmd+K on Mac)' },
        { keys: '/', label: 'Open the command palette' },
        { keys: '?', label: 'Show these shortcuts' },
        ...shortcuts,
    ];
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent title="Keyboard shortcuts" description="Shortcuts don’t fire while you’re typing in a field.">
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    {all.map((s) => (
                        <div key={s.keys} className="contents">
                            <dt className="flex gap-1">
                                {s.keys.split(' ').map((k, i) => (
                                    <kbd key={i} className="rounded border border-line bg-bg px-1.5 py-0.5 font-mono text-xs uppercase">
                                        {k}
                                    </kbd>
                                ))}
                            </dt>
                            <dd>{s.label}</dd>
                        </div>
                    ))}
                </dl>
            </DialogContent>
        </Dialog>
    );
}
