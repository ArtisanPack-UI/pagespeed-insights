/**
 * The Vue score card, against mocked endpoint responses.
 */

import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/vue'
import ScoreCard from '../../../resources/js/vue/ScoreCard.vue'
import { onPsiResultStored, type PsiResultStored } from '../../../resources/js/shared/events'
import { TEST_URL, resultSummary, scoresPayload } from '../fixtures'
import { mockFetch } from '../mock-fetch'

function renderCard( body: unknown, status = 200 ) {
    const fetch = mockFetch( { '/pagespeed/scores': { status, body } } )

    render( ScoreCard, { props: { url: TEST_URL, fetchImpl: fetch.impl } } )

    return fetch
}

describe( 'ScoreCard', () => {
    it( 'renders the scores it was given, banded', async () => {
        renderCard( scoresPayload() )

        expect( await screen.findByText( '94' ) ).toBeInTheDocument()
        expect( screen.getByText( '72' ) ).toBeInTheDocument()
        expect( screen.getByText( '41' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Good' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Needs improvement' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Poor' ) ).toBeInTheDocument()
    } )

    it( 'renders a category the run never measured as unavailable, not as zero', async () => {
        renderCard( scoresPayload() )

        // The fixture carries no SEO score at all.
        expect( await screen.findByText( 'Unavailable' ) ).toBeInTheDocument()
        expect(
            screen.getByText( 'This run did not return a score for this category.' ),
        ).toBeInTheDocument()
        expect( screen.queryByText( '0' ) ).not.toBeInTheDocument()
    } )

    it( 'asks the endpoint for the URL and form factor it was given', async () => {
        const fetch = mockFetch( {
            '/pagespeed/scores': { body: scoresPayload( { strategy: 'desktop' } ) },
        } )

        render( ScoreCard, {
            props: { url: TEST_URL, strategy: 'desktop', fetchImpl: fetch.impl },
        } )

        await screen.findByText( '94' )

        expect( fetch.calls[ 0 ].url ).toContain( `url=${ encodeURIComponent( TEST_URL ) }` )
        expect( fetch.calls[ 0 ].url ).toContain( 'strategy=desktop' )
    } )

    it( 'honours an endpointBase override', async () => {
        const fetch = mockFetch( { '/admin/psi/scores': { body: scoresPayload() } } )

        render( ScoreCard, {
            props: { url: TEST_URL, endpointBase: '/admin/psi', fetchImpl: fetch.impl },
        } )

        await screen.findByText( '94' )

        expect( fetch.calls[ 0 ].url.startsWith( '/admin/psi/scores' ) ).toBe( true )
    } )

    it( 'says nothing has run yet rather than showing an empty card', async () => {
        renderCard( scoresPayload( { state: 'empty', result: null, scores: [], labMetrics: {} } ) )

        expect(
            await screen.findByText( 'No PageSpeed test has run for this URL yet.' ),
        ).toBeInTheDocument()
    } )

    it( 'offers setup instructions when no API key is configured', async () => {
        renderCard(
            scoresPayload( {
                state: 'empty',
                apiKeyConfigured: false,
                result: null,
                scores: [],
                labMetrics: {},
            } ),
        )

        expect( await screen.findByText( 'No PageSpeed API key is configured' ) ).toBeInTheDocument()
        expect( screen.getByText( 'PAGESPEED_API_KEY=your-key-here' ) ).toBeInTheDocument()
        expect( screen.getByRole( 'button', { name: 'Run test' } ) ).toBeDisabled()
    } )

    it( 'reports a failed run with the error the server stored', async () => {
        renderCard(
            scoresPayload( {
                state: 'failed',
                result: resultSummary( {
                    status: 'failed',
                    errorMessage: 'Lighthouse returned an error.',
                } ),
                scores: [],
                labMetrics: {},
            } ),
        )

        expect( await screen.findByText( 'The last PageSpeed run failed' ) ).toBeInTheDocument()
        expect( screen.getByText( 'Lighthouse returned an error.' ) ).toBeInTheDocument()
    } )

    it( 'separates a degraded run from a clean one and lists its warnings', async () => {
        renderCard(
            scoresPayload( {
                state: 'degraded',
                result: resultSummary( {
                    degraded: true,
                    warnings: [ 'The SEO category was not returned.' ],
                } ),
            } ),
        )

        expect(
            await screen.findByText( 'The last run completed with data missing' ),
        ).toBeInTheDocument()
        expect( screen.getByText( 'The SEO category was not returned.' ) ).toBeInTheDocument()
        expect( screen.getByText( '94' ) ).toBeInTheDocument()
    } )

    it( 'renders the endpoint refusal rather than a blank card', async () => {
        renderCard(
            { error: 'url_not_monitored', message: 'This installation does not monitor that URL.' },
            403,
        )

        expect( await screen.findByText( 'These scores could not be loaded' ) ).toBeInTheDocument()
        expect(
            screen.getByText( 'This installation does not monitor that URL.' ),
        ).toBeInTheDocument()
    } )

    it( 'announces a finished run so the sibling panels stop showing the previous one', async () => {
        const heard: PsiResultStored[] = []
        const stop = onPsiResultStored( ( event ) => heard.push( event ) )

        const fetch = mockFetch( {
            '/pagespeed/scores': { body: scoresPayload() },
            '/pagespeed/test': {
                status: 202,
                body: { id: 'ticket-1', status: 'queued', url: TEST_URL, strategy: 'mobile' },
            },
            '/pagespeed/results/': {
                body: { id: 42, status: 'completed', url: TEST_URL, strategy: 'mobile', result: null },
            },
        } )

        const { emitted } = render( ScoreCard, {
            props: { url: TEST_URL, fetchImpl: fetch.impl, csrfToken: 'token' },
        } )

        await screen.findByText( '94' )
        await fireEvent.click( screen.getByRole( 'button', { name: 'Run test' } ) )

        // The card polls the ticket rather than the scores endpoint, so the
        // announcement waits on a poll landing.
        await waitFor( () => expect( heard ).toHaveLength( 1 ), { timeout: 10000 } )

        expect( heard[ 0 ] ).toEqual( { url: TEST_URL, strategy: 'mobile', id: 42 } )
        // The same news reaches the surrounding page as a component event.
        expect( emitted()[ 'result-stored' ] ).toEqual( [ [ 42 ] ] )

        stop()
    }, 15000 )

    it( 'announces the run it queued, not whatever the card is showing when it lands', async () => {
        const heard: PsiResultStored[] = []
        const stop = onPsiResultStored( ( event ) => heard.push( event ) )

        const fetch = mockFetch( {
            '/pagespeed/scores': { body: scoresPayload() },
            '/pagespeed/test': {
                status: 202,
                body: { id: 'ticket-3', status: 'queued', url: TEST_URL, strategy: 'mobile' },
            },
            '/pagespeed/results/': {
                body: { id: 42, status: 'completed', url: TEST_URL, strategy: 'mobile', result: null },
            },
        } )

        const { rerender } = render( ScoreCard, {
            props: {
                url: TEST_URL,
                strategy: 'mobile',
                fetchImpl: fetch.impl,
                csrfToken: 'token',
            },
        } )

        await screen.findByText( '94' )
        await fireEvent.click( screen.getByRole( 'button', { name: 'Run test' } ) )

        // The surrounding page moves its selector while the run is in flight.
        await rerender( {
            url: 'https://example.com/other',
            strategy: 'desktop',
            fetchImpl: fetch.impl,
            csrfToken: 'token',
        } )

        await waitFor( () => expect( heard ).toHaveLength( 1 ), { timeout: 10000 } )

        // The announcement names the page the run was queued for. Naming the
        // new one would refresh three panels for a run that was not theirs, and
        // leave the panels that were waiting on it stale.
        expect( heard[ 0 ] ).toEqual( { url: TEST_URL, strategy: 'mobile', id: 42 } )

        stop()
    }, 15000 )

    it( 'announces nothing when the queued run never reports back', async () => {
        const heard: PsiResultStored[] = []
        const stop = onPsiResultStored( ( event ) => heard.push( event ) )

        const fetch = mockFetch( {
            '/pagespeed/scores': { body: scoresPayload() },
            '/pagespeed/test': {
                status: 202,
                body: { id: 'ticket-2', status: 'queued', url: TEST_URL, strategy: 'mobile' },
            },
            '/pagespeed/results/': {
                body: {
                    id: 'ticket-2',
                    status: 'timed-out',
                    url: TEST_URL,
                    strategy: 'mobile',
                    result: null,
                },
            },
        } )

        render( ScoreCard, {
            props: { url: TEST_URL, fetchImpl: fetch.impl, csrfToken: 'token' },
        } )

        await screen.findByText( '94' )
        await fireEvent.click( screen.getByRole( 'button', { name: 'Run test' } ) )

        expect(
            await screen.findByText( 'The test has not reported back', {}, { timeout: 10000 } ),
        ).toBeInTheDocument()

        // No row appeared, so refreshing three panels to show the data they
        // already show would be noise.
        expect( heard ).toHaveLength( 0 )

        stop()
    }, 15000 )
} )
