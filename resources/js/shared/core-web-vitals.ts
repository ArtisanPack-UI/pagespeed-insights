/**
 * Typed client for `GET /pagespeed/core-web-vitals`.
 */

import { psiRequest, type PsiBand, type PsiRequestOptions, type PsiResultSummary, type PsiStrategy } from './client'

/**
 * No run has been stored for this URL and form factor yet.
 */
export const VITALS_STATE_EMPTY = 'empty'

/**
 * The most recent run failed, so there is no field data to read.
 */
export const VITALS_STATE_FAILED = 'failed'

/**
 * The run completed, but CrUX has no data for the page or the origin.
 */
export const VITALS_STATE_NO_FIELD_DATA = 'no-field-data'

/**
 * The only data available describes the origin, not this page.
 */
export const VITALS_STATE_ORIGIN_LEVEL = 'origin-level'

/**
 * Page-level field data for this exact URL.
 */
export const VITALS_STATE_LOADED = 'loaded'

/**
 * The five states `CoreWebVitalsController` answers with.
 */
export type PsiVitalsState =
    | typeof VITALS_STATE_EMPTY
    | typeof VITALS_STATE_FAILED
    | typeof VITALS_STATE_NO_FIELD_DATA
    | typeof VITALS_STATE_ORIGIN_LEVEL
    | typeof VITALS_STATE_LOADED

/**
 * One Core Web Vital, in the units CrUX reports it in.
 *
 * CLS arrives multiplied by 100 — a value of 4 is a CLS of 0.04 — which is why
 * rendering it goes through `formatVital()` rather than being printed raw.
 */
export interface PsiVital {
    metric: string
    value: number | null
    band: PsiBand | null
    category: string | null
}

/**
 * One CrUX field data set, page-level or origin-level.
 */
export interface PsiFieldData {
    id: string | null
    overallCategory: string | null
    originFallback: boolean
    originLevel: boolean
    vitals: PsiVital[]
}

/**
 * The payload `GET /pagespeed/core-web-vitals` serves.
 *
 * Both sets are always carried under their own keys, and either may be null.
 * Page-level and origin-level numbers are not interchangeable — origin-level
 * numbers describe the whole site — so a client renders what it has and says
 * which it is rather than guessing from the numbers.
 */
export interface PsiCoreWebVitalsData {
    url: string
    strategy: PsiStrategy
    percentile: number
    state: PsiVitalsState
    result: PsiResultSummary | null
    page: PsiFieldData | null
    origin: PsiFieldData | null
}

/**
 * The options {@link fetchPsiCoreWebVitals} accepts.
 */
export interface FetchPsiCoreWebVitalsOptions extends PsiRequestOptions {
    /** The URL to read. */
    url: string
    /** The form factor. CrUX collects phone and desktop separately. */
    strategy?: PsiStrategy | null
}

/**
 * Fetch the latest CrUX field data for one URL.
 */
export function fetchPsiCoreWebVitals(
    options: FetchPsiCoreWebVitalsOptions,
): Promise<PsiCoreWebVitalsData> {
    const { url, strategy, ...request } = options

    return psiRequest<PsiCoreWebVitalsData>( '/core-web-vitals', {
        ...request,
        query: { url, strategy },
    } )
}
