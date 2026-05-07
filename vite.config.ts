import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    build: {
        // The player-metrics chunk is intentionally large (~180 KB gzipped)
        // — tree-shaken ECharts, lazy-loaded only when the Metrics tab
        // opens, not part of the initial bundle. Bump the warning limit
        // so Vite stops nagging about a deliberate split.
        chunkSizeWarningLimit: 600,
    },
});
