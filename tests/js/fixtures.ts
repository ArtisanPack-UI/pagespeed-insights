/**
 * Mocked endpoint responses, in the shapes the controllers serialise.
 *
 * Written against `ResultPresenter` and the five controllers rather than
 * against the components, so a payload the server stops sending fails these
 * tests instead of quietly rendering as an empty card.
 */

import type { PsiResultSummary } from '../../resources/js/shared/client'
import type { PsiScoresData } from '../../resources/js/shared/scores'
import type { PsiCoreWebVitalsData } from '../../resources/js/shared/core-web-vitals'
import type { PsiOpportunitiesData } from '../../resources/js/shared/opportunities'
import type { PsiTrendData } from '../../resources/js/shared/trends'
import type { PsiMonitoredUrlsData } from '../../resources/js/shared/urls'

export const TEST_URL = 'https://example.com/pricing'

/**
 * A completed run's summary.
 */
export function resultSummary( overrides: Partial<PsiResultSummary> = {} ): PsiResultSummary {
    return {
        id: 12,
        url: TEST_URL,
        finalUrl: TEST_URL,
        strategy: 'mobile',
        status: 'completed',
        lighthouseVersion: '12.0.0',
        fetchedAt: '2026-08-01T10:00:00+00:00',
        degraded: false,
        warnings: [],
        errorMessage: null,
        ...overrides,
    }
}

/**
 * `GET /pagespeed/scores`.
 */
export function scoresPayload( overrides: Partial<PsiScoresData> = {} ): PsiScoresData {
    return {
        url: TEST_URL,
        strategy: 'mobile',
        apiKeyConfigured: true,
        state: 'loaded',
        result: resultSummary(),
        scores: [
            { category: 'performance', score: 94, band: 'good' },
            { category: 'accessibility', score: 72, band: 'needs-improvement' },
            { category: 'best-practices', score: 41, band: 'poor' },
        ],
        labMetrics: {
            'first-contentful-paint': { value: 1200, display: '1.2 s' },
        },
        ...overrides,
    }
}

/**
 * `GET /pagespeed/core-web-vitals`.
 */
export function vitalsPayload( overrides: Partial<PsiCoreWebVitalsData> = {} ): PsiCoreWebVitalsData {
    return {
        url: TEST_URL,
        strategy: 'mobile',
        percentile: 75,
        state: 'loaded',
        result: resultSummary(),
        page: {
            id: TEST_URL,
            overallCategory: 'AVERAGE',
            originFallback: false,
            originLevel: false,
            vitals: [
                { metric: 'largest_contentful_paint', value: 2100, band: 'good', category: 'FAST' },
                { metric: 'interaction_to_next_paint', value: 350, band: 'needs-improvement', category: 'AVERAGE' },
                { metric: 'cumulative_layout_shift', value: 4, band: 'good', category: 'FAST' },
            ],
        },
        origin: null,
        ...overrides,
    }
}

/**
 * `GET /pagespeed/opportunities`.
 */
export function opportunitiesPayload(
    overrides: Partial<PsiOpportunitiesData> = {},
): PsiOpportunitiesData {
    return {
        url: TEST_URL,
        strategy: 'mobile',
        state: 'loaded',
        result: resultSummary(),
        opportunities: [
            {
                id: 'render-blocking-resources',
                title: 'Eliminate render-blocking resources',
                description: 'Resources are blocking the first paint of your page.',
                displayValue: 'Potential savings of 1,240 ms',
                savingsMs: 1240,
                score: 38,
                band: 'poor',
            },
            {
                id: 'unused-css-rules',
                title: 'Reduce unused CSS',
                description: null,
                displayValue: null,
                savingsMs: null,
                score: null,
                band: null,
            },
        ],
        ...overrides,
    }
}

/**
 * `GET /pagespeed/trends`.
 */
export function trendPayload( overrides: Partial<PsiTrendData> = {} ): PsiTrendData {
    return {
        url: TEST_URL,
        metric: 'performance',
        range: 90,
        strategy: null,
        state: 'loaded',
        series: [
            {
                strategy: 'mobile',
                points: [
                    { x: '2026-07-01T00:00:00+00:00', y: 82 },
                    { x: '2026-07-08T00:00:00+00:00', y: null },
                    { x: '2026-07-15T00:00:00+00:00', y: 91 },
                    { x: '2026-07-22T00:00:00+00:00', y: 94 },
                ],
            },
        ],
        pointCount: 3,
        hasGaps: true,
        truncated: false,
        hasOlderHistory: false,
        ...overrides,
    }
}

/**
 * `GET /pagespeed/urls`.
 */
export function urlsPayload( overrides: Partial<PsiMonitoredUrlsData> = {} ): PsiMonitoredUrlsData {
    return {
        urls: [
            {
                id: 1,
                url: TEST_URL,
                label: 'Pricing',
                source: 'manual',
                editable: true,
                isActive: true,
                strategies: [ 'mobile', 'desktop' ],
                testFrequency: 'daily',
                frequency: 'daily',
                lastTestedAt: '2026-08-01T10:00:00+00:00',
            },
            {
                id: null,
                url: 'https://example.com/blog',
                label: null,
                source: 'hook',
                editable: false,
                isActive: true,
                strategies: [ 'mobile' ],
                testFrequency: null,
                frequency: 'weekly',
                lastTestedAt: null,
            },
        ],
        total: 2,
        truncated: false,
        ...overrides,
    }
}
