import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: { alias: { '@': '/resources/js' } },
    test: { environment: 'jsdom', setupFiles: ['resources/js/__tests__/setup.ts'], include: ['resources/js/**/*.test.tsx'], css: false },
});
