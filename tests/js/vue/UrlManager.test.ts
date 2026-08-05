/**
 * The Vue monitored URL manager, against mocked endpoint responses.
 */

import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import UrlManager from '../../../resources/js/vue/UrlManager.vue'
import { TEST_URL, urlsPayload } from '../fixtures'
import { mockFetch } from '../mock-fetch'

describe( 'UrlManager', () => {
    it( 'lists the monitored set', async () => {
        const fetch = mockFetch( { '/pagespeed/urls': { body: urlsPayload() } } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        expect( await screen.findByText( 'Pricing' ) ).toBeInTheDocument()
        expect( screen.getByText( TEST_URL ) ).toBeInTheDocument()
        expect( screen.getByText( 'https://example.com/blog' ) ).toBeInTheDocument()
        expect( screen.getByText( '2 URLs monitored' ) ).toBeInTheDocument()
    } )

    it( 'reports a hook-contributed row as belonging to another package', async () => {
        const fetch = mockFetch( { '/pagespeed/urls': { body: urlsPayload() } } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        expect( await screen.findByText( 'Registered by another package' ) ).toBeInTheDocument()
        // The stored row is removable; the contributed one is not.
        expect( screen.getAllByRole( 'button', { name: 'Remove' } ) ).toHaveLength( 1 )
    } )

    it( 'says the set is empty rather than showing a blank table', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': { body: urlsPayload( { urls: [], total: 0 } ) },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        expect( await screen.findByText( /No URLs are monitored yet/ ) ).toBeInTheDocument()
    } )

    it( 'posts a new URL and reloads the list', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': ( call ) =>
                'POST' === call.method
                    ? {
                          status: 201,
                          body: {
                              id: 3,
                              url: 'https://example.com/about',
                              label: 'About',
                              source: 'manual',
                              editable: true,
                              isActive: true,
                              strategies: [ 'mobile' ],
                              testFrequency: null,
                              frequency: 'daily',
                              lastTestedAt: null,
                          },
                      }
                    : { body: urlsPayload() },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl, csrfToken: 'token-123' } } )

        await screen.findByText( 'Pricing' )

        await fireEvent.update( screen.getByLabelText( 'URL' ), 'https://example.com/about' )
        await fireEvent.update( screen.getByLabelText( 'Label' ), 'About' )
        await fireEvent.click( screen.getByRole( 'button', { name: 'Add URL' } ) )

        expect( await screen.findByText( 'URL added' ) ).toBeInTheDocument()

        const post = fetch.calls.find( ( call ) => 'POST' === call.method )

        expect( post ).toBeDefined()
        expect( post?.body ).toEqual( { url: 'https://example.com/about', label: 'About' } )
        expect( post?.headers[ 'X-CSRF-TOKEN' ] ).toBe( 'token-123' )
    } )

    it( 'shows the endpoint refusal when a URL cannot be added', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': ( call ) =>
                'POST' === call.method
                    ? {
                          status: 409,
                          body: {
                              error: 'already_monitored',
                              message: '"https://example.com/pricing" is already monitored.',
                          },
                      }
                    : { body: urlsPayload() },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        await screen.findByText( 'Pricing' )

        await fireEvent.update( screen.getByLabelText( 'URL' ), TEST_URL )
        await fireEvent.click( screen.getByRole( 'button', { name: 'Add URL' } ) )

        expect( await screen.findByText( 'That URL could not be added' ) ).toBeInTheDocument()
        expect(
            screen.getByText( '"https://example.com/pricing" is already monitored.' ),
        ).toBeInTheDocument()
    } )

    it( 'asks before removing a URL, then deletes it', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls/1': { status: 204 },
            '/pagespeed/urls': { body: urlsPayload() },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        await screen.findByText( 'Pricing' )

        await fireEvent.click( screen.getByRole( 'button', { name: 'Remove' } ) )

        // Nothing has been sent yet — the first click only asks.
        expect( screen.getByText( 'Stop monitoring this URL?' ) ).toBeInTheDocument()
        expect( fetch.calls.some( ( call ) => 'DELETE' === call.method ) ).toBe( false )

        await fireEvent.click( screen.getByRole( 'button', { name: 'Remove' } ) )

        await waitFor( () => {
            expect( fetch.calls.some( ( call ) => 'DELETE' === call.method ) ).toBe( true )
        } )

        expect( await screen.findByText( 'URL removed' ) ).toBeInTheDocument()
    } )

    it( 'states when the listing was truncated rather than showing a prefix quietly', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': { body: urlsPayload( { total: 400, truncated: true } ) },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        expect( await screen.findByText( 'Showing the first 250 URLs' ) ).toBeInTheDocument()
    } )

    it( 'renders the endpoint refusal rather than a blank table', async () => {
        const fetch = mockFetch( {
            '/pagespeed/urls': {
                status: 403,
                body: { error: 'unauthenticated', message: 'You are not signed in.' },
            },
        } )

        render( UrlManager, { props: { fetchImpl: fetch.impl } } )

        expect(
            await screen.findByText( 'The monitored set could not be loaded' ),
        ).toBeInTheDocument()
    } )
} )
