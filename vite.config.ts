import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            // Self-hosted variable Inter: no third-party font requests, preloaded, with metric-matched fallbacks (no layout shift).
            fonts: [
                local('Inter', {
                    variants: [{ src: 'node_modules/@fontsource-variable/inter/files/inter-latin-opsz-normal.woff2', weight: '100 900', style: 'normal' }],
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
