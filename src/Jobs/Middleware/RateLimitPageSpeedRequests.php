<?php

/**
 * PageSpeed request rate limiting job middleware.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Jobs\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;

/**
 * Keeps the whole application under a PageSpeed request budget per minute.
 *
 * The budget is shared: the limiter key is fixed rather than derived from the
 * URL, because Google's quota is attributed to the API key, not to whatever
 * page is being tested. Counting per URL would let fifty workers each testing
 * a different page sail past a limit none of them individually broke.
 *
 * A job over the budget is released back onto the queue with the limiter's
 * own retry-after rather than being dropped or failed, so a large scheduled
 * cycle spreads itself out over the following minutes instead of burning its
 * retries against a wall.
 *
 * Deliberately not built on {@see \Illuminate\Queue\Middleware\RateLimited},
 * which needs a named limiter registered at boot: that would fix the limit at
 * the moment the service provider ran, and this package's limit is a config
 * value an operator is expected to change once they have looked at their own
 * quota page.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RateLimitPageSpeedRequests
{
    /**
     * The cache key the request budget is counted under.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const LIMITER_KEY = 'artisanpack-ui:pagespeed-insights';

    /**
     * The budget used when config carries no usable value.
     *
     * Conservative on purpose. Google publishes no PageSpeed Insights rate
     * limit anywhere in its documentation, so a higher default would be a
     * guess dressed up as a setting.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_PER_MINUTE = 30;

    /**
     * The window the budget is measured over, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const WINDOW_SECONDS = 60;

    /**
     * Build the middleware.
     *
     * @since 1.0.0
     *
     * @param  RateLimiter  $limiter  The shared request counter.
     */
    public function __construct( protected RateLimiter $limiter )
    {
    }

    /**
     * Let the job through, or release it until there is budget for it.
     *
     * @since 1.0.0
     *
     * @param  object  $job  The job being processed.
     * @param  Closure  $next  The next stage of the pipeline.
     *
     * @return mixed Whatever the pipeline returned, or null when the job was released.
     */
    public function handle( object $job, Closure $next ): mixed
    {
        $perMinute = $this->perMinute();

        if ( $perMinute <= 0 ) {
            return $next( $job );
        }

        if ( $this->limiter->tooManyAttempts( self::LIMITER_KEY, $perMinute ) ) {
            if ( method_exists( $job, 'release' ) ) {
                $job->release( $this->limiter->availableIn( self::LIMITER_KEY ) );
            }

            return null;
        }

        $this->limiter->hit( self::LIMITER_KEY, self::WINDOW_SECONDS );

        return $next( $job );
    }

    /**
     * The configured budget.
     *
     * A negative value is read as "no limit" rather than as a limit of zero
     * attempts, which would wedge the queue permanently.
     *
     * @since 1.0.0
     *
     * @return int Requests allowed per minute; zero or less disables throttling.
     */
    protected function perMinute(): int
    {
        $configured = config( 'pagespeed-insights.rate_limit.per_minute', self::DEFAULT_PER_MINUTE );

        if ( ! is_numeric( $configured ) ) {
            return self::DEFAULT_PER_MINUTE;
        }

        return (int) $configured;
    }
}
