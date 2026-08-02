<?php

/**
 * Stored PageSpeed result factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Database\Factories;

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the stored result model.
 *
 * The states worth having fixtures for are the ones that mislead: a failed
 * run, a run with no CrUX data, a poor-scoring page, and a degraded run that
 * completed while quietly losing data. A trend chart drawn from the last of
 * those looks like a page that got worse.
 *
 * @extends Factory<PageSpeedResult>
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedResultFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @since 1.0.0
     *
     * @var class-string<PageSpeedResult>
     */
    protected $model = PageSpeedResult::class;

    /**
     * Define the model's default state: a healthy, complete run.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        $url = rtrim( fake()->url(), '/' ) . '/' . fake()->slug();

        return [
            'pagespeed_url_id'     => null,
            'url'                  => $url,
            'final_url'            => $url,
            'strategy'             => PageSpeedRequest::STRATEGY_MOBILE,
            'performance_score'    => fake()->numberBetween( 90, 100 ),
            'accessibility_score'  => fake()->numberBetween( 90, 100 ),
            'best_practices_score' => fake()->numberBetween( 90, 100 ),
            'seo_score'            => fake()->numberBetween( 90, 100 ),
            'lab_metrics'          => self::labMetrics( 1.0 ),
            'field_data'           => self::fieldData( false ),
            'origin_field_data'    => self::fieldData( true ),
            'opportunities'        => [],
            'warnings'             => self::warnings(),
            'lighthouse_version'   => '12.2.1',
            'raw_response'         => null,
            'status'               => PageSpeedResult::STATUS_COMPLETED,
            'error_message'        => null,
            'fetched_at'           => CarbonImmutable::now(),
        ];
    }

    /**
     * A run that never produced a result.
     *
     * Every measurement column is null, which is the whole point: a failed
     * run must not be averaged into a trend as a zero.
     *
     * @since 1.0.0
     *
     * @param  string|null  $message  The failure message.
     *
     * @return static The configured factory.
     */
    public function failed( ?string $message = null ): static
    {
        return $this->state( fn (): array => [
            'performance_score'    => null,
            'accessibility_score'  => null,
            'best_practices_score' => null,
            'seo_score'            => null,
            'lab_metrics'          => null,
            'field_data'           => null,
            'origin_field_data'    => null,
            'opportunities'        => null,
            'warnings'             => null,
            'lighthouse_version'   => null,
            'status'               => PageSpeedResult::STATUS_FAILED,
            'error_message'        => $message ?? 'PageSpeed returned HTTP 500 for this URL.',
        ] );
    }

    /**
     * A completed run for a page CrUX has no real-user data for. Ordinary
     * for low-traffic pages.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function withoutFieldData(): static
    {
        return $this->state( fn (): array => [
            'field_data'        => null,
            'origin_field_data' => null,
            'warnings'          => self::warnings( missingFieldData: true ),
        ] );
    }

    /**
     * A page scoring badly, with opportunities to match.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function poor(): static
    {
        return $this->state( fn (): array => [
            'performance_score'    => fake()->numberBetween( 5, 35 ),
            'accessibility_score'  => fake()->numberBetween( 30, 60 ),
            'best_practices_score' => fake()->numberBetween( 30, 60 ),
            'seo_score'            => fake()->numberBetween( 30, 70 ),
            'lab_metrics'          => self::labMetrics( 4.5 ),
            'opportunities'        => [
                [
                    'id'             => 'render-blocking-resources',
                    'title'          => 'Eliminate render-blocking resources',
                    'score'          => 0.12,
                    'savings_ms'     => 2100.0,
                    'display_value'  => 'Potential savings of 2,100 ms',
                    'metric_savings' => [ 'FCP' => 1200.0, 'LCP' => 2100.0 ],
                    'description'    => 'Resources are blocking the first paint of your page.',
                ],
                [
                    'id'             => 'unused-javascript',
                    'title'          => 'Reduce unused JavaScript',
                    'score'          => 0.31,
                    'savings_ms'     => 900.0,
                    'display_value'  => 'Potential savings of 412 KiB',
                    'metric_savings' => [ 'LCP' => 900.0 ],
                    'description'    => 'Reduce unused JavaScript and defer loading scripts.',
                ],
            ],
        ] );
    }

    /**
     * A run that completed while quietly losing data.
     *
     * Two of the four categories never came back, a lab metric is missing,
     * and Lighthouse itself warned about the page — yet the row reads as a
     * success. This is the state that misleads, so it is the one worth
     * having a fixture for.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function degraded(): static
    {
        return $this->state( fn (): array => [
            'best_practices_score' => null,
            'seo_score'            => null,
            'lab_metrics'          => array_diff_key( self::labMetrics( 1.0 ), [ 'speed-index' => null ] ),
            'warnings'             => self::warnings(
                runWarnings: [ 'The page may not be loading as expected because your test URL got redirected.' ],
                missingCategories: [ 'best-practices', 'seo' ],
                unrecognizedCategories: [ 'agentic-browsing' ],
                missingMetrics: [ 'speed-index' ],
            ),
        ] );
    }

    /**
     * A run whose raw payload was retained.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|null  $payload  The payload to store.
     *
     * @return static The configured factory.
     */
    public function withRawResponse( ?array $payload = null ): static
    {
        return $this->state( fn ( array $attributes ): array => [
            'raw_response' => $payload ?? [
                'id'               => $attributes[ 'url' ] ?? null,
                'lighthouseResult' => [ 'lighthouseVersion' => $attributes[ 'lighthouse_version' ] ?? null ],
            ],
        ] );
    }

    /**
     * A run on the desktop form factor.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function desktop(): static
    {
        return $this->state( fn (): array => [ 'strategy' => PageSpeedRequest::STRATEGY_DESKTOP ] );
    }

    /**
     * A lab metric set, scaled so a healthy run and a slow one differ by
     * more than noise.
     *
     * @since 1.0.0
     *
     * @param  float  $factor  Multiplier applied to the healthy baseline.
     *
     * @return array<string, array{value: float, display: string}> The metric set.
     */
    protected static function labMetrics( float $factor ): array
    {
        $milliseconds = static fn ( float $base ): array => [
            'value'   => round( $base * $factor, 1 ),
            'display' => round( $base * $factor / 1000, 1 ) . ' s',
        ];

        return [
            'first-contentful-paint'   => $milliseconds( 1100.0 ),
            'largest-contentful-paint' => $milliseconds( 1800.0 ),
            'total-blocking-time'      => $milliseconds( 90.0 ),
            'cumulative-layout-shift'  => [
                'value'   => round( 0.02 * $factor, 3 ),
                'display' => (string) round( 0.02 * $factor, 3 ),
            ],
            'speed-index'              => $milliseconds( 1600.0 ),
        ];
    }

    /**
     * A CrUX field data set in its stored shape.
     *
     * The percentile values are the 75th — see {@see FieldData::PERCENTILE}.
     *
     * @since 1.0.0
     *
     * @param  bool  $originLevel  Whether this is the origin-level set.
     *
     * @return array<string, mixed> The stored shape.
     */
    protected static function fieldData( bool $originLevel ): array
    {
        return [
            'id'               => $originLevel ? 'https://example.com' : 'https://example.com/page',
            'overall_category' => 'FAST',
            'origin_fallback'  => false,
            'origin_level'     => $originLevel,
            'metrics'          => [
                'LARGEST_CONTENTFUL_PAINT_MS'   => [
                    'percentile'    => 1900,
                    'category'      => 'FAST',
                    'distributions' => [
                        [ 'min' => 0, 'max' => 2500, 'proportion' => 0.82 ],
                        [ 'min' => 2500, 'max' => 4000, 'proportion' => 0.12 ],
                        [ 'min' => 4000, 'proportion' => 0.06 ],
                    ],
                ],
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => [
                    'percentile'    => 4,
                    'category'      => 'FAST',
                    'distributions' => [
                        [ 'min' => 0, 'max' => 10, 'proportion' => 0.91 ],
                        [ 'min' => 10, 'max' => 25, 'proportion' => 0.06 ],
                        [ 'min' => 25, 'proportion' => 0.03 ],
                    ],
                ],
                'INTERACTION_TO_NEXT_PAINT'     => [
                    'percentile'    => 140,
                    'category'      => 'FAST',
                    'distributions' => [
                        [ 'min' => 0, 'max' => 200, 'proportion' => 0.88 ],
                        [ 'min' => 200, 'max' => 500, 'proportion' => 0.09 ],
                        [ 'min' => 500, 'proportion' => 0.03 ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A warnings record in its stored shape.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $runWarnings  Lighthouse's own warnings.
     * @param  array<int, string>  $missingCategories  Requested categories the response omitted.
     * @param  array<int, string>  $unrecognizedCategories  Categories with no column.
     * @param  array<int, string>  $missingMetrics  Lab metric audit ids the response omitted.
     * @param  bool  $missingFieldData  Whether CrUX had no data at all.
     *
     * @return array<string, mixed> The stored shape.
     */
    protected static function warnings(
        array $runWarnings = [],
        array $missingCategories = [],
        array $unrecognizedCategories = [],
        array $missingMetrics = [],
        bool $missingFieldData = false,
    ): array {
        return [
            'run_warnings'            => $runWarnings,
            'unrecognized_categories' => $unrecognizedCategories,
            'missing_categories'      => $missingCategories,
            'missing_metrics'         => $missingMetrics,
            'missing_field_data'      => $missingFieldData,
        ];
    }
}
