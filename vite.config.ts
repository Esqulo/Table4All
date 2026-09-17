import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

// Set by docker-compose.override.yml. Inside the container the dev server has to
// listen on all interfaces, and the browser (on the host) reaches HMR via
// localhost:5173. File-change events don't cross the Windows bind mount, so poll.
const inDocker = process.env.VITE_IN_DOCKER === 'true';

export default defineConfig({
    server: inDocker
        ? {
              host: '0.0.0.0',
              port: 5173,
              strictPort: true,
              cors: true,
              hmr: { host: 'localhost' },
              watch: { usePolling: true },
          }
        : undefined,
    // Crawl every Inertia page up front so the dep optimizer doesn't keep
    // re-bundling (and reloading) as you navigate on the first run.
    optimizeDeps: inDocker ? { entries: ['resources/js/**/*.tsx'] } : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
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
});
