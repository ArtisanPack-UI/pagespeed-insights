<?php

/**
 * PageSpeed Insights response parser.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Api;

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Data\Opportunity;
use ArtisanPackUI\PageSpeedInsights\Data\ScoreSet;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use Carbon\CarbonImmutable;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Turns a raw runPagespeed payload into typed DTOs.
 *
 * The parser is deliberately tolerant. Lighthouse revises its category and
 * metric lineup on roughly a two-release cadence — `PWA` is already
 * deprecated and `AGENTIC_BROWSING` already exists — and CrUX simply has no
 * data for low-traffic pages, so a strict parser would fail runs that are
 * perfectly readable.
 *
 * Tolerance is not silence. Every skip logs a warning naming the exact key it
 * skipped, and every skip is carried onto the {@see TestResult} so storage,
 * the CLI, and the UI can say *why* a result looks thin instead of each
 * re-deriving it from the gaps.
 *
 * The one thing it will not tolerate is a run that did not happen:
 * `lighthouseResult.runtimeError` arrives with HTTP 200 and becomes a thrown
 * {@see PageSpeedApiException}, because a result full of nulls is
 * indistinguishable from a genuinely terrible page.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedResponse
{
    /**
     * The hook consumers use to reshape the pruned opportunity list.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FILTER_OPPORTUNITIES = 'ap.pageSpeed.opportunities';

    /**
     * Score display modes Lighthouse uses to mark an audit as something other
     * than a scored, actionable finding.
     *
     * Lighthouse 13 attaches `metricSavings` to diagnostics and informational
     * audits as well as opportunities, and marks them `notApplicable` with a
     * null score — which is neither passing nor failing. Without this list a
     * page scoring 100 reports a handful of "opportunities" worth zero
     * milliseconds each.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const NON_ACTIONABLE_DISPLAY_MODES = [ 'notApplicable', 'informative', 'manual', 'error' ];

    /**
     * Build the parser.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  The decoded runPagespeed response.
     * @param  PageSpeedRequest  $request  The request that produced it.
     * @param  LoggerInterface  $logger  Where tolerated gaps get reported.
     * @param  int  $opportunitiesLimit  How many opportunities to keep.
     */
    public function __construct(
        protected array $payload,
        protected PageSpeedRequest $request,
        protected LoggerInterface $logger,
        protected int $opportunitiesLimit = 10,
    ) {
    }

    /**
     * Parse the payload into an aggregate result.
     *
     * @since 1.0.0
     *
     * @throws PageSpeedApiException When Lighthouse failed or the payload is not a PageSpeed result.
     *
     * @return TestResult The parsed result.
     */
    public function toTestResult(): TestResult
    {
        $lighthouse = $this->payload[ 'lighthouseResult' ] ?? null;

        if ( ! is_array( $lighthouse ) ) {
            throw PageSpeedApiException::malformedResponse( $this->request->url );
        }

        $this->guardAgainstRuntimeError( $lighthouse );

        $audits      = is_array( $lighthouse[ 'audits' ] ?? null ) ? $lighthouse[ 'audits' ] : [];
        $runWarnings = $this->parseRunWarnings( $lighthouse );

        [ $scores, $unrecognized, $missingCategories ] = $this->parseScores( $lighthouse );
        [ $labMetrics, $missingMetrics ]               = $this->parseLabMetrics( $audits );
        [ $fieldData, $originFieldData ]               = $this->parseFieldData();

        return new TestResult(
            url: $this->request->url,
            strategy: $this->request->strategy,
            scores: $scores,
            labMetrics: $labMetrics,
            fieldData: $fieldData,
            originFieldData: $originFieldData,
            opportunities: $this->parseOpportunities( $audits ),
            finalUrl: $this->finalUrl( $lighthouse ),
            lighthouseVersion: $this->stringOrNull( $lighthouse[ 'lighthouseVersion' ] ?? null ),
            analyzedAt: $this->parseTimestamp(),
            runWarnings: $runWarnings,
            unrecognizedCategories: $unrecognized,
            missingCategories: $missingCategories,
            missingMetrics: $missingMetrics,
            raw: $this->payload,
        );
    }

    /**
     * The raw payload, for callers that opted into storing it.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The decoded response.
     */
    public function raw(): array
    {
        return $this->payload;
    }

    /**
     * Reject a run Lighthouse itself could not complete.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $lighthouse  The lighthouseResult block.
     *
     * @throws PageSpeedApiException When runtimeError is populated.
     *
     * @return void
     */
    protected function guardAgainstRuntimeError( array $lighthouse ): void
    {
        $runtimeError = $lighthouse[ 'runtimeError' ] ?? null;

        if ( ! is_array( $runtimeError ) || [] === $runtimeError ) {
            return;
        }

        $code    = $this->stringOrNull( $runtimeError[ 'code' ] ?? null ) ?? 'UNKNOWN';
        $message = $this->stringOrNull( $runtimeError[ 'message' ] ?? null ) ?? '';

        $this->logger->error(
            'PageSpeed run failed inside Lighthouse.',
            $this->request->logContext() + [ 'code' => $code, 'lighthouse_message' => $message ],
        );

        throw PageSpeedApiException::lighthouseRuntimeError( $code, $message, $this->request->url );
    }

    /**
     * Read Lighthouse's own warnings about the run.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $lighthouse  The lighthouseResult block.
     *
     * @return array<int, string> The warnings, as strings.
     */
    protected function parseRunWarnings( array $lighthouse ): array
    {
        $raw = $lighthouse[ 'runWarnings' ] ?? [];

        if ( ! is_array( $raw ) || [] === $raw ) {
            return [];
        }

        $warnings = [];

        foreach ( $raw as $warning ) {
            $text = $this->stringOrNull( $warning );

            if ( null !== $text ) {
                $warnings[] = $text;
            }
        }

        if ( [] !== $warnings ) {
            $this->logger->warning(
                'Lighthouse reported warnings for this PageSpeed run.',
                $this->request->logContext() + [ 'run_warnings' => $warnings ],
            );
        }

        return $warnings;
    }

    /**
     * Read the category scores, noting anything unexpected.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $lighthouse  The lighthouseResult block.
     *
     * @return array{0: ScoreSet, 1: array<int, string>, 2: array<int, string>} The scores, unrecognized keys, and missing requested categories.
     */
    protected function parseScores( array $lighthouse ): array
    {
        $categories = is_array( $lighthouse[ 'categories' ] ?? null ) ? $lighthouse[ 'categories' ] : [];

        $scores       = [];
        $unrecognized = [];

        foreach ( $categories as $key => $category ) {
            $responseKey = CategoryTranslator::toResponseKey( (string) $key );

            if ( ! is_array( $category ) ) {
                continue;
            }

            $scores[ $responseKey ] = $this->toScore( $category[ 'score' ] ?? null );

            if ( ! CategoryTranslator::isKnown( $responseKey ) ) {
                $unrecognized[] = $responseKey;

                $this->logger->warning(
                    'PageSpeed returned a Lighthouse category this package has no dedicated score for. It was kept on the score set but will not be stored as a column.',
                    $this->request->logContext() + [ 'category' => $responseKey ],
                );
            }
        }

        $missing = [];

        foreach ( $this->request->categories as $requested ) {
            if ( ! array_key_exists( $requested, $scores ) ) {
                $missing[] = $requested;

                $this->logger->warning(
                    'PageSpeed did not return a category that was requested. Its score will be null for this run.',
                    $this->request->logContext() + [ 'category' => $requested ],
                );
            }
        }

        return [ new ScoreSet( $scores ), $unrecognized, $missing ];
    }

    /**
     * Read the five lab metrics, noting any the response omitted.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $audits  The audits block.
     *
     * @return array{0: LabMetrics, 1: array<int, string>} The metrics and the missing audit ids.
     */
    protected function parseLabMetrics( array $audits ): array
    {
        $metrics = [];
        $missing = [];

        foreach ( LabMetrics::AUDIT_IDS as $auditId ) {
            $audit = $audits[ $auditId ] ?? null;

            if ( ! is_array( $audit ) || ! is_numeric( $audit[ 'numericValue' ] ?? null ) ) {
                $missing[] = $auditId;

                $this->logger->warning(
                    'PageSpeed did not return a lab metric. It will be stored as null for this run.',
                    $this->request->logContext() + [ 'metric' => $auditId ],
                );

                continue;
            }

            $metrics[ $auditId ] = [
                'value'   => (float) $audit[ 'numericValue' ],
                'display' => $this->stringOrNull( $audit[ 'displayValue' ] ?? null ),
            ];
        }

        return [ new LabMetrics( $metrics, $missing ), $missing ];
    }

    /**
     * Read the page-level and origin-level CrUX data.
     *
     * @since 1.0.0
     *
     * @return array{0: FieldData|null, 1: FieldData|null} Page-level and origin-level field data.
     */
    protected function parseFieldData(): array
    {
        $page   = $this->buildFieldData( $this->payload[ 'loadingExperience' ] ?? null, false );
        $origin = $this->buildFieldData( $this->payload[ 'originLoadingExperience' ] ?? null, true );

        if ( null === $page && null === $origin ) {
            $this->logger->warning(
                'PageSpeed returned no Chrome UX Report field data for this URL or its origin. This is normal for low-traffic pages, but it means Core Web Vitals cannot be shown from real users.',
                $this->request->logContext(),
            );
        } elseif ( null === $page ) {
            $this->logger->warning(
                'PageSpeed returned no page-level Chrome UX Report data; only origin-level data is available for this URL.',
                $this->request->logContext(),
            );
        }

        return [ $page, $origin ];
    }

    /**
     * Build one field data set from a loadingExperience block.
     *
     * @since 1.0.0
     *
     * @param  mixed  $experience  The loadingExperience or originLoadingExperience block.
     * @param  bool  $isOriginLevel  Whether this is the origin-level block.
     *
     * @return FieldData|null The parsed set, or null when CrUX had no metrics.
     */
    protected function buildFieldData( mixed $experience, bool $isOriginLevel ): ?FieldData
    {
        if ( ! is_array( $experience ) ) {
            return null;
        }

        $rawMetrics = is_array( $experience[ 'metrics' ] ?? null ) ? $experience[ 'metrics' ] : [];

        if ( [] === $rawMetrics ) {
            return null;
        }

        $metrics = [];

        foreach ( $rawMetrics as $key => $metric ) {
            if ( ! is_array( $metric ) ) {
                continue;
            }

            $percentile = $metric[ 'percentile' ] ?? null;

            $metrics[ (string) $key ] = [
                'percentile'    => is_numeric( $percentile ) ? (int) round( (float) $percentile ) : null,
                'category'      => $this->stringOrNull( $metric[ 'category' ] ?? null ),
                'distributions' => is_array( $metric[ 'distributions' ] ?? null ) ? array_values( $metric[ 'distributions' ] ) : [],
            ];
        }

        if ( [] === $metrics ) {
            return null;
        }

        return new FieldData(
            metrics: $metrics,
            overallCategory: $this->stringOrNull( $experience[ 'overall_category' ] ?? null ),
            id: $this->stringOrNull( $experience[ 'id' ] ?? null ),
            originFallback: true === ( $experience[ 'origin_fallback' ] ?? false ),
            isOriginLevel: $isOriginLevel,
        );
    }

    /**
     * Extract, prune, and filter the actionable audits.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $audits  The audits block.
     *
     * @return array<int, Opportunity> The pruned, filtered opportunities.
     */
    protected function parseOpportunities( array $audits ): array
    {
        $opportunities = [];

        foreach ( $audits as $id => $audit ) {
            if ( ! is_array( $audit ) ) {
                continue;
            }

            $opportunity = $this->buildOpportunity( (string) $id, $audit );

            if ( null !== $opportunity ) {
                $opportunities[] = $opportunity;
            }
        }

        usort(
            $opportunities,
            static fn ( Opportunity $a, Opportunity $b ): int => $b->weight() <=> $a->weight(),
        );

        $limit = max( 0, $this->opportunitiesLimit );

        return $this->applyOpportunityFilter( array_slice( $opportunities, 0, $limit ) );
    }

    /**
     * Build an opportunity from an audit, or skip an audit that is not one.
     *
     * @since 1.0.0
     *
     * @param  string  $id  The audit id.
     * @param  array<string, mixed>  $audit  The audit body.
     *
     * @return Opportunity|null The opportunity, or null when the audit is not actionable.
     */
    protected function buildOpportunity( string $id, array $audit ): ?Opportunity
    {
        // Lighthouse's own marker for "this is not a scored, actionable
        // finding". Checked before the score, because these carry a null
        // score that is neither passing nor failing.
        if ( in_array( $audit[ 'scoreDisplayMode' ] ?? null, self::NON_ACTIONABLE_DISPLAY_MODES, true ) ) {
            return null;
        }

        $details       = is_array( $audit[ 'details' ] ?? null ) ? $audit[ 'details' ] : [];
        $metricSavings = $this->numericMap( $audit[ 'metricSavings' ] ?? null );
        $score         = is_numeric( $audit[ 'score' ] ?? null ) ? (float) $audit[ 'score' ] : null;

        // Keeps the five lab-metric audits out: they are measurements with a
        // millisecond value, not things you can fix.
        $isOpportunity = 'opportunity' === ( $details[ 'type' ] ?? null ) || [] !== $metricSavings;

        // A passing audit is not an opportunity even when Lighthouse still
        // attaches a savings estimate to it.
        if ( ! $isOpportunity || ( null !== $score && $score >= 1.0 ) ) {
            return null;
        }

        $opportunity = new Opportunity(
            id: $id,
            title: $this->stringOrNull( $audit[ 'title' ] ?? null ) ?? $id,
            score: $score,
            savingsMs: $this->savingsMs( $details ),
            displayValue: $this->stringOrNull( $audit[ 'displayValue' ] ?? null ),
            metricSavings: $metricSavings,
            description: $this->stringOrNull( $audit[ 'description' ] ?? null ),
        );

        // Lighthouse attaches all-zero savings to most audits on a healthy
        // page. Reporting those as opportunities would tell someone with a
        // perfect score that they have work to do.
        return $opportunity->weight() > 0.0 ? $opportunity : null;
    }

    /**
     * Hand the pruned list to consumers before it is returned.
     *
     * A filter that returns something other than a list of opportunities is
     * ignored rather than obeyed — a broken consumer should not be able to
     * corrupt a stored result — and the fact that it was ignored is logged.
     *
     * @since 1.0.0
     *
     * @param  array<int, Opportunity>  $opportunities  The pruned list.
     *
     * @return array<int, Opportunity> The filtered list.
     */
    protected function applyOpportunityFilter( array $opportunities ): array
    {
        if ( ! function_exists( 'applyFilters' ) ) {
            return $opportunities;
        }

        $filtered = applyFilters( self::FILTER_OPPORTUNITIES, $opportunities, $this->request );

        if ( ! is_array( $filtered ) ) {
            $this->logger->warning(
                'A callback on the ap.pageSpeed.opportunities filter returned a non-array value. The unfiltered opportunity list was used instead.',
                $this->request->logContext(),
            );

            return $opportunities;
        }

        $kept = array_values( array_filter(
            $filtered,
            static fn ( mixed $item ): bool => $item instanceof Opportunity,
        ) );

        if ( count( $kept ) !== count( $filtered ) ) {
            $this->logger->warning(
                'A callback on the ap.pageSpeed.opportunities filter added entries that are not Opportunity instances. They were dropped.',
                $this->request->logContext() + [ 'dropped' => count( $filtered ) - count( $kept ) ],
            );
        }

        return $kept;
    }

    /**
     * The estimated overall saving in milliseconds for an audit.
     *
     * Only `details.overallSavingsMs` is a saving. An audit's own
     * `numericValue` is a measurement even when its unit is milliseconds —
     * `mainthread-work-breakdown` reports how long the main thread was busy,
     * not how much time you would get back — so it is deliberately not used
     * as a fallback here. {@see Opportunity::weight()} falls back to the
     * largest per-metric estimate instead.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $details  The audit's details block.
     *
     * @return float|null The saving, or null when the audit reports none.
     */
    protected function savingsMs( array $details ): ?float
    {
        return is_numeric( $details[ 'overallSavingsMs' ] ?? null )
            ? (float) $details[ 'overallSavingsMs' ]
            : null;
    }

    /**
     * The URL Lighthouse actually measured, after redirects.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $lighthouse  The lighthouseResult block.
     *
     * @return string|null The final URL, or null when the response omitted it.
     */
    protected function finalUrl( array $lighthouse ): ?string
    {
        return $this->stringOrNull( $lighthouse[ 'finalDisplayedUrl' ] ?? null )
            ?? $this->stringOrNull( $lighthouse[ 'finalUrl' ] ?? null )
            ?? $this->stringOrNull( $lighthouse[ 'mainDocumentUrl' ] ?? null );
    }

    /**
     * When the analysis finished, per the API.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null The timestamp, or null when absent or unparseable.
     */
    protected function parseTimestamp(): ?CarbonImmutable
    {
        $timestamp = $this->stringOrNull( $this->payload[ 'analysisUTCTimestamp' ] ?? null );

        if ( null === $timestamp ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( $timestamp );
        } catch ( Exception $e ) {
            $this->logger->warning(
                'PageSpeed returned an analysis timestamp that could not be parsed.',
                $this->request->logContext() + [ 'timestamp' => $timestamp, 'error' => $e->getMessage() ],
            );

            return null;
        }
    }

    /**
     * Convert Lighthouse's 0-1 float score to a 0-100 integer.
     *
     * @since 1.0.0
     *
     * @param  mixed  $score  The raw score value.
     *
     * @return int|null The 0-100 score, or null when the category is unscored.
     */
    protected function toScore( mixed $score ): ?int
    {
        if ( ! is_numeric( $score ) ) {
            return null;
        }

        return (int) round( (float) $score * 100 );
    }

    /**
     * Keep only the numeric entries of a map, e.g. metricSavings.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw map.
     *
     * @return array<string, float> The numeric entries.
     */
    protected function numericMap( mixed $value ): array
    {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $map = [];

        foreach ( $value as $key => $entry ) {
            if ( is_numeric( $entry ) ) {
                $map[ (string) $key ] = (float) $entry;
            }
        }

        return $map;
    }

    /**
     * Coerce a scalar to a non-empty string, or null.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  The raw value.
     *
     * @return string|null The trimmed string, or null when it was empty or not a scalar.
     */
    protected function stringOrNull( mixed $value ): ?string
    {
        if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
            return null;
        }

        $string = trim( (string) $value );

        return '' === $string ? null : $string;
    }
}
