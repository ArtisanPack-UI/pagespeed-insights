/**
 * Test setup for the React and Vue component suites.
 *
 * Both libraries' `cleanup` runs after every test rather than only the one the
 * file under test uses: the two suites share one jsdom document, and a
 * component left mounted by one keeps answering the queries made by the next.
 */

import '@testing-library/jest-dom/vitest'
import { cleanup as cleanupReact } from '@testing-library/react'
import { cleanup as cleanupVue } from '@testing-library/vue'
import { afterEach } from 'vitest'

afterEach( () => {
    cleanupReact()
    cleanupVue()
} )
