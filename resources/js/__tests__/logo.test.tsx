import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Logo } from '@/components/brand/Logo';

describe('Logo', () => {
    it('gives each copy its own gradient, so a hidden copy cannot blank the "2" in a visible one', () => {
        const { container } = render(
            <>
                <div hidden>
                    <Logo markOnly />
                </div>
                <Logo markOnly />
                <Logo />
            </>,
        );
        const ids = [...container.querySelectorAll('linearGradient')].map((g) => g.id);
        expect(ids).toHaveLength(3);
        expect(new Set(ids).size).toBe(3);
        // Every gradient reference points at the gradient in its own copy.
        for (const svg of container.querySelectorAll('svg')) {
            const own = svg.querySelector('linearGradient')!.id;
            const refs = [...svg.querySelectorAll('[stroke^="url("]')].map((p) => p.getAttribute('stroke'));
            expect(refs.length).toBeGreaterThan(0);
            for (const r of refs) expect(r).toBe(`url(#${own})`);
        }
    });
});
