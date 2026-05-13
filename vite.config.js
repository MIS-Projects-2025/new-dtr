import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
    ],
    optimizeDeps: {
        exclude: ['@digitalpersona/devices'],  // don't pre-bundle it
    },
    build: {
        rollupOptions: {
            external: ['WebSdk'],              // treat WebSdk as a browser global
        },
    },
});