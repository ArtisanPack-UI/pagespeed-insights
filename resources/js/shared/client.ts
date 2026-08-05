/**
 * Shared HTTP plumbing for the PageSpeed Insights JSON endpoints.
 *
 * The React and Vue components talk to the same five endpoints, so the request
 * shape, the error vocabulary, and the CSRF handling live here once rather than
 * being spelled slightly differently in each framework's copy. A summary
 * serialised three ways is how one dashboard grows three subtly different bugs.
 */

/**
 * The form factors this package tests.
 */
export type PsiStrategy = 'mobile' | 'desktop'

/**
 * Where a score or a vital falls against Google's published thresholds.
 *
 * Computed server-side and sent as a name rather than a colour: duplicating the
 * arithmetic in every client is how two surfaces of the same dashboard come to
 * disagree about whether a page is green.
 */
export type PsiBand = 'good' | 'needs-improvement' | 'poor'

/**
 * Which run a payload describes, without any of its measurements.
 *
 * Mirrors `ResultPresenter::summary()`.
 */
export interface PsiResultSummary {
    id: number | null
    url: string
    finalUrl: string | null
    strategy: string
    status: string
    lighthouseVersion: string | null
    fetchedAt: string | null
    degraded: boolean
    warnings: string[]
    errorMessage: string | null
}

/**
 * The path the package's routes sit under by default.
 *
 * Matches `pagespeed-insights.routes.prefix`. An application that moves the
 * prefix passes its own `endpointBase` to every component.
 */
export const DEFAULT_ENDPOINT_BASE = '/pagespeed'

/**
 * The options every request in this layer accepts.
 */
export interface PsiRequestOptions {
    /** Path the package's routes are mounted under. Defaults to `/pagespeed`. */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** Cancels the request. */
    signal?: AbortSignal
    /**
     * CSRF token for the writing endpoints. Read from the page's
     * `<meta name="csrf-token">` when omitted, which is where Laravel's own
     * scaffolding puts it.
     */
    csrfToken?: string | null
}

/**
 * No global `fetch` was available and none was supplied.
 */
export const ERROR_NO_FETCH = 'no_fetch'

/**
 * The response arrived but was not the JSON the endpoint documents.
 */
export const ERROR_MALFORMED_RESPONSE = 'malformed_response'

/**
 * A failed request, carrying the endpoint's machine-readable code.
 *
 * Every endpoint answers a refusal with a stable `error` alongside its prose
 * `message`, so a client branches on the code and displays the message. Code
 * that branches on the prose breaks the moment somebody improves the wording,
 * or the moment the server runs in a locale the client does not read.
 */
export class PageSpeedInsightsError extends Error {
    /** The endpoint's machine-readable error code. */
    public readonly code: string

    /** The HTTP status, or 0 when the request never reached the server. */
    public readonly status: number

    /** The attribute a 422 refused, when the endpoint named one. */
    public readonly field: string | null

    constructor( code: string, status: number, message: string, field: string | null = null ) {
        super( message )
        this.name = 'PageSpeedInsightsError'
        this.code = code
        this.status = status
        this.field = field
    }
}

/**
 * The CSRF token Laravel's scaffolding writes into the document head.
 */
function documentCsrfToken(): string | null {
    if ( typeof document === 'undefined' ) {
        return null
    }

    return document.querySelector<HTMLMetaElement>( 'meta[name="csrf-token"]' )?.content ?? null
}

/**
 * Build a query string from values, dropping the ones that were not supplied.
 *
 * An omitted parameter and an empty one are not the same request: `strategy=`
 * on the trends endpoint means "every form factor", and sending it as an empty
 * string when the caller passed nothing would silently widen the answer.
 */
function queryString( query: Record<string, string | number | null | undefined> ): string {
    const params = new URLSearchParams()

    for ( const [ key, value ] of Object.entries( query ) ) {
        if ( null === value || undefined === value || '' === value ) {
            continue
        }

        params.set( key, String( value ) )
    }

    const encoded = params.toString()

    return '' === encoded ? '' : `?${ encoded }`
}

/**
 * Call one of the package's endpoints and return its decoded payload.
 *
 * Throws {@link PageSpeedInsightsError} on any non-2xx response, so consumers
 * render code-driven UI — "this installation does not monitor that URL" is a
 * different screen from "the URL is unusable" — rather than parsing raw JSON.
 *
 * @typeParam T - The payload the endpoint documents.
 */
export async function psiRequest<T>(
    path: string,
    options: PsiRequestOptions & {
        method?: 'GET' | 'POST' | 'DELETE'
        query?: Record<string, string | number | null | undefined>
        body?: Record<string, unknown>
    } = {},
): Promise<T> {
    const {
        endpointBase = DEFAULT_ENDPOINT_BASE,
        fetchImpl = typeof fetch !== 'undefined' ? fetch : undefined,
        signal,
        csrfToken,
        method = 'GET',
        query = {},
        body,
    } = options

    if ( ! fetchImpl ) {
        throw new PageSpeedInsightsError(
            ERROR_NO_FETCH,
            0,
            'A global fetch implementation is not available. Pass options.fetchImpl explicitly.',
        )
    }

    const headers: Record<string, string> = { Accept: 'application/json' }

    if ( undefined !== body ) {
        headers[ 'Content-Type' ] = 'application/json'
    }

    if ( 'GET' !== method ) {
        const token = undefined === csrfToken ? documentCsrfToken() : csrfToken

        if ( token ) {
            headers[ 'X-CSRF-TOKEN' ] = token
        }
    }

    const url = `${ endpointBase.replace( /\/+$/, '' ) }${ path }${ queryString( query ) }`

    const response = await fetchImpl( url, {
        method,
        headers,
        signal,
        credentials: 'same-origin',
        body: undefined === body ? undefined : JSON.stringify( body ),
    } )

    if ( ! response.ok ) {
        let code = 'http_error'
        let message = `Request failed with status ${ response.status }`
        let field: string | null = null

        try {
            const payload = ( await response.json() ) as {
                error?: string
                message?: string
                field?: string
            }

            if ( payload?.error ) code = payload.error
            if ( payload?.message ) message = payload.message
            if ( payload?.field ) field = payload.field
        } catch {
            /* The body was not JSON — keep the defaults. */
        }

        throw new PageSpeedInsightsError( code, response.status, message, field )
    }

    // 204 is what `DELETE /urls/{id}` answers with, and asking an empty body
    // for its JSON throws.
    if ( 204 === response.status ) {
        return undefined as T
    }

    try {
        return ( await response.json() ) as T
    } catch {
        throw new PageSpeedInsightsError(
            ERROR_MALFORMED_RESPONSE,
            response.status,
            'The endpoint answered with something other than JSON.',
        )
    }
}

/**
 * Whether a thrown value is a request that was cancelled rather than one that
 * failed.
 *
 * An aborted request is the caller's own doing — a changed selector, an
 * unmounted component — and rendering it as an error puts a red panel on screen
 * for something nobody did wrong.
 */
export function isAbortError( error: unknown ): boolean {
    return error instanceof DOMException && 'AbortError' === error.name
}
