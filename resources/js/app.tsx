import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';
import AppLayout from '@/layouts/app-layout';
import { watchSystemTheme } from '@/lib/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');

let AdminLayout: ComponentType<{ children: ReactNode }> | null = null;

void createInertiaApp({
    title: (title) => {
        if (!title) {
            return appName;
        }

        return title.startsWith(appName) ? title : `${title} - ${appName}`;
    },
    strictMode: true,
    resolve: async (name) => {
        const load = pages[`./pages/${name}.tsx`];

        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }

        if (name.startsWith('admin/') && AdminLayout === null) {
            AdminLayout = (await import('@/layouts/admin-layout')).default;
        }

        return (await load()).default;
    },
    layout: (name) =>
        name.startsWith('admin/') && AdminLayout ? AdminLayout : AppLayout,
    progress: {
        includeCSS: false,
    },
});

if (typeof window !== 'undefined') {
    watchSystemTheme();
}
