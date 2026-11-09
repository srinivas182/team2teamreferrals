import { useCallback, useEffect, useState } from 'react';

export type ThemeChoice = 'system' | 'light' | 'dark';
const KEY = 't2t-theme';

const read = (): ThemeChoice => {
    if (typeof window === 'undefined') return 'system';
    try {
        const v = localStorage.getItem(KEY);
        return v === 'light' || v === 'dark' ? v : 'system';
    } catch {
        return 'system';
    }
};

const systemDark = () => typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches;

/** Light, dark or follow the device. The inline script in app.blade.php applies it before first paint. */
export function useTheme() {
    const [choice, setChoice] = useState<ThemeChoice>(read);
    const [sysDark, setSysDark] = useState(systemDark);

    useEffect(() => {
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const on = () => setSysDark(mq.matches);
        mq.addEventListener('change', on);
        return () => mq.removeEventListener('change', on);
    }, []);

    const set = useCallback((next: ThemeChoice) => {
        setChoice(next);
        const el = document.documentElement;
        if (next === 'system') delete el.dataset.theme;
        else el.dataset.theme = next;
        try {
            if (next === 'system') localStorage.removeItem(KEY);
            else localStorage.setItem(KEY, next);
        } catch {
            /* storage unavailable: theme still applies for this page */
        }
    }, []);

    const resolved: 'light' | 'dark' = choice === 'system' ? (sysDark ? 'dark' : 'light') : choice;
    return { choice, resolved, set };
}
