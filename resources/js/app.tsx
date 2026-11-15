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

// Where sign-ups come from (Sprint 46b): the first visit, and any later visit from a campaign or another site, are
// remembered (first-party, 90 days) and saved with the account at sign-up.
try {
    const q = new URLSearchParams(window.location.search);
    const utm = { source: q.get('utm_source'), medium: q.get('utm_medium'), campaign: q.get('utm_campaign') };
    const external = document.referrer !== '' && new URL(document.referrer).host !== window.location.host;
    const seen = document.cookie.split('; ').some((c) => c.startsWith('t2t_v='));
    if (!/^\/(app|admin)(\/|$)/.test(window.location.pathname) && (!seen || utm.source || external)) {
        const body = new Blob([JSON.stringify({ path: window.location.pathname, referrer: document.referrer, utm })], { type: 'application/json' });
        navigator.sendBeacon('/visit', body);
        document.cookie = `t2t_v=1; max-age=${90 * 86400}; path=/; samesite=lax`;
    }
} catch {
    // never let counting break the page
}
