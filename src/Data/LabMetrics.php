<?php

/**
 * Lighthouse lab metrics.
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
 * The five lab metrics that make up the Lighthouse performance score.
 *
 * Weights as of Lighthouse 10, unchanged since: TBT 30%, LCP 25%, CLS 25%,
 * FCP 10%, Speed Index 10%. Time to Interactive was removed in Lighthouse 10
 * and is deliberately absent.
 *
 * Each metric carries the numeric value (milliseconds, or unitless for CLS)
 * and Lighthouse's own localized display string. Any metric the response did
 * not contain is null on both, and its audit id is listed in
 * {@see self::missing()} so a thin result can be explained instead of guessed
 * at.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class LabMetrics
{
    /**
     * The Lighthouse audit ids these metrics come from, in the order the
     * package reports them.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const AUDIT_IDS = [
        'first-contentful-paint',
        'largest-contentful-paint',
        'total-blocking-time',
        'cumulative-layout-shift',
        'speed-index',
    ];

    /**
     * Build the metric set.
     *
     * @since 1.0.0
     *
     * @param  array<string, array{value: float|null, display: string|null}>  $metrics  Audit id to value pair.
     * @param  array<int, string>  $missing  Audit ids the response did not carry.
     */
    public function __construct(
        protected array $metrics = [],
        protected array $missing = [],
    ) {
    }

    /**
     * First Contentful Paint, in milliseconds.
     *
     * @since 1.0.0
     *
     * @return float|null The measured value, or null when absent.
     */
    public function firstContentfulPaint(): ?float
    {
        return $this->value( 'first-contentful-paint' );
    }

    /**
     * Largest Contentful Paint, in milliseconds.
     *
     * @since 1.0.0
     *
     * @return float|null The measured value, or null when absent.
     */
    public function largestContentfulPaint(): ?float
    {
        return $this->value( 'largest-contentful-paint' );
    }

    /**
     * Total Blocking Time, in milliseconds.
     *
     * @since 1.0.0
     *
     * @return float|null The measured value, or null when absent.
     */
    public function totalBlockingTime(): ?float
    {
        return $this->value( 'total-blocking-time' );
    }

    /**
     * Cumulative Layout Shift, unitless.
     *
     * @since 1.0.0
     *
     * @return float|null The measured value, or null when absent.
     */
    public function cumulativeLayoutShift(): ?float
    {
        return $this->value( 'cumulative-layout-shift' );
    }

    /**
     * Speed Index, in milliseconds.
     *
     * @since 1.0.0
     *
     * @return float|null The measured value, or null when absent.
     */
    public function speedIndex(): ?float
    {
        return $this->value( 'speed-index' );
    }

    /**
     * The numeric value of any lab metric by audit id.
     *
     * @since 1.0.0
     *
     * @param  string  $auditId  The Lighthouse audit id.
     *
     * @return float|null The measured value, or null when absent.
     */
    public function value( string $auditId ): ?float
    {
        return $this->metrics[ $auditId ][ 'value' ] ?? null;
    }

    /**
     * Lighthouse's own localized display string for a metric, e.g. "1.2 s".
     *
     * @since 1.0.0
     *
     * @param  string  $auditId  The Lighthouse audit id.
     *
     * @return string|null The display string, or null when absent.
     */
    public function display( string $auditId ): ?string
    {
        return $this->metrics[ $auditId ][ 'display' ] ?? null;
    }

    /**
     * Every metric the response carried.
     *
     * @since 1.0.0
     *
     * @return array<string, array{value: float|null, display: string|null}> Audit id to value pair.
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * Audit ids that were expected but absent from the response.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Missing Lighthouse audit ids.
     */
    public function missing(): array
    {
        return $this->missing;
    }

    /**
     * The metrics as a plain array for storage.
     *
     * @since 1.0.0
     *
     * @return array<string, array{value: float|null, display: string|null}> Audit id to value pair.
     */
    public function toArray(): array
    {
        return $this->all();
    }
}
