/**
 * The React trend chart, against mocked endpoint responses.
 */

import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { TrendChart } from '../../resources/js/react/TrendChart'
import { TEST_URL, trendPayload } from './fixtures'
import { mockFetch } from './mock-fetch'

function renderChart( body: unknown, status = 200 ) {
    const fetch = mockFetch( { '/pagespeed/trends': { status, body } } )

    render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

    return fetch
}

describe( 'TrendChart', () => {
    it( 'draws the series and reports how many measurements it holds', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )
        const { container } = render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        expect( await screen.findByText( '3 measurements of Performance.' ) ).toBeInTheDocument()
        expect( container.querySelector( 'svg' ) ).not.toBeNull()
    } )

    it( 'breaks the line at a gap rather than joining across it', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )
        const { container } = render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( '3 measurements of Performance.' )

        // The fixture's middle point is null, so the two surviving points on
        // its right form one polyline and the stranded point on its left forms
        // none — it is drawn as a marker instead.
        expect( container.querySelectorAll( 'polyline' ) ).toHaveLength( 1 )
        expect( container.querySelectorAll( 'circle' ) ).toHaveLength( 3 )
        expect( screen.getByText( 'The line has gaps' ) ).toBeInTheDocument()
    } )

    it( 'refuses to draw a single measurement as a trend', async () => {
        renderChart(
            trendPayload( { state: 'insufficient', series: [], pointCount: 1, hasGaps: false } ),
        )

        expect( await screen.findByText( 'Not enough history yet' ) ).toBeInTheDocument()
        expect(
            screen.getByText( /There is only one measurement of Performance so far/ ),
        ).toBeInTheDocument()
    } )

    it( 'points at a wider range when the history sits outside this one', async () => {
        renderChart(
            trendPayload( {
                state: 'insufficient',
                series: [],
                pointCount: 1,
                hasOlderHistory: true,
            } ),
        )

        expect(
            await screen.findByText( /Choose a wider range to include older results/ ),
        ).toBeInTheDocument()
    } )

    it( 'separates "no history at all" from "none in this range"', async () => {
        renderChart( trendPayload( { state: 'out-of-range', series: [], pointCount: 0 } ) )

        expect( await screen.findByText( 'Nothing tested in this range' ) ).toBeInTheDocument()
    } )

    it( 'says nothing has run yet rather than drawing an empty chart', async () => {
        renderChart( trendPayload( { state: 'empty', series: [], pointCount: 0 } ) )

        expect(
            await screen.findByText( /No PageSpeed test has run for this URL yet/ ),
        ).toBeInTheDocument()
    } )

    it( 'states when the range was truncated rather than shortening it quietly', async () => {
        renderChart( trendPayload( { truncated: true } ) )

        expect( await screen.findByText( 'Showing the most recent results only' ) ).toBeInTheDocument()
    } )

    it( 'refetches when the metric selector changes', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( '3 measurements of Performance.' )

        fireEvent.change( screen.getByLabelText( 'Metric' ), { target: { value: 'seo' } } )

        await waitFor( () => {
            expect( fetch.calls.some( ( call ) => call.url.includes( 'metric=seo' ) ) ).toBe( true )
        } )
    } )

    it( 'renders the endpoint refusal rather than a blank chart', async () => {
        renderChart( { error: 'url_not_monitored', message: 'This installation does not monitor that URL.' }, 403 )

        expect( await screen.findByText( 'This trend could not be loaded' ) ).toBeInTheDocument()
    } )
} )
