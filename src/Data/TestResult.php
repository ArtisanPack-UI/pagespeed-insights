<?php

/**
 * Aggregate PageSpeed test result DTO.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Data;

use Carbon\CarbonImmutable;

/**
 * Everything one PageSpeed run produced, in one object.
 *
 * The parser tolerates a lot — unknown categories, absent categories, missing
 * lab metrics, no CrUX data — because a single oddity should not take down a
 * monitoring cycle. Tolerating is not hiding, so everything that was
 * tolerated is carried here as well as logged: `unrecognizedCategories`,
 * `missingCategories`, `missingMetrics`, and Lighthouse's own `runWarnings`.
 * Storage, the CLI, and the UI read those instead of each re-deriving "this
 * result looks thin" from gaps in the data.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TestResult
{
    /**
     * Build the result.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL that was requested.
     * @param  string  $strategy  mobile or desktop.
     * @param  ScoreSet  $scores  The category scores.
     * @param  LabMetrics  $labMetrics  The Lighthouse lab metrics.
     * @param  FieldData|null  $fieldData  Page-level CrUX data, when available.
     * @param  FieldData|null  $originFieldData  Origin-level CrUX data, when available.
     * @param  array<int, Opportunity>  $opportunities  Pruned, filtered opportunities.
     * @param  string|null  $finalUrl  The URL Lighthouse ended up on after redirects.
     * @param  string|null  $lighthouseVersion  The Lighthouse version that ran.
     * @param  CarbonImmutable|null  $analyzedAt  When the run finished, per the API.
     * @param  array<int, string>  $runWarnings  Lighthouse's own warnings about the run.
     * @param  array<int, string>  $unrecognizedCategories  Categories with no typed accessor.
     * @param  array<int, string>  $missingCategories  Requested categories the response omitted.
     * @param  array<int, string>  $missingMetrics  Lab metric audit ids the response omitted.
     * @param  array<string, mixed>|null  $raw  The raw response, only when explicitly retained.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $strategy,
        public readonly ScoreSet $scores,
        public readonly LabMetrics $labMetrics,
        public readonly ?FieldData $fieldData = null,
        public readonly ?FieldData $originFieldData = null,
        public readonly array $opportunities = [],
        public readonly ?string $finalUrl = null,
        public readonly ?string $lighthouseVersion = null,
        public readonly ?CarbonImmutable $analyzedAt = null,
        public readonly array $runWarnings = [],
        public readonly array $unrecognizedCategories = [],
        public readonly array $missingCategories = [],
        public readonly array $missingMetrics = [],
        public readonly ?array $raw = null,
    ) {
    }

    /**
     * Whether CrUX had real-user data for this page or its origin.
     *
     * @since 1.0.0
     *
     * @return bool True when either field data set is present.
     */
    public function hasFieldData(): bool
    {
        return null !== $this->fieldData || null !== $this->originFieldData;
    }

    /**
     * The field data to show: page level when CrUX had it, origin level
     * otherwise.
     *
     * @since 1.0.0
     *
     * @return FieldData|null The best available field data, or null when CrUX had none.
     */
    public function effectiveFieldData(): ?FieldData
    {
        return $this->fieldData ?? $this->originFieldData;
    }

    /**
     * Whether the parser had to tolerate anything, or Lighthouse warned about
     * the run.
     *
     * @since 1.0.0
     *
     * @return bool True when this result is worth explaining to a developer.
     */
    public function hasWarnings(): bool
    {
        return [] !== $this->runWarnings
            || [] !== $this->unrecognizedCategories
            || [] !== $this->missingCategories
            || [] !== $this->missingMetrics
            || ! $this->hasFieldData();
    }

    /**
     * Everything the parser tolerated plus Lighthouse's own warnings, ready
     * for the `warnings` column on a stored result.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Structured warning record.
     */
    public function warnings(): array
    {
        return [
            'run_warnings'            => $this->runWarnings,
            'unrecognized_categories' => $this->unrecognizedCategories,
            'missing_categories'      => $this->missingCategories,
            'missing_metrics'         => $this->missingMetrics,
            'missing_field_data'      => ! $this->hasFieldData(),
        ];
    }

    /**
     * The result as a plain array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Storage-ready representation.
     */
    public function toArray(): array
    {
        return [
            'url'                => $this->url,
            'strategy'           => $this->strategy,
            'final_url'          => $this->finalUrl,
            'lighthouse_version' => $this->lighthouseVersion,
            'analyzed_at'        => $this->analyzedAt?->toIso8601String(),
            'scores'             => $this->scores->toArray(),
            'lab_metrics'        => $this->labMetrics->toArray(),
            'field_data'         => $this->fieldData?->toArray(),
            'origin_field_data'  => $this->originFieldData?->toArray(),
            'opportunities'      => array_map(
                static fn ( Opportunity $opportunity ): array => $opportunity->toArray(),
                $this->opportunities,
            ),
            'warnings'           => $this->warnings(),
        ];
    }
}
