/**
 * A fetch stand-in that answers from a routing table.
 *
 * Every component takes a `fetchImpl` prop precisely so the suite never has to
 * patch a global, which keeps two tests running side by side from answering
 * each other's requests.
 */

import { vi } from 'vitest'

/**
 * One canned answer.
 */
export interface MockResponse {
    status?: number
    body?: unknown
}

/**
 * The routes a mock answers, keyed by the path the component asks for.
 *
 * A key matches when the requested URL's path starts with it, so
 * `/pagespeed/scores` answers `/pagespeed/scores?url=…&strategy=mobile`.
 */
export type MockRoutes = Record<string, MockResponse | ( ( request: MockCall ) => MockResponse )>

/**
 * One recorded request.
 */
export interface MockCall {
    url: string
    method: string
    headers: Record<string, string>
    body: unknown
}

/**
 * Build a fetch stand-in, along with the log of what it was asked for.
 */
export function mockFetch( routes: MockRoutes ) {
    const calls: MockCall[] = []

    const impl = vi.fn( async ( input: RequestInfo | URL, init?: RequestInit ) => {
        const url = String( input )
        const path = url.split( '?' )[ 0 ]

        const call: MockCall = {
            url,
            method: init?.method ?? 'GET',
            headers: ( init?.headers as Record<string, string> ) ?? {},
            body: 'string' === typeof init?.body ? JSON.parse( init.body ) : null,
        }

        calls.push( call )

        const matched = Object.keys( routes )
            .sort( ( a, b ) => b.length - a.length )
            .find( ( key ) => path.startsWith( key ) )

        if ( undefined === matched ) {
            throw new Error( `No mock route matches ${ url }` )
        }

        const route = routes[ matched ]
        const answer = 'function' === typeof route ? route( call ) : route
        const status = answer.status ?? 200

        return {
            ok: status >= 200 && status < 300,
            status,
            json: async () => {
                if ( undefined === answer.body ) {
                    throw new Error( 'No body' )
                }

                return answer.body
            },
        } as Response
    } )

    return { impl: impl as unknown as typeof fetch, calls, spy: impl }
}
