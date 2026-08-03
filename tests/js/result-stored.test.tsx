/**
 * The finished-run announcement, and the three panels that listen for it.
 *
 * A page that shows fresh scores beside three panels describing the previous
 * run has not finished updating, and looks like it has. The Livewire cards
 * avoid that with `pagespeed-insights:result-stored`; these are the same
 * semantics on the React side.
 */

import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import {
    PSI_EVENT_RESULT_STORED,
    emitPsiResultStored,
    onPsiResultStored,
} from '../../resources/js/shared/events'
import { CoreWebVitalsCard } from '../../resources/js/react/CoreWebVitalsCard'
import { OpportunitiesTable } from '../../resources/js/react/OpportunitiesTable'
import { TrendChart } from '../../resources/js/react/TrendChart'
import {
    TEST_URL,
    opportunitiesPayload,
    trendPayload,
    vitalsPayload,
} from './fixtures'
import { mockFetch } from './mock-fetch'

describe( 'the finished-run announcement', () => {
    it( 'reaches every listener and carries the run it describes', () => {
        const heard: unknown[] = []
        const stop = onPsiResultStored( ( event ) => heard.push( event ) )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 12 } )

        expect( heard ).toEqual( [ { url: TEST_URL, strategy: 'mobile', id: 12 } ] )

        stop()
        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        // Unsubscribing means unsubscribed: a listener left behind refreshes a
        // panel that is no longer on the page.
        expect( heard ).toHaveLength( 1 )
    } )

    it( 'does not let one failing listener silence the others', () => {
        const heard: unknown[] = []
        const consoleError = vi.spyOn( console, 'error' ).mockImplementation( () => {} )

        const stopFirst = onPsiResultStored( () => {
            throw new Error( 'boom' )
        } )
        const stopSecond = onPsiResultStored( ( event ) => heard.push( event ) )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 12 } )

        expect( heard ).toHaveLength( 1 )
        expect( consoleError ).toHaveBeenCalled()

        stopFirst()
        stopSecond()
        consoleError.mockRestore()
    } )

    it( 'also dispatches a window event, for a surface built with neither component set', () => {
        const heard: unknown[] = []
        const listener = ( event: Event ) => heard.push( ( event as CustomEvent ).detail )

        window.addEventListener( PSI_EVENT_RESULT_STORED, listener )
        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 'ticket-1' } )
        window.removeEventListener( PSI_EVENT_RESULT_STORED, listener )

        expect( heard ).toEqual( [ { url: TEST_URL, strategy: 'desktop', id: 'ticket-1' } ] )
    } )
} )

describe( 'CoreWebVitalsCard', () => {
    it( 'reloads when a run for its URL and form factor finishes', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        render( <CoreWebVitalsCard url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( '2.1 s' )
        expect( fetch.calls ).toHaveLength( 1 )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run on the other form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/core-web-vitals': { body: vitalsPayload() } } )

        render( <CoreWebVitalsCard url={ TEST_URL } strategy="mobile" fetchImpl={ fetch.impl } /> )

        await screen.findByText( '2.1 s' )

        // CrUX collects field data per form factor, so a desktop run says
        // nothing about the mobile card.
        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )
        emitPsiResultStored( { url: 'https://example.com/other', strategy: 'mobile', id: 14 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )

describe( 'OpportunitiesTable', () => {
    it( 'reloads when a run for its URL and form factor finishes', async () => {
        const fetch = mockFetch( { '/pagespeed/opportunities': { body: opportunitiesPayload() } } )

        render( <OpportunitiesTable url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( 'Eliminate render-blocking resources' )
        expect( fetch.calls ).toHaveLength( 1 )

        emitPsiResultStored( { url: TEST_URL, strategy: 'mobile', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run on the other form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/opportunities': { body: opportunitiesPayload() } } )

        render( <OpportunitiesTable url={ TEST_URL } strategy="mobile" fetchImpl={ fetch.impl } /> )

        await screen.findByText( 'Eliminate render-blocking resources' )

        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )

describe( 'TrendChart', () => {
    it( 'reloads on a run for its URL on either form factor', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( '3 measurements of Performance.' )
        expect( fetch.calls ).toHaveLength( 1 )

        // Both form factors are plotted, so either adds a point.
        emitPsiResultStored( { url: TEST_URL, strategy: 'desktop', id: 13 } )

        await waitFor( () => expect( fetch.calls ).toHaveLength( 2 ) )
    } )

    it( 'ignores a run for a different URL', async () => {
        const fetch = mockFetch( { '/pagespeed/trends': { body: trendPayload() } } )

        render( <TrendChart url={ TEST_URL } fetchImpl={ fetch.impl } /> )

        await screen.findByText( '3 measurements of Performance.' )

        emitPsiResultStored( { url: 'https://example.com/other', strategy: 'mobile', id: 13 } )

        await new Promise( ( resolve ) => setTimeout( resolve, 20 ) )

        expect( fetch.calls ).toHaveLength( 1 )
    } )
} )
