import type { StorybookConfig } from '@storybook/react-vite';
import type { PluginOption } from 'vite';

const config: StorybookConfig = {
    stories: ['../resources/js/stories/**/*.stories.tsx'],
    addons: ['@storybook/addon-a11y'],
    framework: { name: '@storybook/react-vite', options: {} },
    // Storybook builds the components on their own, without the Laravel entry points.
    viteFinal: async (config) => {
        const flat = (config.plugins ?? []).flat(Infinity as 1) as PluginOption[];
        config.plugins = flat.filter((p) => !(p && typeof p === 'object' && 'name' in p && String(p.name).startsWith('laravel')));
        return config;
    },
};
export default config;
