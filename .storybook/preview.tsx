import type { Preview } from '@storybook/react-vite';
import '../resources/css/app.css';

const preview: Preview = {
    globalTypes: {
        theme: {
            description: 'Light or dark theme',
            toolbar: { title: 'Theme', icon: 'mirror', items: ['light', 'dark'], dynamicTitle: true },
        },
    },
    initialGlobals: { theme: 'light' },
    decorators: [
        (Story, ctx) => {
            document.documentElement.dataset.theme = ctx.globals.theme as string;
            return (
                <div className="bg-bg p-6 text-ink">
                    <Story />
                </div>
            );
        },
    ],
    parameters: { a11y: { test: 'error' }, layout: 'fullscreen' },
};
export default preview;
