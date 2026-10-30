import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

// In development the API is reached through this proxy, so the SPA and the API share
// one origin and Sanctum can use its cookie session (ADR-0003). Production does the
// same with nginx (see nginx.conf).
const apiTarget = process.env.ALPHAMASTER_API_URL ?? 'http://localhost:8080';

export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false },
      '/sanctum': { target: apiTarget, changeOrigin: false },
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Libraries change less often than app code, so they get their own cached chunk.
        manualChunks: { vendor: ['react', 'react-dom', 'react-router', '@tanstack/react-query', 'i18next', 'react-i18next', 'zod'] },
      },
    },
  },
  test: { environment: 'node' },
});
