/**
 * The load lifecycle every component in this package shares.
 */

import { useCallback, useEffect, useState } from 'react'
import { PageSpeedInsightsError, isAbortError } from '../shared/client'

/**
 * What {@link usePsiResource} hands back.
 *
 * @typeParam T - The payload the endpoint serves.
 */
export interface PsiResource<T> {
    /** The most recent payload, or null before the first one arrives. */
    data: T | null
    /** The refusal the endpoint answered with, or null. */
    error: PageSpeedInsightsError | null
    /** Whether a request is in flight. */
    loading: boolean
    /** Ask for the payload again. */
    reload: () => void
}

/**
 * Fetch a payload, keeping the previous one on screen while the next loads.
 *
 * The old payload is deliberately not cleared when a reload starts: a card that
 * blanks itself every five seconds while polling is harder to read than one
 * that updates in place, and a reload that fails leaves the reader with the
 * numbers they already had plus an explanation.
 *
 * @typeParam T - The payload the endpoint serves.
 *
 * @param load - Runs the request. Must be referentially stable — wrap it in
 *   `useCallback` with the props it reads.
 */
export function usePsiResource<T>( load: ( signal: AbortSignal ) => Promise<T> ): PsiResource<T> {
    const [ data, setData ] = useState<T | null>( null )
    const [ error, setError ] = useState<PageSpeedInsightsError | null>( null )
    const [ loading, setLoading ] = useState<boolean>( true )
    const [ nonce, setNonce ] = useState<number>( 0 )

    const reload = useCallback( () => setNonce( ( value ) => value + 1 ), [] )

    useEffect( () => {
        const controller = new AbortController()
        let active = true

        setLoading( true )

        load( controller.signal )
            .then( ( result ) => {
                if ( ! active ) return
                setData( result )
                setError( null )
            } )
            .catch( ( thrown: unknown ) => {
                // An aborted request is the component's own doing — a changed
                // selector, an unmount — and is not news.
                if ( ! active || isAbortError( thrown ) ) return

                setError(
                    thrown instanceof PageSpeedInsightsError
                        ? thrown
                        : new PageSpeedInsightsError(
                              'request_failed',
                              0,
                              thrown instanceof Error ? thrown.message : String( thrown ),
                          ),
                )
            } )
            .finally( () => {
                if ( active ) setLoading( false )
            } )

        return () => {
            active = false
            controller.abort()
        }
    }, [ load, nonce ] )

    return { data, error, loading, reload }
}
