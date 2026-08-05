<?php

/**
 * Queued regression digest job.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Jobs;

use ArtisanPackUI\PageSpeedInsights\Alerts\AlertDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one digest window's worth of regressions as a single notification.
 *
 * Queued with a delay by {@see AlertDispatcher}, once per window rather than
 * once per regression. It carries no payload: everything it sends is in the
 * cache buffer, so a regression detected a second after this job was queued
 * still goes out in the same email.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class SendRegressionDigest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Attempts allowed before the digest is given up on.
     *
     * Declared rather than left to the worker's own default, which is one. If
     * the cache store is briefly unreachable when {@see AlertDispatcher::flush()}
     * reads the buffer, a single attempt means the window's regressions sit in
     * cache until they expire and then vanish — silently, from the component
     * whose entire job is not being silent.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Build the job.
     *
     * Dispatched onto the package's own queue, for the same reason the runs
     * are: an application that gave PageSpeed a dedicated queue meant all of
     * it.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->onConnection( $this->stringConfig( 'pagespeed-insights.queue.connection' ) );
        $this->onQueue( $this->stringConfig( 'pagespeed-insights.queue.queue' ) );
    }

    /**
     * Send the digest.
     *
     * @since 1.0.0
     *
     * @param  AlertDispatcher  $dispatcher  Holds the buffer and the recipients.
     *
     * @return void
     */
    public function handle( AlertDispatcher $dispatcher ): void
    {
        $dispatcher->flush();
    }

    /**
     * Say loudly that a window of regressions was never delivered.
     *
     * There is nothing left to retry by this point and nothing else will
     * notice: the buffered regressions expire out of the cache on their own,
     * so without this line a score drop would be detected, buffered, and then
     * quietly discarded with no record anywhere that it happened.
     *
     * @since 1.0.0
     *
     * @param  Throwable|null  $exception  Why the job died, when the queue knows.
     *
     * @return void
     */
    public function failed( ?Throwable $exception = null ): void
    {
        Log::error(
            'A PageSpeed regression digest could not be sent, and the regressions it held are lost. Check the cache store the alert buffer runs on.',
            [
                'exception' => null === $exception ? null : $exception::class,
                'error'     => $exception?->getMessage(),
            ],
        );
    }

    /**
     * Read a non-empty string out of config.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The config key.
     *
     * @return string|null The trimmed value, or null when it is unset or blank.
     */
    protected function stringConfig( string $key ): ?string
    {
        $value = config( $key );

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }

        return trim( $value );
    }
}
