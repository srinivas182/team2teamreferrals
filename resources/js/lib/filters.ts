import { useEffect, useState } from 'react';

/**
 * Filters for an admin list that's already on the page, kept in the address bar (?status=live&tier=A) so a filtered
 * view survives a refresh or Back, and can be shared. Values equal to the default are left out of the address.
 */
export function useUrlFilters<T extends Record<string, string>>(defaults: T) {
    const [values, setValues] = useState<T>(() => {
        if (typeof window === 'undefined') return defaults;
        const params = new URLSearchParams(window.location.search);
        const read = { ...defaults };
        for (const key of Object.keys(defaults) as (keyof T)[]) {
            const v = params.get(key as string);
            if (v !== null) read[key] = v as T[keyof T];
        }
        return read;
    });
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        for (const [key, value] of Object.entries(values)) {
            if (value === defaults[key]) params.delete(key);
            else params.set(key, value);
        }
        const query = params.toString();
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values]);
    const set = (key: keyof T, value: string) => setValues((v) => ({ ...v, [key]: value }));
    const active = (Object.keys(defaults) as (keyof T)[]).filter((k) => values[k] !== defaults[k]).length;
    return { values, set, clear: () => setValues(defaults), active };
}
