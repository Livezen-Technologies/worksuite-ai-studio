import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/*
 * Served from the site root on aistudio.livezentech.com: nginx sends every non-API path to
 * public/app/index.html and aliases /assets/ to public/app/assets/ (deploy/nginx-ollamahub.conf
 * is the older /app/ layout; the live vhost is the source of truth).
 */
export default defineConfig({
  base: '/',
  plugins: [react(), tailwindcss()],
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor': ['react', 'react-dom', 'react-router-dom', '@tanstack/react-query'],
          ui: ['@headlessui/react', '@heroicons/react/24/outline', '@heroicons/react/20/solid'],
          charts: ['recharts'],
        },
      },
    },
  },
  server: {
    port: 5191,
    proxy: { '^/api/': { target: process.env.AISTUDIO_API ?? 'https://aistudio.livezentech.com', changeOrigin: true } },
  },
  test: { environment: 'jsdom' },
} as never);
