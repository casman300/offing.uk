import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import {
    defineConfig
} from 'vite';
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
        VitePWA({
            registerType: 'autoUpdate',
            manifest: {
                name: 'offing.uk',
                short_name: 'offing.uk',
                description: "What's in the offing.",
                start_url: '/',
                display: 'standalone',
                background_color: '#fbfaf9',
                theme_color: '#1b5874',
                icons: [],
            },
            workbox: {
                navigateFallbackDenylist: [/^\/mcp/],
            },
        }),
    ],
    esbuild: {
        jsx: 'automatic',
    },
});