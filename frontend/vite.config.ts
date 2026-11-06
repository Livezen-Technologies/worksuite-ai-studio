import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

/**
 * virtual:latest-release: the newest CHANGELOG entry ({ version, date, title }), resolved at build
 * time so the landing page banner doesn't ship the whole changelog (the blog loads that lazily).
 */
function latestRelease() {
  const id = 'virtual:latest-release';
  return {
    name: 'latest-release',
    resolveId: (s: string) => (s === id ? '\0' + id : null),
    load(s: string) {
      if (s !== '\0' + id) return null;
      const file = fileURLToPath(new URL('../CHANGELOG.md', import.meta.url));
      const m = readFileSync(file, 'utf8').match(/^## \[([^\]]+)\]\s*—\s*(\d{4}-\d{2}-\d{2})\s*—\s*(.+)$/m);
      const value = m ? { version: m[1], date: m[2], title: m[3].trim() } : null;
      return `export default ${JSON.stringify(value)};`;
    },
  };
}

/*
 * Served from the site root on aistudio.livezentech.com: nginx sends every non-API path to
 * public/app/index.html and aliases /assets/ to public/app/assets/ (deploy/nginx-ollamahub.conf
 * is the older /app/ layout; the live vhost is the source of truth).
 */
export default defineConfig({
  base: '/',
  plugins: [react(), tailwindcss(), latestRelease()],
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
