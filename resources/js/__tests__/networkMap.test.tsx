import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { axe } from 'vitest-axe';
import { NetworkMap } from '@/components/map/NetworkMap';
import type { MapData, MapPoint } from '@/components/map/types';

const data: MapData = {
    title: 'Solar Installers Network',
    updated: '2027-01-01T00:00:00Z',
    counts: { held: 1, waitlist: 0, open: 1 },
    points: [
        {
            key: '1:city:3',
            name: 'Austin, TX',
            state: 'TX',
            lat: 30.2,
            lon: -97.9,
            status: 'held',
            url: '/industries/solar/tx/austin',
            team: { name: 'Lone Star Solar', slug: 'lone-star-solar' },
        },
        {
            key: '1:open:4',
            name: 'Houston, TX',
            state: 'TX',
            lat: 29.6,
            lon: -95.5,
            status: 'open',
            url: '/industries/solar/tx/houston',
            claim: '/claim/solar/4',
        },
    ],
};

describe('NetworkMap', () => {
    it('lists every place as text with its link, and filters by status', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => undefined)),
        );
        const { container } = render(<NetworkMap initial={data} src="/map/group/solar.json" />);
        expect(screen.getByRole('link', { name: 'Austin, TX: Lone Star Solar' }).getAttribute('href')).toBe('/t/lone-star-solar');
        expect(screen.getByRole('link', { name: 'claim' }).getAttribute('href')).toBe('/claim/solar/4');
        const filled = screen.getByRole('button', { name: 'Filled: 1' });
        await userEvent.click(filled);
        expect(filled.getAttribute('aria-pressed')).toBe('false');
        expect(await axe(container)).toHaveNoViolations();
        vi.unstubAllGlobals();
    });

    it('adds a team’s own seats (even with a private profile) and links out from an embed', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => undefined)),
        );
        const mine: MapPoint = { ...(data.points[0] as MapPoint), key: '1:city:9', name: 'Dallas, TX' };
        render(<NetworkMap initial={data} src="/x.json" extra={[mine]} base="https://example.org" external />);
        expect(screen.getByRole('button', { name: 'Filled: 2' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'claim' }).getAttribute('href')).toBe('https://example.org/claim/solar/4');
        vi.unstubAllGlobals();
    });
});
