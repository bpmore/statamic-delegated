import { defineConfig } from 'vite';
import statamic from '@statamic/cms/vite-plugin';

/**
 * Control panel assets.
 *
 * Statamic's own Vite plugin marks the control panel's Vue, Inertia and UI kit
 * as externals, so this bundle uses the ones already on the page rather than
 * shipping a second copy of Vue into somebody's admin.
 *
 * The output path is what AddonServiceProvider::registerVite expects:
 * `publicDirectory` + `buildDirectory`, published to public/vendor/<package>.
 */
export default defineConfig({
    plugins: [statamic()],
    build: {
        outDir: 'resources/dist/build',
        emptyOutDir: true,
        // Not `true`: Vite 6 would write .vite/manifest.json, while Laravel's
        // Vite resolver looks for <buildDirectory>/manifest.json.
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/cp.js',
        },
    },
});
