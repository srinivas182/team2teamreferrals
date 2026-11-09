import type { ResolvedComponent } from '@inertiajs/react';

const pages = import.meta.glob<{ default: ResolvedComponent }>('../pages/**/*.tsx');

export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const loader = pages[`../pages/${name}.tsx`];
    if (!loader) {
        throw new Error(`Page not found: ${name}`);
    }
    return (await loader()).default;
}
