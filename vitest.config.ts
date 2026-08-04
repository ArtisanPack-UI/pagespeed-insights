/**
 * Vitest configuration for the React and Vue components.
 *
 * The components are shipped as source rather than built — a host application
 * publishes `resources/js` into its own pipeline — so there is no build step
 * here, only a test run against the sources themselves.
 */

import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import vue from '@vitejs/plugin-vue'

export default defineConfig( {
    plugins: [ react(), vue() ],
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: [ './tests/js/setup.ts' ],
        include: [ 'tests/js/**/*.test.{ts,tsx}' ],
    },
} )
