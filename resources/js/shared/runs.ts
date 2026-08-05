/**
 * Typed client for queueing an ad hoc run and reading the result.
 *
 * `POST /pagespeed/test` and `GET /pagespeed/results/{id}`.
 */

import { psiRequest, type PsiRequestOptions, type PsiResultSummary, type PsiStrategy } from './client'
import type { PsiCategoryScore, PsiLabMetric } from './scores'
import type { PsiFieldData } from './core-web-vitals'
import type { PsiOpportunity } from './opportunities'

/**
 * The run is queued and has not started, or has not finished.
 */
export const RUN_STATUS_QUEUED = 'queued'

/**
 * The run finished and stored a result.
 */
export const RUN_STATUS_COMPLETED = 'completed'

/**
 * The run finished without a usable measurement.
 */
export const RUN_STATUS_FAILED = 'failed'

/**
 * The ticket outlived its own window without the queue reporting back.
 */
export const RUN_STATUS_TIMED_OUT = 'timed-out'

/**
 * The four statuses a ticket or a stored result reports.
 */
export type PsiRunStatus =
    | typeof RUN_STATUS_QUEUED
    | typeof RUN_STATUS_COMPLETED
    | typeof RUN_STATUS_FAILED
    | typeof RUN_STATUS_TIMED_OUT

/**
 * No usable API key is configured, so a run would fail before it started.
 *
 * Google's anonymous quota is zero, so every keyless request fails.
 */
export const RUN_ERROR_NO_API_KEY = 'no_api_key'

/**
 * The queue would not accept the job.
 */
export const RUN_ERROR_QUEUE_UNAVAILABLE = 'queue_unavailable'

/**
 * The payload `POST /pagespeed/test` serves.
 */
export interface PsiQueuedRun {
    id: number | string
    status: PsiRunStatus
    url: string
    strategy: PsiStrategy
}

/**
 * Everything one stored run holds. Mirrors `ResultPresenter::full()`.
 */
export interface PsiFullResult extends PsiResultSummary {
    scores?: PsiCategoryScore[]
    labMetrics?: Record<string, PsiLabMetric>
    fieldData?: {
        percentile: number
        page: PsiFieldData | null
        origin: PsiFieldData | null
    }
    opportunities?: PsiOpportunity[]
}

/**
 * The payload `GET /pagespeed/results/{id}` serves.
 *
 * `result` is null while a ticket is still queued: the run has an id before it
 * has anything to report.
 */
export interface PsiRunResult {
    id: number | string
    status: PsiRunStatus
    url: string
    strategy: PsiStrategy
    result: PsiFullResult | null
}

/**
 * The options {@link queuePsiTest} accepts.
 */
export interface QueuePsiTestOptions extends PsiRequestOptions {
    /** The URL to test. */
    url: string
    /** The form factor. Defaults to mobile server-side. */
    strategy?: PsiStrategy | null
}

/**
 * Queue an ad hoc run.
 *
 * Each run costs one PageSpeed request against a key the application pays for,
 * which is why the server scopes this more tightly than reading.
 */
export function queuePsiTest( options: QueuePsiTestOptions ): Promise<PsiQueuedRun> {
    const { url, strategy, ...request } = options

    return psiRequest<PsiQueuedRun>( '/test', {
        ...request,
        method: 'POST',
        body: { url, ...( strategy ? { strategy } : {} ) },
    } )
}

/**
 * Read a queued run's ticket, or a stored result, by id.
 */
export function fetchPsiRun(
    id: number | string,
    options: PsiRequestOptions = {},
): Promise<PsiRunResult> {
    return psiRequest<PsiRunResult>( `/results/${ encodeURIComponent( String( id ) ) }`, options )
}
