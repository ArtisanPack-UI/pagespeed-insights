/**
 * The shared fetch layer, on its own.
 *
 * These are the pieces the Vue components consume too, so they are tested
 * without a framework in the room.
 */

import { describe, expect, it, vi } from 'vitest'
import {
    DEFAULT_ENDPOINT_BASE,
    ERROR_NO_FETCH,
    PageSpeedInsightsError,
    psiRequest,
} from '../../resources/js/shared/client'
import {
    bandColor,
    bandLabel,
    formatSavings,
    formatVital,
    metricLabel,
    normalizeStrategy,
    strategyLabel,
    vitalAbbreviation,
} from '../../resources/js/shared/labels'
import { fetchPsiScores } from '../../resources/js/shared/scores'
import { fetchPsiTrend } from '../../resources/js/shared/trends'
import { createPsiUrl, deletePsiUrl } from '../../resources/js/shared/urls'
import { scoresPayload, TEST_URL, trendPayload } from './fixtures'
import { mockFetch } from './mock-fetch'

describe( 'psiRequest', () => {
    it( 'mounts requests under the documented prefix by default', async () => {
        const fetch = mockFetch( { '/pagespeed/scores': { body: scoresPayload() } } )

        await fetchPsiScores( { url: TEST_URL, fetchImpl: fetch.impl } )

        expect( DEFAULT_ENDPOINT_BASE ).toBe( '/pagespeed' )
        expect( fetch.calls[ 0 ].url.startsWith( '/pagespeed/scores?' ) ).toBe( true )
    } )

    it( 'drops a trailing slash on the endpoint base rather than doubling it', async () => {
        const fetch = mockFetch( { '/admin/psi/scores': { body: scoresPayload() } } )

        await fetchPsiScores( { url: TEST_URL, endpointBase: '/admin/psi/', fetchImpl: fetch.impl } )

        expect( fetch.calls[ 0 ].url.startsWith( '/admin/psi/scores?' ) ).toBe( true )
    } )

    it( 'omits a parameter that was not supplied rather than sending it empty', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        await fetchPsiTrend( { url: TEST_URL, fetchImpl: fetch.impl } )

        // An absent strategy means "every form factor". Sending `strategy=`
        // would ask the server a different question.
        expect( fetch.calls[ 0 ].url ).not.toContain( 'strategy=' )
    } )

    it( 'raises the endpoint error code alongside its message', async () => {
        const fetch = mockFetch( {
            '/pagespeed/scores': {
                status: 403,
                body: { error: 'url_not_monitored', message: 'This installation does not monitor that URL.' },
            },
        } )

        await expect( fetchPsiScores( { url: TEST_URL, fetchImpl: fetch.impl } ) ).rejects.toMatchObject( {
            code: 'url_not_monitored',
            status: 403,
            message: 'This installation does not monitor that URL.',
        } )
    } )

    it( 'carries the field a 422 named', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': {
                status: 422,
                body: { error: 'invalid_attribute', field: 'label', message: 'A label must be text.' },
            },
        } )

        await expect(
            createPsiUrl( TEST_URL, { label: 'x' }, { fetchImpl: fetch.impl, csrfToken: null } ),
        ).rejects.toMatchObject( { code: 'invalid_attribute', field: 'label' } )
    } )

    it( 'falls back to a status-based message when the body is not JSON', async () => {
        const fetch = mockFetch( { '/pagespeed/scores': { status: 500 } } )

        await expect( fetchPsiScores( { url: TEST_URL, fetchImpl: fetch.impl } ) ).rejects.toMatchObject( {
            code: 'http_error',
            status: 500,
        } )
    } )

    it( 'accepts an empty 204 without asking it for JSON', async () => {
        const fetch = mockFetch( { '/pagespeed/urls/7': { status: 204 } } )

        await expect(
            deletePsiUrl( 7, { fetchImpl: fetch.impl, csrfToken: 'token' } ),
        ).resolves.toBeUndefined()
    } )

    it( 'sends the CSRF token on writes and not on reads', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': { body: { id: 1 } },
            '/pagespeed/scores': { body: scoresPayload() },
        } )

        await createPsiUrl( TEST_URL, {}, { fetchImpl: fetch.impl, csrfToken: 'token-abc' } )
        await fetchPsiScores( { url: TEST_URL, fetchImpl: fetch.impl } )

        expect( fetch.calls[ 0 ].headers[ 'X-CSRF-TOKEN' ] ).toBe( 'token-abc' )
        expect( fetch.calls[ 1 ].headers[ 'X-CSRF-TOKEN' ] ).toBeUndefined()
    } )

    it( 'explains itself when there is no fetch to call', async () => {
        // An SSR runtime with no global fetch and no injected one. The layer
        // says so rather than throwing a TypeError from somewhere deeper.
        vi.stubGlobal( 'fetch', undefined )

        try {
            await expect( psiRequest( '/scores' ) ).rejects.toMatchObject( {
                code: ERROR_NO_FETCH,
                status: 0,
            } )
            await expect( psiRequest( '/scores' ) ).rejects.toBeInstanceOf( PageSpeedInsightsError )
        } finally {
            vi.unstubAllGlobals()
        }
    } )
} )

describe( 'labels', () => {
    it( 'maps each band to a theme colour and a name', () => {
        expect( bandColor( 'good' ) ).toBe( 'success' )
        expect( bandColor( 'needs-improvement' ) ).toBe( 'warning' )
        expect( bandColor( 'poor' ) ).toBe( 'error' )
        expect( bandColor( null ) ).toBeUndefined()

        expect( bandLabel( 'good' ) ).toBe( 'Good' )
        expect( bandLabel( null ) ).toBeNull()
    } )

    it( 'writes each vital in the unit a reader expects', () => {
        // CrUX reports CLS multiplied by 100, so 4 is a CLS of 0.04.
        expect( formatVital( 'largest_contentful_paint', 2100 ) ).toBe( '2.1 s' )
        expect( formatVital( 'interaction_to_next_paint', 350 ) ).toBe( '350 ms' )
        expect( formatVital( 'cumulative_layout_shift', 4 ) ).toBe( '0.04' )
        expect( formatVital( 'cumulative_layout_shift', null ) ).toBeNull()
    } )

    it( 'keeps sub-second savings in milliseconds', () => {
        expect( formatSavings( 400 ) ).toBe( '400 ms' )
        expect( formatSavings( 1240 ) ).toBe( '1.2 s' )
        expect( formatSavings( 0 ) ).toBeNull()
        expect( formatSavings( null ) ).toBeNull()
    } )

    it( 'names the metrics and form factors the endpoints send as keys', () => {
        expect( metricLabel( 'best-practices' ) ).toBe( 'Best practices' )
        expect( metricLabel( 'speed-index' ) ).toBe( 'Speed Index' )
        // An unrecognised key is still a metric worth plotting.
        expect( metricLabel( 'something-new' ) ).toBe( 'something-new' )

        expect( vitalAbbreviation( 'interaction_to_next_paint' ) ).toBe( 'INP' )
        expect( strategyLabel( 'desktop' ) ).toBe( 'Desktop' )
    } )

    it( 'reduces an unknown form factor to mobile, as the server does', () => {
        expect( normalizeStrategy( 'DESKTOP' ) ).toBe( 'desktop' )
        expect( normalizeStrategy( 'tablet' ) ).toBe( 'mobile' )
        expect( normalizeStrategy( null ) ).toBe( 'mobile' )
    } )
} )
