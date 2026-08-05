/**
 * The finished-run announcement, and the three Vue panels that listen for it.
 *
 * A page that shows fresh scores beside three panels describing the previous
 * run has not finished updating, and looks like it has. The Livewire cards
 * avoid that with `pagespeed-insights:result-stored`; these are the same
 * semantics on the Vue side, reading the same shared announcement the React
 * components read.
 */

import { describe, expect, it } from 'vitest'
import { render, screen, waitFor } from '@testing-library/vue'
import { emitPsiResultStored } from '../../../resources/js/shared/events'
import CoreWebVitalsCard from '../../../resources/js/vue/CoreWebVitalsCard.vue'
import OpportunitiesTable from '../../../resources/js/vue/OpportunitiesTable.vue'
import TrendChart from '../../../resources/js/vue/TrendChart.vue'
import { TEST_URL, opportunitiesPayload, trendPayload, vitalsPayload } from '../fixtures'
import { mockFetch } from '../mock-fetch'

describe( 'CoreWebVitalsCard', () => {
    it( 'reloads when a run for its URL and form factor finishes', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        render( CoreWebVitalsCard, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

        await screen.findByText( '2.1 s' )
        expect( fetch.calls ).toHaveLength( 1 )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run on the other form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        render( CoreWebVitalsCard, {
            props: { url: TEST_URL, strategy: 'mobile', fetchImpl: fetch.impl },
        } )

        await screen.findByText( '2.1 s' )

        // CrUX collects field data per form factor, so a desktop run says
        // nothing about the mobile card.
        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )
        emitPsiResultStored( { url: 'https://example.com/other', strategy: 'mobile', id: 14 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )

    it( 'stops listening once it leaves the page', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        const { unmount } = render( CoreWebVitalsCard, {
            props: { url: TEST_URL, fetchImpl: fetch.impl },
        } )

        await screen.findByText( '2.1 s' )
        unmount()

        // A listener left behind holds its component's closure alive and
        // refreshes a panel that is no longer on the page.
        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )

describe( 'OpportunitiesTable', () => {
    it( 'reloads when a run for its URL and form factor finishes', async () => {
        const fetch = mockFetch( { '/pagespeed/opportunities': { body: opportunitiesPayload() } } )

        render( OpportunitiesTable, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

        await screen.findByText( 'Eliminate render-blocking resources' )
        expect( fetch.calls ).toHaveLength( 1 )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run on the other form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/opportunities': { body: opportunitiesPayload() } } )

        render( OpportunitiesTable, {
            props: { url: TEST_URL, strategy: 'mobile', fetchImpl: fetch.impl },
        } )

        await screen.findByText( 'Eliminate render-blocking resources' )

        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )

describe( 'TrendChart', () => {
    it( 'reloads on a run for its URL on either form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        render( TrendChart, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

        await screen.findByText( '3 measurements of Performance.' )
        expect( fetch.calls ).toHaveLength( 1 )

        // Both form factors are plotted, so either adds a point.
        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run for a different URL', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        render( TrendChart, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

        await screen.findByText( '3 measurements of Performance.' )

        emitPsiResultStored( { url: 'https://example.com/other', strategy: 'mobile', id: 13 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )
