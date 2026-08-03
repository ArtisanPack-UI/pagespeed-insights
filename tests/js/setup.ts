/**
 * Test setup for the React component suite.
 */

import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

afterEach( () => {
    cleanup()
} )
