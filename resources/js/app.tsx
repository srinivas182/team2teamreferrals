import { createInertiaApp } from '@inertiajs/react';
import { hydrateRoot, createRoot } from 'react-dom/client';
import '../css/app.css';
import { resolvePage } from './lib/resolve-page';

createInertiaApp({
    title: (title) => title,
    resolve: resolvePage,
    setup({ el, App, props }) {
        // Pages are server-rendered for search engines and AI crawlers; the browser hydrates them.
        if (el.hasChildNodes()) {
            hydrateRoot(el, <App {...props} />);
        } else {
            createRoot(el).render(<App {...props} />);
        }
    },
    progress: { color: '#2451F5', delay: 150 },
});
