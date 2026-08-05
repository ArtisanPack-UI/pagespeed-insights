/**
 * The framework-agnostic half of the PageSpeed Insights front end.
 *
 * Everything here is plain TypeScript with no React or Vue in it, because both
 * framework packages consume it. Import from the framework barrel
 * (`../react` or `../vue`) for the components themselves.
 */

export {
    DEFAULT_ENDPOINT_BASE,
    ERROR_MALFORMED_RESPONSE,
    ERROR_NO_FETCH,
    PageSpeedInsightsError,
    isAbortError,
    psiRequest,
} from './client'
export type { PsiBand, PsiRequestOptions, PsiResultSummary, PsiStrategy } from './client'

export { PSI_EVENT_RESULT_STORED, emitPsiResultStored, onPsiResultStored } from './events'
export type { PsiResultStored, PsiResultStoredListener } from './events'

export {
    BAND_COLORS,
    bandColor,
    bandLabel,
    formatSavings,
    formatTimestamp,
    formatVital,
    metricLabel,
    normalizeStrategy,
    strategyLabel,
    vitalAbbreviation,
    vitalLabel,
} from './labels'

export {
    SCORES_STATE_DEGRADED,
    SCORES_STATE_EMPTY,
    SCORES_STATE_FAILED,
    SCORES_STATE_LOADED,
    fetchPsiScores,
} from './scores'
export type {
    FetchPsiScoresOptions,
    PsiCategoryScore,
    PsiLabMetric,
    PsiScoresData,
    PsiScoresState,
} from './scores'

export {
    VITALS_STATE_EMPTY,
    VITALS_STATE_FAILED,
    VITALS_STATE_LOADED,
    VITALS_STATE_NO_FIELD_DATA,
    VITALS_STATE_ORIGIN_LEVEL,
    fetchPsiCoreWebVitals,
} from './core-web-vitals'
export type {
    FetchPsiCoreWebVitalsOptions,
    PsiCoreWebVitalsData,
    PsiFieldData,
    PsiVital,
    PsiVitalsState,
} from './core-web-vitals'

export {
    OPPORTUNITIES_STATE_EMPTY,
    OPPORTUNITIES_STATE_FAILED,
    OPPORTUNITIES_STATE_LOADED,
    OPPORTUNITIES_STATE_NONE,
    OPPORTUNITIES_STATE_NOT_MEASURED,
    fetchPsiOpportunities,
} from './opportunities'
export type {
    FetchPsiOpportunitiesOptions,
    PsiOpportunitiesData,
    PsiOpportunitiesState,
    PsiOpportunity,
} from './opportunities'

export {
    TREND_CATEGORIES,
    TREND_DEFAULT_METRIC,
    TREND_DEFAULT_RANGE,
    TREND_LAB_METRICS,
    TREND_MAX_RESULTS,
    TREND_METRICS,
    TREND_MINIMUM_POINTS,
    TREND_RANGES,
    TREND_STATE_EMPTY,
    TREND_STATE_INSUFFICIENT,
    TREND_STATE_LOADED,
    TREND_STATE_OUT_OF_RANGE,
    fetchPsiTrend,
    isTrendCategory,
} from './trends'
export type {
    FetchPsiTrendOptions,
    PsiTrendData,
    PsiTrendPoint,
    PsiTrendSeries,
    PsiTrendState,
} from './trends'

export {
    URLS_ERROR_ALREADY_MONITORED,
    URLS_ERROR_INVALID_ATTRIBUTE,
    URLS_ERROR_NOT_FOUND,
    URLS_MAX,
    URLS_MAX_LABEL_LENGTH,
    createPsiUrl,
    deletePsiUrl,
    fetchPsiUrls,
} from './urls'
export type {
    PsiMonitoredUrl,
    PsiMonitoredUrlAttributes,
    PsiMonitoredUrlsData,
} from './urls'

export {
    RUN_ERROR_NO_API_KEY,
    RUN_ERROR_QUEUE_UNAVAILABLE,
    RUN_STATUS_COMPLETED,
    RUN_STATUS_FAILED,
    RUN_STATUS_QUEUED,
    RUN_STATUS_TIMED_OUT,
    fetchPsiRun,
    queuePsiTest,
} from './runs'
export type { PsiFullResult, PsiQueuedRun, PsiRunResult, PsiRunStatus, QueuePsiTestOptions } from './runs'
