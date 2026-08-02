<?php

/**
 * Lighthouse opportunity DTO.
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
 * One actionable Lighthouse audit with an estimated saving.
 *
 * A pruned extract, not the whole audit: the full tree is megabytes and its
 * tail is not actionable. `metricSavings` is Lighthouse's own estimate of
 * what fixing this would buy, per metric.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class Opportunity
{
    /**
     * Build the opportunity.
     *
     * @since 1.0.0
     *
     * @param  string  $id  The Lighthouse audit id.
     * @param  string  $title  The human-readable audit title.
     * @param  float|null  $score  The audit's 0-1 score, or null when unscored.
     * @param  float|null  $savingsMs  Estimated saving in milliseconds.
     * @param  string|null  $displayValue  Lighthouse's own localized summary.
     * @param  array<string, float|int>  $metricSavings  Per-metric savings estimates.
     * @param  string|null  $description  The audit's explanatory text.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?float $score = null,
        public readonly ?float $savingsMs = null,
        public readonly ?string $displayValue = null,
        public readonly array $metricSavings = [],
        public readonly ?string $description = null,
    ) {
    }

    /**
     * The largest single-metric saving Lighthouse estimated, used for
     * ordering when the audit has no top-level numeric value.
     *
     * @since 1.0.0
     *
     * @return float The largest estimated saving, or 0.0 when there is none.
     */
    public function largestMetricSaving(): float
    {
        $values = array_filter( $this->metricSavings, 'is_numeric' );

        return [] === $values ? 0.0 : (float) max( $values );
    }

    /**
     * How much this opportunity is worth, for sorting.
     *
     * @since 1.0.0
     *
     * @return float The estimated saving in milliseconds.
     */
    public function weight(): float
    {
        return $this->savingsMs ?? $this->largestMetricSaving();
    }

    /**
     * The opportunity as a plain array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Storage-ready representation.
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'score'          => $this->score,
            'savings_ms'     => $this->savingsMs,
            'display_value'  => $this->displayValue,
            'metric_savings' => $this->metricSavings,
            'description'    => $this->description,
        ];
    }
}
