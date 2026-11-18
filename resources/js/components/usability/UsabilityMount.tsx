import { lazy, Suspense, useEffect, useState } from 'react';

const UsabilityBar = lazy(() => import('./UsabilityBar'));

/** Loads the usability-test bar only in a browser that's running a test (the signed t2t_ut cookie), so nobody else pays for it. */
export function UsabilityMount() {
    const [on, setOn] = useState(false);
    useEffect(() => {
        const id = requestAnimationFrame(() => setOn(document.cookie.split('; ').some((c) => c.startsWith('t2t_ut='))));
        return () => cancelAnimationFrame(id);
    }, []);
    if (!on) return null;
    return (
        <Suspense fallback={null}>
            <UsabilityBar />
        </Suspense>
    );
}
