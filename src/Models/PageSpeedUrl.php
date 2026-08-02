<?php

/**
 * Monitored URL model.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Models;

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Database\Factories\PageSpeedUrlFactory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL this installation tests on a schedule.
 *
 * One row per URL, not per URL-and-form-factor: `strategies` says which form
 * factors to run, so switching a page from mobile-only to both does not
 * fragment its history across two rows.
 *
 * @property int $id
 * @property string $url
 * @property string|null $label
 * @property string $source
 * @property array<int, string> $strategies
 * @property bool $is_active
 * @property string|null $test_frequency
 * @property CarbonImmutable|null $last_tested_at
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedUrl extends Model
{
    use HasFactory;

    /**
     * Added by an operator through the UI or the API.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Discovered by crawling the site's sitemap.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SOURCE_SITEMAP = 'sitemap';

    /**
     * Contributed by another package through a hook.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SOURCE_HOOK = 'hook';

    /**
     * Every recognized value of the `source` column.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SOURCES = [ self::SOURCE_MANUAL, self::SOURCE_SITEMAP, self::SOURCE_HOOK ];

    /**
     * The cadences {@see self::scopeDue()} understands, mapped to the number
     * of minutes that must pass between runs.
     *
     * A value the map has no entry for — a cadence a later version added, or
     * a typo — is treated as the package default rather than being skipped
     * forever, because a URL that silently stops being tested is worse than
     * one tested on the wrong cadence.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    public const FREQUENCY_INTERVALS = [
        'hourly'  => 60,
        'daily'   => 1440,
        'weekly'  => 10080,
        'monthly' => 43200,
    ];

    /**
     * The cadence used when a row has no override and the package is not
     * configured otherwise.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_FREQUENCY = 'weekly';

    /**
     * The table associated with the model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'pagespeed_urls';

    /**
     * The attributes that are mass assignable.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'url',
        'label',
        'source',
        'strategies',
        'is_active',
        'test_frequency',
        'last_tested_at',
    ];

    /**
     * The model's default attribute values.
     *
     * `strategies` is defaulted here rather than in the schema because
     * SQLite cannot default a json column.
     *
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source'     => self::SOURCE_MANUAL,
        'is_active'  => true,
        'strategies' => '["mobile","desktop"]',
    ];

    /**
     * The attributes that should be cast.
     *
     * Declared as a property rather than the `casts()` method so the casts
     * apply on Laravel 10, which has no such method.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    protected $casts = [
        'strategies'     => 'array',
        'is_active'      => 'boolean',
        'last_tested_at' => 'immutable_datetime',
    ];

    /**
     * A stored result for this URL.
     *
     * @since 1.0.0
     *
     * @return HasMany<PageSpeedResult, $this> The result history, newest last.
     */
    public function results(): HasMany
    {
        return $this->hasMany( PageSpeedResult::class, 'pagespeed_url_id' );
    }

    /**
     * Only URLs that are still being monitored.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedUrl>  $query  The query to constrain.
     *
     * @return Builder<PageSpeedUrl> The constrained query.
     */
    public function scopeActive( Builder $query ): Builder
    {
        return $query->where( 'is_active', true );
    }

    /**
     * Active URLs whose next scheduled run is owed.
     *
     * A URL is due when it has never been tested, or when its own cadence —
     * falling back to the package default — has elapsed since
     * `last_tested_at`. The comparison happens in SQL rather than in PHP so
     * a large monitored set does not have to be hydrated to find the handful
     * that are owed a run.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedUrl>  $query  The query to constrain.
     * @param  CarbonInterface|null  $now  The moment to measure from; defaults to now.
     *
     * @return Builder<PageSpeedUrl> The constrained query.
     */
    public function scopeDue( Builder $query, ?CarbonInterface $now = null ): Builder
    {
        $now      = null === $now ? CarbonImmutable::now() : CarbonImmutable::instance( $now );
        $fallback = self::defaultFrequency();
        $known    = array_keys( self::FREQUENCY_INTERVALS );

        return $query->active()->where( function ( Builder $due ) use ( $now, $fallback, $known ): void {
            $due->whereNull( 'last_tested_at' );

            foreach ( self::FREQUENCY_INTERVALS as $frequency => $minutes ) {
                $threshold = $now->subMinutes( $minutes );

                $due->orWhere( function ( Builder $branch ) use ( $frequency, $fallback, $threshold ): void {
                    $branch->where( function ( Builder $matches ) use ( $frequency, $fallback ): void {
                        $matches->where( 'test_frequency', $frequency );

                        // Rows with no override ride on whichever cadence is
                        // configured as the default.
                        if ( $frequency === $fallback ) {
                            $matches->orWhereNull( 'test_frequency' );
                        }
                    } )->where( 'last_tested_at', '<=', $threshold );
                } );
            }

            // An unrecognized cadence is treated as the default rather than
            // never coming due.
            $due->orWhere( function ( Builder $branch ) use ( $now, $fallback, $known ): void {
                $branch->whereNotNull( 'test_frequency' )
                    ->whereNotIn( 'test_frequency', $known )
                    ->where( 'last_tested_at', '<=', $now->subMinutes( self::FREQUENCY_INTERVALS[ $fallback ] ) );
            } );
        } );
    }

    /**
     * The row for one exact URL.
     *
     * @since 1.0.0
     *
     * @param  Builder<PageSpeedUrl>  $query  The query to constrain.
     * @param  string  $url  The URL to match.
     *
     * @return Builder<PageSpeedUrl> The constrained query.
     */
    public function scopeForUrl( Builder $query, string $url ): Builder
    {
        return $query->where( 'url', trim( $url ) );
    }

    /**
     * How often this URL should be tested, resolving its override against
     * the package default.
     *
     * @since 1.0.0
     *
     * @return string A key of {@see self::FREQUENCY_INTERVALS}.
     */
    public function frequency(): string
    {
        $frequency = $this->test_frequency;

        if ( null === $frequency || ! array_key_exists( $frequency, self::FREQUENCY_INTERVALS ) ) {
            return self::defaultFrequency();
        }

        return $frequency;
    }

    /**
     * Whether this URL is owed a run right now.
     *
     * The single-row counterpart to {@see self::scopeDue()}; the two answer
     * the same question and must agree.
     *
     * @since 1.0.0
     *
     * @param  CarbonInterface|null  $now  The moment to measure from; defaults to now.
     *
     * @return bool True when a run is owed.
     */
    public function isDue( ?CarbonInterface $now = null ): bool
    {
        if ( ! $this->is_active ) {
            return false;
        }

        if ( null === $this->last_tested_at ) {
            return true;
        }

        $now = null === $now ? CarbonImmutable::now() : CarbonImmutable::instance( $now );

        return $this->last_tested_at->lessThanOrEqualTo(
            $now->subMinutes( self::FREQUENCY_INTERVALS[ $this->frequency() ] ),
        );
    }

    /**
     * The form factors to test this URL on.
     *
     * Guards against a row whose json was emptied or corrupted: a monitored
     * URL with no strategies would simply never be tested. Named apart from
     * the `strategies` attribute so that reading the raw column and reading
     * the validated list are not the same expression.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Valid strategy names.
     */
    public function effectiveStrategies(): array
    {
        $strategies = array_values( array_filter(
            (array) ( $this->strategies ?? [] ),
            static fn ( mixed $strategy ): bool => in_array(
                $strategy,
                [ PageSpeedRequest::STRATEGY_MOBILE, PageSpeedRequest::STRATEGY_DESKTOP ],
                true,
            ),
        ) );

        return [] === $strategies
            ? [ PageSpeedRequest::STRATEGY_MOBILE, PageSpeedRequest::STRATEGY_DESKTOP ]
            : $strategies;
    }

    /**
     * The package-wide default cadence, validated against the known set.
     *
     * @since 1.0.0
     *
     * @return string A key of {@see self::FREQUENCY_INTERVALS}.
     */
    public static function defaultFrequency(): string
    {
        $configured = config( 'pagespeed-insights.test_frequency', self::DEFAULT_FREQUENCY );

        if ( ! is_string( $configured ) || ! array_key_exists( $configured, self::FREQUENCY_INTERVALS ) ) {
            return self::DEFAULT_FREQUENCY;
        }

        return $configured;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @since 1.0.0
     *
     * @return PageSpeedUrlFactory The model factory.
     */
    protected static function newFactory(): PageSpeedUrlFactory
    {
        return PageSpeedUrlFactory::new();
    }
}
