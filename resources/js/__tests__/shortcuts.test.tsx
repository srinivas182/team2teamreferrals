import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { visit: (href: string) => visit(href) } }));

import { useShortcuts, type Shortcut } from '@/lib/shortcuts';

const shortcuts: Shortcut[] = [
    { keys: 'n', label: 'New referral', href: '/app/send' },
    { keys: 'g d', label: 'Dashboard', href: '/app/dashboard' },
];

function Harness({ onPalette, onHelp }: { onPalette: () => void; onHelp: () => void }) {
    useShortcuts(shortcuts, onPalette, onHelp);
    return <input aria-label="field" />;
}

const press = (key: string, target: EventTarget = window) => target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

describe('keyboard shortcuts', () => {
    beforeEach(() => visit.mockReset());

    it('opens a screen from a single key and from a "g" sequence', () => {
        render(<Harness onPalette={() => {}} onHelp={() => {}} />);
        press('n');
        press('g');
        press('d');
        expect(visit.mock.calls).toEqual([['/app/send'], ['/app/dashboard']]);
    });

    it('opens the palette with "/" and the help with "?"', () => {
        const onPalette = vi.fn();
        const onHelp = vi.fn();
        render(<Harness onPalette={onPalette} onHelp={onHelp} />);
        press('/');
        press('?');
        expect(onPalette).toHaveBeenCalledOnce();
        expect(onHelp).toHaveBeenCalledOnce();
    });

    it('never fires while typing in a field', () => {
        const { getByLabelText } = render(<Harness onPalette={() => {}} onHelp={() => {}} />);
        const field = getByLabelText('field');
        press('n', field);
        press('g', field);
        press('d', field);
        expect(visit).not.toHaveBeenCalled();
    });
});
