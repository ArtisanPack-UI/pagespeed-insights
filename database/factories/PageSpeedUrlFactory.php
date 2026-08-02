<?php

/**
 * Monitored URL factory.
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
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for the monitored URL model.
 *
 * @extends Factory<PageSpeedUrl>
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedUrlFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @since 1.0.0
     *
     * @var class-string<PageSpeedUrl>
     */
    protected $model = PageSpeedUrl::class;

    /**
     * Define the model's default state.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The default attributes.
     */
    public function definition(): array
    {
        return [
            'url'            => rtrim( fake()->unique()->url(), '/' ) . '/' . fake()->slug(),
            'label'          => fake()->words( 3, true ),
            'source'         => PageSpeedUrl::SOURCE_MANUAL,
            'strategies'     => [ PageSpeedRequest::STRATEGY_MOBILE, PageSpeedRequest::STRATEGY_DESKTOP ],
            'is_active'      => true,
            'test_frequency' => null,
            'last_tested_at' => CarbonImmutable::now()->subHours( 2 ),
        ];
    }

    /**
     * A URL that is no longer monitored.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function inactive(): static
    {
        return $this->state( fn (): array => [ 'is_active' => false ] );
    }

    /**
     * A URL that has never been tested, and so is due immediately.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function neverTested(): static
    {
        return $this->state( fn (): array => [ 'last_tested_at' => null ] );
    }

    /**
     * A URL whose cadence has elapsed.
     *
     * @since 1.0.0
     *
     * @param  string  $frequency  The cadence to set.
     *
     * @return static The configured factory.
     */
    public function due( string $frequency = PageSpeedUrl::DEFAULT_FREQUENCY ): static
    {
        $minutes = PageSpeedUrl::FREQUENCY_INTERVALS[ $frequency ]
            ?? PageSpeedUrl::FREQUENCY_INTERVALS[ PageSpeedUrl::DEFAULT_FREQUENCY ];

        return $this->state( fn (): array => [
            'test_frequency' => $frequency,
            'last_tested_at' => CarbonImmutable::now()->subMinutes( $minutes + 1 ),
        ] );
    }

    /**
     * A URL tested recently enough that it is not yet owed a run.
     *
     * @since 1.0.0
     *
     * @param  string  $frequency  The cadence to set.
     *
     * @return static The configured factory.
     */
    public function notDue( string $frequency = PageSpeedUrl::DEFAULT_FREQUENCY ): static
    {
        return $this->state( fn (): array => [
            'test_frequency' => $frequency,
            'last_tested_at' => CarbonImmutable::now()->subMinute(),
        ] );
    }

    /**
     * A URL discovered from the site's sitemap.
     *
     * @since 1.0.0
     *
     * @return static The configured factory.
     */
    public function fromSitemap(): static
    {
        return $this->state( fn (): array => [ 'source' => PageSpeedUrl::SOURCE_SITEMAP ] );
    }

    /**
     * A URL tested on one form factor only.
     *
     * @since 1.0.0
     *
     * @param  string  $strategy  The form factor to keep.
     *
     * @return static The configured factory.
     */
    public function strategy( string $strategy = PageSpeedRequest::STRATEGY_MOBILE ): static
    {
        return $this->state( fn (): array => [ 'strategies' => [ $strategy ] ] );
    }
}
