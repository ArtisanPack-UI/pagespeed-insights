/**
 * Typed client for `GET /pagespeed/scores`.
 */

import { psiRequest, type PsiBand, type PsiRequestOptions, type PsiResultSummary, type PsiStrategy } from './client'

/**
 * No run has been stored for this URL and form factor yet.
 */
export const SCORES_STATE_EMPTY = 'empty'

/**
 * The most recent run failed.
 */
export const SCORES_STATE_FAILED = 'failed'

/**
 * The most recent run completed but lost data on the way.
 */
export const SCORES_STATE_DEGRADED = 'degraded'

/**
 * The most recent run completed cleanly.
 */
export const SCORES_STATE_LOADED = 'loaded'

/**
 * The four states `ScoresController` answers with.
 */
export type PsiScoresState =
    | typeof SCORES_STATE_EMPTY
    | typeof SCORES_STATE_FAILED
    | typeof SCORES_STATE_DEGRADED
    | typeof SCORES_STATE_LOADED

/**
 * One Lighthouse category's score.
 *
 * A category the run never measured is absent from the list entirely rather
 * than present with a null score, which is how a client tells "not measured"
 * from "measured and unscored".
 */
export interface PsiCategoryScore {
    category: string
    score: number | null
    band: PsiBand | null
}

/**
 * One Lighthouse lab metric, keyed by audit id in {@link PsiScoresData.labMetrics}.
 */
export interface PsiLabMetric {
    value: number | null
    display: string | null
}

/**
 * The payload `GET /pagespeed/scores` serves.
 */
export interface PsiScoresData {
    url: string
    strategy: PsiStrategy
    apiKeyConfigured: boolean
    state: PsiScoresState
    result: PsiResultSummary | null
    scores: PsiCategoryScore[]
    labMetrics: Record<string, PsiLabMetric>
}

/**
 * The options {@link fetchPsiScores} accepts.
 */
export interface FetchPsiScoresOptions extends PsiRequestOptions {
    /** The URL to read. Required — it is the subject of the request. */
    url: string
    /** The form factor. Defaults to mobile server-side. */
    strategy?: PsiStrategy | null
}

/**
 * Fetch the latest category scores and lab metrics for one URL.
 */
export function fetchPsiScores( options: FetchPsiScoresOptions ): Promise<PsiScoresData> {
    const { url, strategy, ...request } = options

    return psiRequest<PsiScoresData>( '/scores', {
        ...request,
        query: { url, strategy },
    } )
}
