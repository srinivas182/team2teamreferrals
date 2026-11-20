import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useUrlFilters } from '@/lib/filters';

const defaults = { q: '', status: '', tier: '' };

describe('admin list filters', () => {
    afterEach(() => window.history.replaceState(null, '', '/'));

    it('reads filters from the address, so a filtered list survives a refresh', () => {
        window.history.replaceState(null, '', '/admin/industries?status=live&tier=A&other=1');
        const { result } = renderHook(() => useUrlFilters(defaults));
        expect(result.current.values).toEqual({ q: '', status: 'live', tier: 'A' });
        expect(result.current.active).toBe(2);
    });

    it('writes changes to the address, leaves defaults out, and clears', () => {
        window.history.replaceState(null, '', '/admin/groups?page=2');
        const { result } = renderHook(() => useUrlFilters(defaults));
        act(() => result.current.set('status', 'draft'));
        expect(window.location.search).toBe('?page=2&status=draft');
        act(() => result.current.set('q', 'solar'));
        expect(new URLSearchParams(window.location.search).get('q')).toBe('solar');
        act(() => result.current.clear());
        expect(window.location.search).toBe('?page=2');
        expect(result.current.active).toBe(0);
    });
});
