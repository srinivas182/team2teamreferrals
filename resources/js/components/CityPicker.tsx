import { MapPin } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Input } from '@/components/ui/field';
import { cn } from '@/lib/utils';

export type CityOption = { id: number; name: string; state: string; county: string | null; population: number; label: string };

/** Type-ahead city picker: "st george" finds Saint George, largest places first. Keyboard and screen-reader friendly (combobox). */
export function CityPicker({
    onPick,
    state,
    placeholder = 'Start typing a city…',
    id,
}: {
    onPick: (c: CityOption) => void;
    state?: string;
    placeholder?: string;
    id?: string;
}) {
    const [q, setQ] = useState('');
    const [options, setOptions] = useState<CityOption[]>([]);
    const [active, setActive] = useState(0);
    const listId = useId();
    useEffect(() => {
        if (q.trim().length < 2) return;
        const c = new AbortController();
        const t = window.setTimeout(() => {
            fetch(`/locations/cities?q=${encodeURIComponent(q)}${state ? `&state=${state}` : ''}`, { signal: c.signal })
                .then((r) => r.json())
                .then((d: { cities: CityOption[] }) => {
                    setOptions(d.cities);
                    setActive(0);
                })
                .catch(() => {});
        }, 150);
        return () => {
            window.clearTimeout(t);
            c.abort();
        };
    }, [q, state]);
    const shown = q.trim().length < 2 ? [] : options;
    const pick = (c: CityOption) => {
        onPick(c);
        setQ('');
        setOptions([]);
    };
    return (
        <div className="relative">
            <Input
                id={id}
                role="combobox"
                aria-expanded={shown.length > 0}
                aria-controls={listId}
                aria-autocomplete="list"
                placeholder={placeholder}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') setActive((a) => Math.min(a + 1, shown.length - 1));
                    if (e.key === 'ArrowUp') setActive((a) => Math.max(a - 1, 0));
                    if (e.key === 'Enter' && shown[active]) {
                        e.preventDefault();
                        pick(shown[active]!);
                    }
                }}
            />
            {shown.length > 0 && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute inset-x-0 z-20 mt-1 max-h-72 overflow-y-auto rounded-md border border-line bg-surface shadow-lg"
                >
                    {shown.map((c, i) => (
                        <li key={c.id} role="option" aria-selected={i === active}>
                            <button
                                type="button"
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => pick(c)}
                                className={cn(
                                    'flex w-full items-center gap-2 px-3 py-2 text-left text-sm',
                                    i === active && 'bg-cobalt-50 dark:bg-cobalt-500/15',
                                )}
                            >
                                <MapPin className="size-4 text-muted" aria-hidden />
                                <span className="font-medium">{c.label}</span>
                                {c.county && <span className="text-muted">{c.county} County</span>}
                                {c.population > 0 && <span className="ml-auto text-xs text-subtle tabular">{c.population.toLocaleString()}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
