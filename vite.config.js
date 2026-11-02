import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            // app.js tetap entry Blade lama; spa.ts adalah entry terisolasi Inertia.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/spa.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
});
