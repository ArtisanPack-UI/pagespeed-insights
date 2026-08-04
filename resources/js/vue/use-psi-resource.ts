/**
 * The load lifecycle every component in this package shares.
 */

import { ref, shallowRef, watch, type Ref } from 'vue'
import { PageSpeedInsightsError, isAbortError } from '../shared/client'

/**
 * What {@link usePsiResource} hands back.
 *
 * @typeParam T - The payload the endpoint serves.
 */
export interface PsiResource<T> {
    /** The most recent payload, or null before the first one arrives. */
    data: Ref<T | null>
    /** The refusal the endpoint answered with, or null. */
    error: Ref<PageSpeedInsightsError | null>
    /** Whether a request is in flight. */
    loading: Ref<boolean>
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
 * @param deps - Returns everything the request is built from. Whatever it
 *   reads is what the reload is watched on — the Vue counterpart of the React
 *   hook's dependency array, and spelled out rather than inferred so that a
 *   value read after an `await` inside `load` cannot silently stop being
 *   tracked.
 * @param load - Runs the request.
 */
export function usePsiResource<T>(
    deps: () => unknown,
    load: ( signal: AbortSignal ) => Promise<T>,
): PsiResource<T> {
    // Shallow: a payload is replaced wholesale and never edited in place, so
    // the deep proxy `ref()` would build over every score, vital, and trend
    // point buys nothing and is walked again on each reload.
    const data = shallowRef<T | null>( null )
    const error = shallowRef<PageSpeedInsightsError | null>( null )
    const loading = ref<boolean>( true )
    const nonce = ref<number>( 0 )

    const reload = () => {
        nonce.value += 1
    }

    watch(
        () => [ deps(), nonce.value ],
        ( _current, _previous, onCleanup ) => {
            const controller = new AbortController()
            let active = true

            onCleanup( () => {
                active = false
                controller.abort()
            } )

            loading.value = true

            load( controller.signal )
                .then( ( result ) => {
                    if ( ! active ) return
                    data.value = result
                    error.value = null
                } )
                .catch( ( thrown: unknown ) => {
                    // An aborted request is the component's own doing — a
                    // changed selector, an unmount — and is not news.
                    if ( ! active || isAbortError( thrown ) ) return

                    error.value =
                        thrown instanceof PageSpeedInsightsError
                            ? thrown
                            : new PageSpeedInsightsError(
                                  'request_failed',
                                  0,
                                  thrown instanceof Error ? thrown.message : String( thrown ),
                              )
                } )
                .finally( () => {
                    if ( active ) loading.value = false
                } )
        },
        { immediate: true },
    )

    return { data, error, loading, reload }
}
