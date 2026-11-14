import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/embed-map.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            // Self-hosted variable Inter: no third-party font requests, with metric-matched fallbacks (no layout shift).
            // Not preloaded and the weight-only file (48 KB, not 73 KB): on a phone the stylesheet comes first and text
            // shows straight away in the matched fallback, which keeps Largest Contentful Paint under 2.5 s (Sprint 43).
            fonts: [
                local('Inter', {
                    variants: [{ src: 'node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2', weight: '100 900', style: 'normal' }],
                    preload: false,
                    // Size-matched fallbacks are written by hand in app.css (one each for iPhone/Mac, Android and Windows/Linux),
                    // because the generated one covers Arial only and Android phones don't have Arial.
                    optimizedFallbacks: false,
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: { alias: { '@': '/resources/js' } },
    server: {
        // Inside Docker Vite listens on all interfaces but the browser reaches it on localhost.
        ...(process.env.VITE_DOCKER ? { host: '0.0.0.0', origin: 'http://localhost:5173', hmr: { host: 'localhost' } } : {}),
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
