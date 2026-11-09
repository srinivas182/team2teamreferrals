import { useEffect, useState } from 'react';

export type RemoteGroup = { key: string; label: string; items: { title: string; subtitle: string | null; url: string }[] };

/** Asks the server as the person types (debounced), so results respect their permissions. */
export function useRemoteSearch(query: string) {
    const [groups, setGroups] = useState<RemoteGroup[]>([]);
    useEffect(() => {
        const q = query.trim();
        const controller = new AbortController();
        const t = window.setTimeout(
            () => {
                if (q.length < 2) {
                    setGroups([]);
                    return;
                }
                fetch(`/app/search?q=${encodeURIComponent(q)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                })
                    .then((r) => (r.ok ? r.json() : { groups: [] }))
                    .then((d: { groups: RemoteGroup[] }) => setGroups(d.groups))
                    .catch(() => {});
            },
            q.length < 2 ? 0 : 180,
        );
        return () => {
            window.clearTimeout(t);
            controller.abort();
        };
    }, [query]);
    return groups;
}
