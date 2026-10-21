import { wayfinder } from '@laravel/vite-plugin-wayfinder';
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
        wayfinder(),
        VitePWA({
            registerType: 'autoUpdate',
            manifest: {
                name: 'offing.uk',
                short_name: 'offing.uk',
                description: "What's in the offing.",
                start_url: '/dashboard',
                // The app lives at the root even though this file is in /build/;
                // without this, "Add to Home Screen" isn't a standalone app.
                scope: '/',
                id: '/',
                display: 'standalone',
                background_color: '#fbfaf9',
                theme_color: '#1b5874',
                icons: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
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