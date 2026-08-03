/**
 * Typed client for `GET /pagespeed/trends`.
 */

import { psiRequest, type PsiRequestOptions, type PsiStrategy } from './client'

/**
 * No completed run has ever been stored for this URL.
 */
export const TREND_STATE_EMPTY = 'empty'

/**
 * There is history, but none of it falls inside the selected range.
 */
export const TREND_STATE_OUT_OF_RANGE = 'out-of-range'

/**
 * Fewer than two usable measurements, which is a dot rather than a trend.
 */
export const TREND_STATE_INSUFFICIENT = 'insufficient'

/**
 * There is a trend to draw.
 */
export const TREND_STATE_LOADED = 'loaded'

/**
 * The four states `TrendsController` answers with.
 */
export type PsiTrendState =
    | typeof TREND_STATE_EMPTY
    | typeof TREND_STATE_OUT_OF_RANGE
    | typeof TREND_STATE_INSUFFICIENT
    | typeof TREND_STATE_LOADED

/**
 * The ranges a trend may be drawn over, in days.
 *
 * Capped at a year because that is where `retention.days` defaults, so a wider
 * window would offer a view the data has already been pruned out of. Mirrors
 * `TrendSeries::RANGES`.
 */
export const TREND_RANGES = [ 7, 30, 90, 365 ] as const

/**
 * The range used when none is asked for.
 */
export const TREND_DEFAULT_RANGE = 90

/**
 * The measurement plotted when none is asked for.
 */
export const TREND_DEFAULT_METRIC = 'performance'

/**
 * The fewest usable points that make a trend rather than a dot.
 */
export const TREND_MINIMUM_POINTS = 2

/**
 * The most runs one trend will carry. Mirrors `TrendSeries::MAX_RESULTS`.
 */
export const TREND_MAX_RESULTS = 500

/**
 * The four Lighthouse categories a trend can plot.
 */
export const TREND_CATEGORIES = [ 'performance', 'accessibility', 'best-practices', 'seo' ] as const

/**
 * The lab metrics a trend can plot. Mirrors `LabMetrics::AUDIT_IDS`.
 */
export const TREND_LAB_METRICS = [
    'first-contentful-paint',
    'largest-contentful-paint',
    'total-blocking-time',
    'cumulative-layout-shift',
    'speed-index',
] as const

/**
 * Every measurement a trend can be drawn for, in display order.
 */
export const TREND_METRICS: string[] = [ ...TREND_CATEGORIES, ...TREND_LAB_METRICS ]

/**
 * Whether a metric is one of the four Lighthouse categories.
 *
 * Category scores are pinned to a 0-100 axis; a lab metric is not, because a
 * paint time has no ceiling.
 */
export function isTrendCategory( metric: string ): boolean {
    return ( TREND_CATEGORIES as readonly string[] ).includes( metric )
}

/**
 * One plotted point.
 *
 * A completed run that lost this particular measurement is emitted as a null
 * `y` rather than skipped, so a client breaks its line at the hole instead of
 * joining a confident straight line across a fortnight when testing silently
 * stopped.
 */
export interface PsiTrendPoint {
    x: string
    y: number | null
}

/**
 * One form factor's series.
 */
export interface PsiTrendSeries {
    strategy: string
    points: PsiTrendPoint[]
}

/**
 * The payload `GET /pagespeed/trends` serves.
 */
export interface PsiTrendData {
    url: string
    metric: string
    range: number
    strategy: PsiStrategy | null
    state: PsiTrendState
    series: PsiTrendSeries[]
    pointCount: number
    hasGaps: boolean
    truncated: boolean
    hasOlderHistory: boolean
}

/**
 * The options {@link fetchPsiTrend} accepts.
 */
export interface FetchPsiTrendOptions extends PsiRequestOptions {
    /** The URL to read. */
    url: string
    /** A value of {@link TREND_METRICS}. Unknown metrics fall back server-side. */
    metric?: string | null
    /** A value of {@link TREND_RANGES}, in days. */
    range?: number | null
    /** One form factor, or null for every one that has data. */
    strategy?: PsiStrategy | null
}

/**
 * Fetch one measurement's history for one URL.
 */
export function fetchPsiTrend( options: FetchPsiTrendOptions ): Promise<PsiTrendData> {
    const { url, metric, range, strategy, ...request } = options

    return psiRequest<PsiTrendData>( '/trends', {
        ...request,
        query: { url, metric, range, strategy },
    } )
}
