/**
 * Typed client for `GET /pagespeed/opportunities`.
 */

import { psiRequest, type PsiBand, type PsiRequestOptions, type PsiResultSummary, type PsiStrategy } from './client'

/**
 * No run has been stored for this URL and form factor yet.
 */
export const OPPORTUNITIES_STATE_EMPTY = 'empty'

/**
 * The most recent run failed, so there is nothing to have found.
 */
export const OPPORTUNITIES_STATE_FAILED = 'failed'

/**
 * The run did not measure performance, so no audits were collected.
 */
export const OPPORTUNITIES_STATE_NOT_MEASURED = 'not-measured'

/**
 * Performance was measured and Lighthouse found nothing worth listing.
 */
export const OPPORTUNITIES_STATE_NONE = 'none'

/**
 * There are opportunities to show.
 */
export const OPPORTUNITIES_STATE_LOADED = 'loaded'

/**
 * The five states `OpportunitiesController` answers with.
 *
 * An empty list is three different pieces of news, and the state is what
 * separates them: a run that never asked for the performance category found
 * nothing because nothing was looked for.
 */
export type PsiOpportunitiesState =
    | typeof OPPORTUNITIES_STATE_EMPTY
    | typeof OPPORTUNITIES_STATE_FAILED
    | typeof OPPORTUNITIES_STATE_NOT_MEASURED
    | typeof OPPORTUNITIES_STATE_NONE
    | typeof OPPORTUNITIES_STATE_LOADED

/**
 * One Lighthouse opportunity audit.
 *
 * A type alias rather than an interface so it satisfies the
 * `Record<string, unknown>` constraint the component library's `Table` places
 * on its row type — interfaces get no implicit index signature.
 */
export type PsiOpportunity = {
    id: string
    title: string
    description: string | null
    displayValue: string | null
    savingsMs: number | null
    score: number | null
    band: PsiBand | null
}

/**
 * The payload `GET /pagespeed/opportunities` serves.
 */
export interface PsiOpportunitiesData {
    url: string
    strategy: PsiStrategy
    state: PsiOpportunitiesState
    result: PsiResultSummary | null
    opportunities: PsiOpportunity[]
}

/**
 * The options {@link fetchPsiOpportunities} accepts.
 */
export interface FetchPsiOpportunitiesOptions extends PsiRequestOptions {
    /** The URL to read. */
    url: string
    /** The form factor. */
    strategy?: PsiStrategy | null
}

/**
 * Fetch the latest opportunities for one URL, heaviest first.
 */
export function fetchPsiOpportunities(
    options: FetchPsiOpportunitiesOptions,
): Promise<PsiOpportunitiesData> {
    const { url, strategy, ...request } = options

    return psiRequest<PsiOpportunitiesData>( '/opportunities', {
        ...request,
        query: { url, strategy },
    } )
}
