<?php

/**
 * Score and Core Web Vital banding.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Support;

/**
 * Where a number falls on Google's three-band scale, and what colour that is.
 *
 * Two scales live here rather than one, because they are genuinely different
 * measurements that happen to share a vocabulary:
 *
 * - **Lighthouse category scores** are 0-100 and band at 0-49 / 50-89 /
 *   90-100. Higher is better.
 * - **Core Web Vitals** are durations (or, for CLS, a unitless shift score)
 *   and band at per-metric thresholds. Lower is better.
 *
 * Both map onto the same three theme colours so that a red gauge and a red
 * vital mean the same thing to somebody scanning a dashboard.
 *
 * Null is not a band. A category that came back unscored and a vital CrUX has
 * no data for are absences, and colouring an absence green or red is how a
 * missing measurement gets read as a real one.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
final class ScoreBands
{
    /**
     * 90-100, or a vital inside its good threshold.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const GOOD = 'good';

    /**
     * 50-89, or a vital between its two thresholds.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NEEDS_IMPROVEMENT = 'needs-improvement';

    /**
     * 0-49, or a vital past its upper threshold.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const POOR = 'poor';

    /**
     * The lowest score that still counts as good.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const GOOD_FLOOR = 90;

    /**
     * The lowest score that still counts as needing improvement.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const NEEDS_IMPROVEMENT_FLOOR = 50;

    /**
     * The theme colour each band renders in.
     *
     * Named colours rather than hex so the cards follow whatever theme the
     * host application generated, and so a site with a red-tinted brand
     * palette does not end up with a "good" band that reads as an alarm.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const COLORS = [
        self::GOOD              => 'success',
        self::NEEDS_IMPROVEMENT => 'warning',
        self::POOR              => 'error',
    ];

    /**
     * The upper bound of each Core Web Vital's good and needs-improvement
     * bands, in the units CrUX reports the metric in.
     *
     * LCP and INP are milliseconds. CLS is reported by CrUX as the layout
     * shift score multiplied by 100 — a stored percentile of 4 is a CLS of
     * 0.04 — so its thresholds are 10 and 25 rather than 0.1 and 0.25.
     *
     * @since 1.0.0
     *
     * @var array<string, array{good: int, needsImprovement: int}>
     */
    public const VITAL_THRESHOLDS = [
        'largest_contentful_paint'  => [ 'good' => 2500, 'needsImprovement' => 4000 ],
        'interaction_to_next_paint' => [ 'good' => 200, 'needsImprovement' => 500 ],
        'cumulative_layout_shift'   => [ 'good' => 10, 'needsImprovement' => 25 ],
    ];

    /**
     * The band a 0-100 Lighthouse category score falls in.
     *
     * @since 1.0.0
     *
     * @param  int|null  $score  The 0-100 score, or null when unscored.
     *
     * @return string|null The band, or null when there is no score to band.
     */
    public static function forScore( ?int $score ): ?string
    {
        if ( null === $score ) {
            return null;
        }

        if ( $score >= self::GOOD_FLOOR ) {
            return self::GOOD;
        }

        return $score >= self::NEEDS_IMPROVEMENT_FLOOR ? self::NEEDS_IMPROVEMENT : self::POOR;
    }

    /**
     * The band a Core Web Vital measurement falls in.
     *
     * Unlike a score, lower is better here, so the comparison runs the other
     * way. A metric with no threshold on file — one this package does not
     * band, or a key CrUX adds later — is treated as unbandable rather than
     * being forced into the nearest band.
     *
     * @since 1.0.0
     *
     * @param  string  $metric  A canonical metric key from VITAL_THRESHOLDS.
     * @param  int|null  $value  The measurement, or null when CrUX had none.
     *
     * @return string|null The band, or null when there is nothing to band.
     */
    public static function forVital( string $metric, ?int $value ): ?string
    {
        $thresholds = self::VITAL_THRESHOLDS[ $metric ] ?? null;

        if ( null === $value || null === $thresholds ) {
            return null;
        }

        if ( $value <= $thresholds[ 'good' ] ) {
            return self::GOOD;
        }

        return $value <= $thresholds[ 'needsImprovement' ] ? self::NEEDS_IMPROVEMENT : self::POOR;
    }

    /**
     * The theme colour for a band.
     *
     * @since 1.0.0
     *
     * @param  string|null  $band  A band constant, or null.
     *
     * @return string|null The theme colour, or null when there is no band.
     */
    public static function color( ?string $band ): ?string
    {
        return null === $band ? null : ( self::COLORS[ $band ] ?? null );
    }

    /**
     * The band's name, written for a human.
     *
     * @since 1.0.0
     *
     * @param  string|null  $band  A band constant, or null.
     *
     * @return string|null The translated label, or null when there is no band.
     */
    public static function label( ?string $band ): ?string
    {
        return match ( $band ) {
            self::GOOD              => __( 'Good' ),
            self::NEEDS_IMPROVEMENT => __( 'Needs improvement' ),
            self::POOR              => __( 'Poor' ),
            default                 => null,
        };
    }
}
