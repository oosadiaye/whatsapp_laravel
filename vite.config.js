import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

/**
 * Known placeholder strings that ship in setup docs (.env.example,
 * docs/REVERB-SETUP.md). If `npm run build` picks up any of these as
 * VITE_REVERB_APP_KEY, the resulting bundle will try to open a
 * WebSocket like
 *   wss://blast.dpluxtech.com/app/GENERATE_RANDOM_32_CHARS_HERE
 * Reverb rejects it (correctly), and every page silently loses real-time.
 *
 * deploy.sh already fails loud on these for the script path. This second
 * line of defense catches operators who run `npm run build` manually
 * (out of the deploy script, e.g. during a quick frontend tweak) — the
 * build refuses to emit a bundle at all rather than producing one that
 * will silently break in production.
 */
const PLACEHOLDER_REVERB_KEYS = new Set([
    'GENERATE_RANDOM_32_CHARS_HERE',
    'REPLACE_ME',
    'changeme',
    'TODO',
    'YOUR_KEY_HERE',
    '',  // catches "VITE_REVERB_APP_KEY=" with nothing after the equals
]);

export default defineConfig(({ mode }) => {
    // loadEnv reads .env + .env.[mode] using Vite's own logic, so the
    // value we see here is identical to the one that would get baked
    // into the bundle.
    const env = loadEnv(mode, process.cwd(), '');

    if (mode === 'production' && PLACEHOLDER_REVERB_KEYS.has(env.VITE_REVERB_APP_KEY ?? '')) {
        // Bail loud with a runnable recovery hint. Using throw (not
        // process.exit) so Vite's normal error reporting fires.
        throw new Error(
            '\n\n' +
            '✗ Refusing to build: VITE_REVERB_APP_KEY in .env is a placeholder.\n' +
            '  Current value: "' + (env.VITE_REVERB_APP_KEY ?? '') + '"\n' +
            '\n' +
            '  Generate a real key:\n' +
            '    php -r "echo bin2hex(random_bytes(16));"\n' +
            '\n' +
            '  Then edit .env, set:\n' +
            '    REVERB_APP_KEY=<that value>\n' +
            '    VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"\n' +
            '\n' +
            '  Re-run: npm run build\n' +
            '\n' +
            '  Why: Vite bakes env vars into the JS bundle at build time.\n' +
            '  Shipping a placeholder produces a bundle that opens\n' +
            '  WebSockets like .../app/GENERATE_RANDOM_32_CHARS_HERE,\n' +
            '  which Reverb rejects — every page silently loses real-time.\n'
        );
    }

    return {
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
    };
});
