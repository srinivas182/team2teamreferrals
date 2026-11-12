import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export type Shortcut = { keys: string; label: string; href?: string };

/** Typing in a field, a dropdown or editable text never triggers a shortcut. */
const typing = (t: EventTarget | null) => t instanceof HTMLElement && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName));

/**
 * Keyboard shortcuts: single keys ("n", "/", "?") and "g" sequences ("g d" = go to dashboard, within 1.5 seconds).
 * Cmd/Ctrl+K is handled by the layouts.
 */
export function useShortcuts(shortcuts: Shortcut[], onPalette: () => void, onHelp: () => void) {
    useEffect(() => {
        let pendingG = 0;
        const onKey = (e: KeyboardEvent) => {
            if (e.metaKey || e.ctrlKey || e.altKey || typing(e.target)) return;
            const k = e.key.toLowerCase();
            if (e.key === '?') {
                e.preventDefault();
                onHelp();
                return;
            }
            if (k === '/') {
                e.preventDefault();
                onPalette();
                return;
            }
            if (pendingG && Date.now() - pendingG < 1500) {
                pendingG = 0;
                const hit = shortcuts.find((s) => s.keys === `g ${k}`);
                if (hit?.href) {
                    e.preventDefault();
                    router.visit(hit.href);
                }
                return;
            }
            if (k === 'g') {
                pendingG = Date.now();
                return;
            }
            const single = shortcuts.find((s) => s.keys === k);
            if (single?.href) {
                e.preventDefault();
                router.visit(single.href);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [shortcuts, onPalette, onHelp]);
}
