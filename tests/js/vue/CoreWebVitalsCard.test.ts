/**
 * The Vue Core Web Vitals card, against mocked endpoint responses.
 */

import { describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/vue'
import CoreWebVitalsCard from '../../../resources/js/vue/CoreWebVitalsCard.vue'
import { TEST_URL, resultSummary, vitalsPayload } from '../fixtures'
import { mockFetch } from '../mock-fetch'

function renderCard( body: unknown, status = 200 ) {
    const fetch = mockFetch( { '/pagespeed/core-web-vitals': { status, body } } )

    render( CoreWebVitalsCard, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

    return fetch
}

describe( 'CoreWebVitalsCard', () => {
    it( 'renders each vital in the unit a reader expects', async () => {
        renderCard( vitalsPayload() )

        // LCP arrives in milliseconds and reads in seconds; CLS arrives
        // multiplied by 100 and reads as the unitless score it started as.
        expect( await screen.findByText( '2.1 s' ) ).toBeInTheDocument()
        expect( screen.getByText( '350 ms' ) ).toBeInTheDocument()
        expect( screen.getByText( '0.04' ) ).toBeInTheDocument()
        expect( screen.getByText( 'LCP' ) ).toBeInTheDocument()
        expect( screen.getByText( 'INP' ) ).toBeInTheDocument()
        expect( screen.getByText( 'CLS' ) ).toBeInTheDocument()
    } )

    it( 'states the percentile the numbers are reported at', async () => {
        renderCard( vitalsPayload() )

        expect(
            await screen.findByText(
                'Real-user data from the Chrome UX Report, reported at the 75th percentile.',
            ),
        ).toBeInTheDocument()
    } )

    it( 'reads the new subject when the page moves its selector', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        const { rerender } = render( CoreWebVitalsCard, {
            props: { url: TEST_URL, strategy: 'mobile', fetchImpl: fetch.impl },
        } )

        await screen.findByText( '2.1 s' )
        expect( fetch.calls ).toHaveLength( 1 )

        // `usePsiResource` watches an explicit dependency getter rather than
        // inferring one, so a prop missing from that list is a panel that
        // silently keeps describing the URL it was mounted with.
        await rerender( { url: 'https://example.com/other', strategy: 'mobile' } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
        expect( fetch.calls[ 1 ].url ).toContain(
            `url=${ encodeURIComponent( 'https://example.com/other' ) }`,
        )

        await rerender( { url: 'https://example.com/other', strategy: 'desktop' } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 3 ) )
        expect( fetch.calls[ 2 ].url ).toContain( 'strategy=desktop' )
    } )

    it( 'separates "no field data" from "origin-level data"', async () => {
        renderCard( vitalsPayload( { state: 'no-field-data', page: null, origin: null } ) )

        expect( await screen.findByText( 'Not enough field data' ) ).toBeInTheDocument()
        expect( screen.queryByText( 'Showing site-wide data' ) ).not.toBeInTheDocument()
    } )

    it( 'labels origin-level numbers as describing the site rather than the page', async () => {
        const payload = vitalsPayload( { state: 'origin-level', page: null } )

        payload.origin = {
            id: 'https://example.com',
            overallCategory: 'SLOW',
            originFallback: false,
            originLevel: true,
            vitals: [
                { metric: 'largest_contentful_paint', value: 4300, band: 'poor', category: 'SLOW' },
                { metric: 'interaction_to_next_paint', value: null, band: null, category: null },
                {
                    metric: 'cumulative_layout_shift',
                    value: 12,
                    band: 'needs-improvement',
                    category: 'AVERAGE',
                },
            ],
        }

        renderCard( payload )

        expect( await screen.findByText( 'Showing site-wide data' ) ).toBeInTheDocument()
        expect(
            screen.getByText( /these numbers describe https:\/\/example\.com as a whole/ ),
        ).toBeInTheDocument()
        expect( screen.getByText( '4.3 s' ) ).toBeInTheDocument()
        // A vital CrUX had no measurement for is an em dash, never a zero.
        expect( screen.getByText( 'No data' ) ).toBeInTheDocument()
    } )

    it( 'says nothing has run yet rather than showing an empty card', async () => {
        renderCard( vitalsPayload( { state: 'empty', result: null, page: null, origin: null } ) )

        expect(
            await screen.findByText( 'No PageSpeed test has run for this URL yet.' ),
        ).toBeInTheDocument()
    } )

    it( 'reports a failed run with the error the server stored', async () => {
        renderCard(
            vitalsPayload( {
                state: 'failed',
                result: resultSummary( { status: 'failed', errorMessage: 'The request timed out.' } ),
                page: null,
                origin: null,
            } ),
        )

        expect( await screen.findByText( 'The last PageSpeed run failed' ) ).toBeInTheDocument()
        expect( screen.getByText( 'The request timed out.' ) ).toBeInTheDocument()
    } )

    it( 'renders the endpoint refusal rather than a blank card', async () => {
        renderCard(
            { error: 'invalid_url', message: 'Supply a full http:// or https:// address.' },
            422,
        )

        expect( await screen.findByText( 'This field data could not be loaded' ) ).toBeInTheDocument()
        expect(
            screen.getByText( 'Supply a full http:// or https:// address.' ),
        ).toBeInTheDocument()
    } )
} )
