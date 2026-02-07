import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/custom.css',
                'resources/js/app.js'
            ],
            refresh: true,
        }),
        vue({
            // Treat absolute URLs (e.g. /assets/* served from public/) as runtime URLs,
            // not as module imports. Needed for legacy chat markup parity.
            template: {
                transformAssetUrls: {
                    includeAbsolute: false,
                },
            },
        }),
    ],
})
