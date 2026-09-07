import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ReactNode } from 'react';

import AppLayout from '@/layouts/AppLayout';
import type { PageComponent } from '@/types';

const appName = import.meta.env.VITE_APP_NAME ?? 'Akunta';

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    resolve: async (name) => {
        const pages = import.meta.glob<{ default: PageComponent }>('./pages/**/*.tsx');
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Halaman Inertia [${name}] tidak ditemukan.`);
        }

        const module = await page();

        /*
         * Halaman auth memakai layout sendiri. Sisanya dibungkus AppLayout secara
         * terpusat supaya navigasi dan shell tidak diulang di setiap halaman.
         */
        module.default.layout ??= name.startsWith('auth/')
            ? undefined
            : (children: ReactNode) => <AppLayout>{children}</AppLayout>;

        return module;
    },

    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },

    progress: {
        color: '#0f766e',
    },
});
