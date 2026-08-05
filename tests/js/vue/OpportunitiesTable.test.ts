/**
 * The Vue opportunities table, against mocked endpoint responses.
 */

import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/vue'
import OpportunitiesTable from '../../../resources/js/vue/OpportunitiesTable.vue'
import { TEST_URL, opportunitiesPayload, resultSummary } from '../fixtures'
import { mockFetch } from '../mock-fetch'

function renderTable( body: unknown, status = 200 ) {
    const fetch = mockFetch( { '/pagespeed/opportunities': { status, body } } )

    render( OpportunitiesTable, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

    return fetch
}

describe( 'OpportunitiesTable', () => {
    it( 'lists the opportunities with their estimated savings', async () => {
        renderTable( opportunitiesPayload() )

        expect( await screen.findByText( 'Eliminate render-blocking resources' ) ).toBeInTheDocument()
        expect( screen.getByText( '1.2 s' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Potential savings of 1,240 ms' ) ).toBeInTheDocument()
        expect( screen.getByText( '38' ) ).toBeInTheDocument()
    } )

    it( 'renders an audit with no estimate as an em dash rather than as zero', async () => {
        renderTable( opportunitiesPayload() )

        expect( await screen.findByText( 'Reduce unused CSS' ) ).toBeInTheDocument()
        expect( screen.getByText( '—' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Unscored' ) ).toBeInTheDocument()
    } )

    it( 'separates "performance was not measured" from "nothing to fix"', async () => {
        renderTable( opportunitiesPayload( { state: 'not-measured', opportunities: [] } ) )

        expect( await screen.findByText( 'Performance was not measured' ) ).toBeInTheDocument()
        expect( screen.queryByText( 'No opportunities found' ) ).not.toBeInTheDocument()
    } )

    it( 'reports a clean page as good news', async () => {
        renderTable( opportunitiesPayload( { state: 'none', opportunities: [] } ) )

        expect( await screen.findByText( 'No opportunities found' ) ).toBeInTheDocument()
    } )

    it( 'says nothing has run yet rather than showing an empty table', async () => {
        renderTable( opportunitiesPayload( { state: 'empty', result: null, opportunities: [] } ) )

        expect(
            await screen.findByText( 'No PageSpeed test has run for this URL yet.' ),
        ).toBeInTheDocument()
    } )

    it( 'reports a failed run with the error the server stored', async () => {
        renderTable(
            opportunitiesPayload( {
                state: 'failed',
                result: resultSummary( { status: 'failed', errorMessage: 'Quota exceeded.' } ),
                opportunities: [],
            } ),
        )

        expect( await screen.findByText( 'The last PageSpeed run failed' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Quota exceeded.' ) ).toBeInTheDocument()
    } )

    it( 'renders the endpoint refusal rather than a blank table', async () => {
        renderTable(
            { error: 'url_not_monitored', message: 'This installation does not monitor that URL.' },
            403,
        )

        expect(
            await screen.findByText( 'These opportunities could not be loaded' ),
        ).toBeInTheDocument()
    } )
} )
