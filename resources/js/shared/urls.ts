/**
 * Typed client for the monitored URL endpoints.
 *
 * `GET`, `POST /pagespeed/urls` and `DELETE /pagespeed/urls/{id}`.
 */

import { psiRequest, type PsiRequestOptions } from './client'

/**
 * The most URLs one listing will return. Mirrors `MonitoredUrlController::MAX_URLS`.
 */
export const URLS_MAX = 250

/**
 * The longest label the `label` column holds.
 */
export const URLS_MAX_LABEL_LENGTH = 255

/**
 * A URL was posted that another row already monitors.
 */
export const URLS_ERROR_ALREADY_MONITORED = 'already_monitored'

/**
 * One of the supplied attributes cannot be stored.
 */
export const URLS_ERROR_INVALID_ATTRIBUTE = 'invalid_attribute'

/**
 * No editable monitored URL carries the id that was named.
 */
export const URLS_ERROR_NOT_FOUND = 'not_found'

/**
 * One monitored URL.
 *
 * A URL contributed through `ap.pageSpeed.registerUrls` is listed — it costs
 * quota on every cycle exactly like a stored row does — but carries a null `id`
 * and `editable: false`, because it is owned by whichever package registered it
 * and the next request would rebuild it from the filter whatever this endpoint
 * did to it.
 *
 * A type alias rather than an interface so it satisfies the
 * `Record<string, unknown>` constraint the component library's `Table` places
 * on its row type — interfaces get no implicit index signature.
 */
export type PsiMonitoredUrl = {
    id: number | null
    url: string
    label: string | null
    source: string
    editable: boolean
    isActive: boolean
    strategies: string[]
    testFrequency: string | null
    frequency: string
    lastTestedAt: string | null
}

/**
 * The payload `GET /pagespeed/urls` serves.
 */
export interface PsiMonitoredUrlsData {
    urls: PsiMonitoredUrl[]
    total: number
    truncated: boolean
}

/**
 * The attributes `POST /pagespeed/urls` accepts alongside the URL.
 */
export interface PsiMonitoredUrlAttributes {
    label?: string | null
    strategies?: string[]
    isActive?: boolean
    testFrequency?: string | null
}

/**
 * List the monitored set.
 */
export function fetchPsiUrls( options: PsiRequestOptions = {} ): Promise<PsiMonitoredUrlsData> {
    return psiRequest<PsiMonitoredUrlsData>( '/urls', options )
}

/**
 * Start monitoring a URL.
 *
 * Adding is a recurring commitment of API quota, so the server holds it to a
 * tighter scope than reading: a URL that is neither monitored nor on this
 * application's own origin is refused unless `routes.allow_external_urls` is on.
 */
export function createPsiUrl(
    url: string,
    attributes: PsiMonitoredUrlAttributes = {},
    options: PsiRequestOptions = {},
): Promise<PsiMonitoredUrl> {
    return psiRequest<PsiMonitoredUrl>( '/urls', {
        ...options,
        method: 'POST',
        body: { url, ...attributes },
    } )
}

/**
 * Stop monitoring a URL.
 *
 * The row's result history is kept — `pagespeed_results` carries the URL in its
 * own column and its foreign key nulls rather than cascades.
 */
export function deletePsiUrl( id: number, options: PsiRequestOptions = {} ): Promise<void> {
    return psiRequest<void>( `/urls/${ encodeURIComponent( String( id ) ) }`, {
        ...options,
        method: 'DELETE',
    } )
}
