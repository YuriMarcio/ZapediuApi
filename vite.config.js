import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        proxy: {
            '/auth': 'http://localhost:8080',
            '/public': 'http://localhost:8080',
            '/admin': 'http://localhost:8080',
            '/tenant': 'http://localhost:8080',
        },
    },
});
