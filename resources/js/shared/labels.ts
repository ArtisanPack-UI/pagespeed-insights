/**
 * The presentation vocabulary the JSON endpoints deliberately do not send.
 *
 * Bands, categories, and metric keys arrive as stable identifiers. Names and
 * theme colours are presentation choices, and an endpoint that baked them in
 * would hand every caller a string in the server's locale rather than the
 * reader's. They are resolved here, once, for both frameworks.
 */

import type { PsiBand, PsiStrategy } from './client'

/**
 * The theme colour each band renders in.
 *
 * Named colours rather than hex, so the components follow whatever theme the
 * host application generated. Mirrors `ScoreBands::COLORS`.
 */
export const BAND_COLORS: Record<PsiBand, 'success' | 'warning' | 'error'> = {
    good: 'success',
    'needs-improvement': 'warning',
    poor: 'error',
}

/**
 * The theme colour for a band, or undefined when there is nothing to band.
 */
export function bandColor( band: string | null | undefined ): 'success' | 'warning' | 'error' | undefined {
    return band && band in BAND_COLORS ? BAND_COLORS[ band as PsiBand ] : undefined
}

/**
 * A band's name, written for a human.
 */
export function bandLabel( band: string | null | undefined ): string | null {
    switch ( band ) {
        case 'good':
            return 'Good'
        case 'needs-improvement':
            return 'Needs improvement'
        case 'poor':
            return 'Poor'
        default:
            return null
    }
}

/**
 * A Lighthouse category's or lab metric's name, written for a human.
 *
 * An unrecognised key is returned as it arrived rather than blanked: a metric
 * this build does not know the name of is still a metric worth plotting.
 */
export function metricLabel( metric: string ): string {
    switch ( metric ) {
        case 'performance':
            return 'Performance'
        case 'accessibility':
            return 'Accessibility'
        case 'best-practices':
            return 'Best practices'
        case 'seo':
            return 'SEO'
        case 'first-contentful-paint':
            return 'First Contentful Paint'
        case 'largest-contentful-paint':
            return 'Largest Contentful Paint'
        case 'total-blocking-time':
            return 'Total Blocking Time'
        case 'cumulative-layout-shift':
            return 'Cumulative Layout Shift'
        case 'speed-index':
            return 'Speed Index'
        default:
            return metric
    }
}

/**
 * A Core Web Vital's full name, written for a human.
 */
export function vitalLabel( metric: string ): string {
    switch ( metric ) {
        case 'largest_contentful_paint':
            return 'Largest Contentful Paint'
        case 'interaction_to_next_paint':
            return 'Interaction to Next Paint'
        case 'cumulative_layout_shift':
            return 'Cumulative Layout Shift'
        default:
            return metric
    }
}

/**
 * A Core Web Vital's initialism.
 *
 * Not localised: LCP, INP, and CLS are Google's own identifiers and are used
 * untranslated in every locale's documentation, so translating them would make
 * the card harder to match against the tool it mirrors rather than easier.
 */
export function vitalAbbreviation( metric: string ): string {
    switch ( metric ) {
        case 'largest_contentful_paint':
            return 'LCP'
        case 'interaction_to_next_paint':
            return 'INP'
        case 'cumulative_layout_shift':
            return 'CLS'
        default:
            return metric
    }
}

/**
 * A Core Web Vital's measurement, written in the unit a reader expects.
 *
 * LCP and INP arrive in milliseconds; LCP is conventionally read in seconds,
 * INP in milliseconds. CLS arrives multiplied by 100 and is read as the
 * unitless score it started as.
 */
export function formatVital( metric: string, value: number | null ): string | null {
    if ( null === value ) {
        return null
    }

    switch ( metric ) {
        case 'largest_contentful_paint':
            return `${ ( value / 1000 ).toFixed( 1 ) } s`
        case 'interaction_to_next_paint':
            return `${ new Intl.NumberFormat().format( value ) } ms`
        case 'cumulative_layout_shift':
            return ( value / 100 ).toFixed( 2 )
        default:
            return String( value )
    }
}

/**
 * A form factor's name, written for a human.
 */
export function strategyLabel( strategy: string ): string {
    return 'desktop' === strategy ? 'Desktop' : 'Mobile'
}

/**
 * Reduce a requested form factor to one this package tests.
 *
 * Matches the server, which reduces anything it does not recognise to mobile
 * rather than answering 422.
 */
export function normalizeStrategy( strategy: string | null | undefined ): PsiStrategy {
    return 'desktop' === strategy?.trim().toLowerCase() ? 'desktop' : 'mobile'
}

/**
 * An estimated saving in milliseconds, written for a human.
 *
 * Mirrors the Livewire table: under a second reads in milliseconds, above it in
 * seconds, because "1,240 ms" is harder to weigh at a glance than "1.2 s".
 */
export function formatSavings( savingsMs: number | null ): string | null {
    if ( null === savingsMs || savingsMs <= 0 ) {
        return null
    }

    if ( savingsMs < 1000 ) {
        return `${ new Intl.NumberFormat().format( Math.round( savingsMs ) ) } ms`
    }

    return `${ ( savingsMs / 1000 ).toFixed( 1 ) } s`
}

/**
 * An ISO 8601 timestamp, written in the reader's own locale.
 *
 * Anything unparseable is handed back untouched rather than rendered as
 * "Invalid Date".
 */
export function formatTimestamp( timestamp: string | null | undefined ): string | null {
    if ( ! timestamp ) {
        return null
    }

    const parsed = new Date( timestamp )

    if ( Number.isNaN( parsed.getTime() ) ) {
        return timestamp
    }

    return parsed.toLocaleString()
}
