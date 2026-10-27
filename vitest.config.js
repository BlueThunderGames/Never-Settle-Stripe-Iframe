import { defineConfig } from 'vitest/config';

// Separate from vite.config.js so the Laravel and Tailwind plugins don't load during tests.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
    },
});
