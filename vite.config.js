import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    // Force IPv4 binding so generated <script> URLs match Laravel's host (127.0.0.1).
    // Default `localhost` resolves to ::1 (IPv6) on Windows, breaking script loads
    // when the page is served from 127.0.0.1.
    server: {
        host: '127.0.0.1',
        hmr: {
            host: '127.0.0.1',
        },
    },
});
