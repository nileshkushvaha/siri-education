import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // app.css references the self-hosted Inter files by root-relative
        // path (/fonts/...). In dev the stylesheet is served from Vite's
        // origin, which has no public/ folder, so forward those requests
        // to Laravel. No effect on production builds.
        proxy: {
            '/fonts': 'http://127.0.0.1:8000',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
