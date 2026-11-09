import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { renderToString } from 'react-dom/server';
import { resolvePage } from './lib/resolve-page';

createServer((page) =>
    createInertiaApp({
        page,
        render: renderToString,
        title: (title) => title,
        resolve: resolvePage,
        setup: ({ App, props }) => <App {...props} />,
    }),
);
