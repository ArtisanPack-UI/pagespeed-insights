/**
 * The announcement a finished run makes to the rest of the page.
 *
 * The Livewire components already do this: the score card is the only one that
 * queues a run, so it is the only one that knows when a new row lands, and it
 * dispatches `pagespeed-insights:result-stored` for the vitals card, the
 * opportunities table, and the trend chart to pick up. Without it a page shows
 * fresh scores beside three panels describing the previous run, which reads as
 * a page that has finished updating when it has not.
 *
 * This is the same announcement, framework-free, so the React and Vue halves
 * behave the same way — and so a host application that mounts its own panels
 * can join in.
 */

import type { PsiStrategy } from './client'

/**
 * The event name, matching `ScoreCard::EVENT_RESULT_STORED`.
 */
export const PSI_EVENT_RESULT_STORED = 'pagespeed-insights:result-stored'

/**
 * What a finished run announces about itself.
 */
export interface PsiResultStored {
    /** The URL the run was for. */
    url: string
    /** The form factor it ran on. */
    strategy: PsiStrategy
    /** The stored result's id, when the announcer knows it. */
    id: number | string | null
}

/**
 * One listener.
 */
export type PsiResultStoredListener = ( event: PsiResultStored ) => void

const listeners = new Set<PsiResultStoredListener>()

/**
 * Listen for finished runs.
 *
 * @returns A function that stops listening. Call it on unmount — a listener
 *   left behind holds its component's closure alive and refreshes a panel that
 *   is no longer on the page.
 */
export function onPsiResultStored( listener: PsiResultStoredListener ): () => void {
    listeners.add( listener )

    return () => {
        listeners.delete( listener )
    }
}

/**
 * Announce a finished run.
 *
 * A listener that throws is not allowed to stop the others from hearing about
 * it: the panels on a page are independent, and one of them failing to refresh
 * is a smaller problem than the rest silently not refreshing either.
 *
 * A `CustomEvent` is dispatched on `window` alongside the in-process
 * listeners, so a surface built with neither of this package's component sets
 * — a Blade page with its own script, say — can hear it too.
 */
export function emitPsiResultStored( event: PsiResultStored ): void {
    for ( const listener of [ ...listeners ] ) {
        try {
            listener( event )
        } catch ( thrown: unknown ) {
            console.error( `${ PSI_EVENT_RESULT_STORED } listener failed`, thrown )
        }
    }

    if ( 'undefined' !== typeof window && 'function' === typeof window.dispatchEvent ) {
        window.dispatchEvent( new CustomEvent( PSI_EVENT_RESULT_STORED, { detail: event } ) )
    }
}
