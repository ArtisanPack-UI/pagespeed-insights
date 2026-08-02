<?php

/**
 * Chrome UX Report field data.
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

/**
 * Real-user (CrUX) measurements embedded in a PageSpeed response.
 *
 * Built from either `loadingExperience` (page level) or
 * `originLoadingExperience` (origin level); {@see self::isOriginLevel()} says
 * which. CrUX has no data for low-traffic pages, so both can be absent and
 * callers must handle a null FieldData.
 *
 * `loadingExperience.metrics` is an open map in the API schema — the keys are
 * not enumerated anywhere — so every key present is kept as-is and the
 * Core Web Vitals accessors match against the spellings PageSpeed has been
 * observed to use.
 *
 * ### On the percentile
 *
 * Each metric carries a `percentile`, and it is the **75th** — see
 * {@see self::PERCENTILE}.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class FieldData
{
    /**
     * Which percentile each metric's `percentile` value represents.
     *
     * Confirmed 2026-08-02 against live keyed responses, which settles a
     * conflict in the documentation: the API discovery document claims "for
     * v5, this field contains pc90", while CrUX's own docs say Core Web
     * Vitals are reported at p75.
     *
     * The responses agree with CrUX. Each metric's reported value can be
     * located in its own `distributions` buckets, which bounds the percentile
     * rank it must correspond to. Across 20 metrics from real page-level and
     * origin-level datasets, every bound was consistent with p75 and 8 were
     * flatly incompatible with p90 — the value sat in a bucket whose
     * cumulative proportion had not yet reached 0.90, so the 90th percentile
     * would necessarily have been a larger number. The discovery document's
     * description is stale.
     *
     * This matters because labelling a p75 as a p90 (or the reverse) is a
     * silent correctness bug: the numbers still render, they just mean
     * something else.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const PERCENTILE = 75;

    /**
     * Response keys, in observed order of likelihood, for each Core Web
     * Vital. PageSpeed's embedded CrUX data uses upper-snake keys — confirmed
     * live on 2026-08-02 — while the standalone CrUX API uses lower-snake, so
     * both are matched rather than betting on one.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const METRIC_ALIASES = [
        'largest_contentful_paint'        => [ 'LARGEST_CONTENTFUL_PAINT_MS', 'largest_contentful_paint' ],
        'interaction_to_next_paint'       => [ 'INTERACTION_TO_NEXT_PAINT', 'interaction_to_next_paint' ],
        'cumulative_layout_shift'         => [ 'CUMULATIVE_LAYOUT_SHIFT_SCORE', 'cumulative_layout_shift' ],
        'first_contentful_paint'          => [ 'FIRST_CONTENTFUL_PAINT_MS', 'first_contentful_paint' ],
        'experimental_time_to_first_byte' => [ 'EXPERIMENTAL_TIME_TO_FIRST_BYTE', 'experimental_time_to_first_byte' ],
    ];

    /**
     * Build the field data set.
     *
     * @since 1.0.0
     *
     * @param  array<string, array{percentile: int|null, category: string|null, distributions: array<int, mixed>}>  $metrics  Raw metric key to measurement.
     * @param  string|null  $overallCategory  FAST, AVERAGE, SLOW, or null.
     * @param  string|null  $id  The URL or origin the data describes.
     * @param  bool  $originFallback  Whether CrUX substituted origin-level data.
     * @param  bool  $isOriginLevel  Whether this came from originLoadingExperience.
     */
    public function __construct(
        protected array $metrics = [],
        protected ?string $overallCategory = null,
        protected ?string $id = null,
        protected bool $originFallback = false,
        protected bool $isOriginLevel = false,
    ) {
    }

    /**
     * Largest Contentful Paint.
     *
     * @since 1.0.0
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when CrUX had none.
     */
    public function largestContentfulPaint(): ?array
    {
        return $this->metric( 'largest_contentful_paint' );
    }

    /**
     * Interaction to Next Paint.
     *
     * @since 1.0.0
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when CrUX had none.
     */
    public function interactionToNextPaint(): ?array
    {
        return $this->metric( 'interaction_to_next_paint' );
    }

    /**
     * Cumulative Layout Shift.
     *
     * @since 1.0.0
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when CrUX had none.
     */
    public function cumulativeLayoutShift(): ?array
    {
        return $this->metric( 'cumulative_layout_shift' );
    }

    /**
     * First Contentful Paint.
     *
     * @since 1.0.0
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when CrUX had none.
     */
    public function firstContentfulPaint(): ?array
    {
        return $this->metric( 'first_contentful_paint' );
    }

    /**
     * Time to First Byte.
     *
     * @since 1.0.0
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when CrUX had none.
     */
    public function timeToFirstByte(): ?array
    {
        return $this->metric( 'experimental_time_to_first_byte' );
    }

    /**
     * A measurement by canonical name or by the exact response key.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A key from METRIC_ALIASES, or a raw response key.
     *
     * @return array{percentile: int|null, category: string|null, distributions: array<int, mixed>}|null The measurement, or null when absent.
     */
    public function metric( string $metric ): ?array
    {
        if ( isset( $this->metrics[ $metric ] ) ) {
            return $this->metrics[ $metric ];
        }

        foreach ( self::METRIC_ALIASES[ $metric ] ?? [] as $alias ) {
            if ( isset( $this->metrics[ $alias ] ) ) {
                return $this->metrics[ $alias ];
            }
        }

        return null;
    }

    /**
     * The 75th-percentile value for a metric, in the metric's own units.
     *
     * @since 1.0.0
     * @see self::PERCENTILE For the evidence that this is p75 and not p90.
     *
     * @param  string  $metric  A key from METRIC_ALIASES, or a raw response key.
     *
     * @return int|null The percentile value, or null when the metric is absent.
     */
    public function percentile( string $metric ): ?int
    {
        return $this->metric( $metric )[ 'percentile' ] ?? null;
    }

    /**
     * CrUX's own verdict for a metric: FAST, AVERAGE, or SLOW.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A key from METRIC_ALIASES, or a raw response key.
     *
     * @return string|null The category, or null when the metric is absent.
     */
    public function category( string $metric ): ?string
    {
        return $this->metric( $metric )[ 'category' ] ?? null;
    }

    /**
     * CrUX's overall verdict for the page or origin.
     *
     * @since 1.0.0
     *
     * @return string|null FAST, AVERAGE, SLOW, or null.
     */
    public function overallCategory(): ?string
    {
        return $this->overallCategory;
    }

    /**
     * The URL or origin this data describes.
     *
     * @since 1.0.0
     *
     * @return string|null The identifier, or null when the response omitted it.
     */
    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * Whether CrUX had no page-level data and substituted origin-level data.
     *
     * @since 1.0.0
     *
     * @return bool True when the numbers describe the origin, not the page.
     */
    public function isOriginFallback(): bool
    {
        return $this->originFallback;
    }

    /**
     * Whether this set was built from `originLoadingExperience`.
     *
     * @since 1.0.0
     *
     * @return bool True for the origin-level set.
     */
    public function isOriginLevel(): bool
    {
        return $this->isOriginLevel;
    }

    /**
     * Every measurement, keyed by the raw response key.
     *
     * @since 1.0.0
     *
     * @return array<string, array{percentile: int|null, category: string|null, distributions: array<int, mixed>}> Raw key to measurement.
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * The field data as a plain array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Storage-ready representation.
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'overall_category' => $this->overallCategory,
            'origin_fallback'  => $this->originFallback,
            'origin_level'     => $this->isOriginLevel,
            'metrics'          => $this->metrics,
        ];
    }
}
