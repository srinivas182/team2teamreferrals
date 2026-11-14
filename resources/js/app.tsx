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

// Arrivals from an AI assistant's answer (ChatGPT, Perplexity, Claude…), counted per page with no personal data, so the
// SEO/AEO report shows which pages AI assistants send people to (Sprint 43b). Sent once, on the first page.
try {
    const utm = new URLSearchParams(window.location.search).get('utm_source') ?? '';
    const ref = document.referrer;
    if (
        /(chatgpt\.com|openai\.com|perplexity\.ai|claude\.ai|gemini\.google\.com|copilot\.microsoft\.com|deepseek\.com|meta\.ai|you\.com)/i.test(
            `${ref} ${utm}`,
        )
    ) {
        const body = new Blob([JSON.stringify({ path: window.location.pathname, referrer: ref, utm })], { type: 'application/json' });
        navigator.sendBeacon('/ai-visit', body);
    }
} catch {
    // never let counting break the page
}
