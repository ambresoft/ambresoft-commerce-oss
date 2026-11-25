import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.tsx'],
    setupFiles: ['resources/js/tests/setup.ts'],
    // Full editors (banners, products) can need more than the default 5 s on busy CI runners; a real hang still fails.
    testTimeout: 15000,
  },
});
