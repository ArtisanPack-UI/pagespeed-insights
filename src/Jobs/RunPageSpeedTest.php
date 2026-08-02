<?php

/**
 * Queued PageSpeed test job.
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

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\QuotaExceededException;
use ArtisanPackUI\PageSpeedInsights\Jobs\Middleware\RateLimitPageSpeedRequests;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One PageSpeed run: one URL, one form factor, one stored row.
 *
 * Split per form factor rather than per URL because a run blocks its worker
 * for 20-60 seconds, and because a mobile run that fails should not take the
 * desktop result down with it.
 *
 * ### Failure handling
 *
 * The three ways a run can fail need three different responses, and getting
 * that wrong is the worst failure mode this package has:
 *
 * - **No API key.** Never retried. Keyless PageSpeed answers HTTP 429 — the
 *   same status as genuine quota exhaustion — because Google's shared
 *   anonymous project has a daily quota of zero. An install that simply never
 *   set `PAGESPEED_API_KEY` would otherwise release and retry forever on a
 *   long backoff, silently, and present as a broken queue rather than as the
 *   one-line configuration fix it is. So this fails immediately, records the
 *   row, and logs at error level with the remedy in the message.
 * - **Quota exhausted on a configured key.** Released with a long delay
 *   rather than consuming a retry: the same request will succeed once the
 *   window rolls over, and spending three attempts on it inside one hour
 *   just converts a postponement into a failure.
 * - **Anything else** — a 5xx, a transport error, a Lighthouse runtime
 *   error. Retried with backoff, and recorded as failed once the attempts
 *   are exhausted.
 *
 * That last part matters more than it looks: a job that dies without ever
 * writing `status = failed` leaves a monitored URL that quietly stops
 * producing history, and a regression detector comparing runs then has
 * nothing to compare and reports nothing wrong.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class RunPageSpeedTest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Action fired before the run is sent to Google.
     *
     * Callbacks receive the URL, the strategy, and the monitored row when
     * there is one.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACTION_BEFORE_TEST = 'ap.pageSpeed.beforeTest';

    /**
     * Action fired once a result row has been written.
     *
     * Callbacks receive the saved {@see PageSpeedResult} and the parsed
     * {@see TestResult}, which is null when the row records a failure. Fired
     * for failed rows too, so a listener can alert on a run that stopped
     * producing data.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ACTION_RESULT_STORED = 'ap.pageSpeed.resultStored';

    /**
     * Seconds one attempt may run for when config carries no usable value.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TIMEOUT = 120;

    /**
     * Attempts before a transient failure is recorded as failed.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_TRIES = 3;

    /**
     * Seconds to wait before each retry.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public const DEFAULT_BACKOFF = [ 60, 300 ];

    /**
     * Seconds to postpone a run rejected for quota on a configured key.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_QUOTA_DELAY = 1800;

    /**
     * Seconds this attempt may run for.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $timeout = self::DEFAULT_TIMEOUT;

    /**
     * Attempts allowed before the run is recorded as failed.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = self::DEFAULT_TRIES;

    /**
     * Seconds to wait before each retry.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public array $retryBackoff = self::DEFAULT_BACKOFF;

    /**
     * Seconds to postpone a run rejected for quota.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $quotaDelay = self::DEFAULT_QUOTA_DELAY;

    /**
     * Build the job.
     *
     * The monitored row is carried as an id rather than as a serialized
     * model on purpose: a URL an operator deletes while its job is queued
     * would otherwise make the job explode on unserialization, and the run
     * itself is still perfectly valid — the result simply lands in history
     * without a parent row.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The absolute http(s) URL to test.
     * @param  string  $strategy  mobile or desktop.
     * @param  int|null  $monitoredUrlId  The `pagespeed_urls` row this run belongs to, when it was scheduled.
     */
    public function __construct(
        public string $url,
        public string $strategy = PageSpeedRequest::STRATEGY_MOBILE,
        public ?int $monitoredUrlId = null,
    ) {
        $this->timeout      = $this->positiveConfig( 'pagespeed-insights.job.timeout', self::DEFAULT_TIMEOUT );
        $this->tries        = $this->positiveConfig( 'pagespeed-insights.job.tries', self::DEFAULT_TRIES );
        $this->quotaDelay   = $this->positiveConfig( 'pagespeed-insights.job.quota_delay', self::DEFAULT_QUOTA_DELAY );
        $this->retryBackoff = $this->configuredBackoff();

        $this->onConnection( $this->stringConfig( 'pagespeed-insights.queue.connection' ) );
        $this->onQueue( $this->stringConfig( 'pagespeed-insights.queue.queue' ) );
    }

    /**
     * Run the test and store what came back.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedClient  $client  The API client.
     *
     * @throws PageSpeedApiException When the failure is transient and the attempt should be retried.
     *
     * @return void
     */
    public function handle( PageSpeedClient $client ): void
    {
        $monitored = $this->monitoredUrl();

        $this->fireBeforeTest( $monitored );

        try {
            $result = $client->test( $this->url, $this->strategy );
        } catch ( QuotaExceededException $exception ) {
            $this->postpone( $exception );

            return;
        } catch ( PageSpeedApiException $exception ) {
            if ( $exception->isRetryable() ) {
                Log::warning(
                    'A PageSpeed run failed and will be retried.',
                    $this->logContext() + [
                        'exception' => $exception::class,
                        'error'     => $exception->getMessage(),
                    ],
                );

                throw $exception;
            }

            $this->abandon( $exception );

            return;
        }

        $this->store( $result, $monitored );
    }

    /**
     * Record the run as failed once the queue has given up on it.
     *
     * The single place a failure row is written, which is why nothing in
     * `handle()` writes one directly. The queue invokes this callback on a
     * freshly unserialized instance rather than on the object that was
     * running, so a job that recorded its own failure and then failed itself
     * would produce two rows for one run.
     *
     * Reached on the exhausted-retries path, on a timeout, and through
     * {@see self::abandon()}. Writing the row here rather than only on the
     * success path is what stops an exhausted job from leaving a monitored
     * URL that silently stopped producing history.
     *
     * @since 1.0.0
     *
     * @param  Throwable|null  $exception  Why the job died, when the queue knows.
     *
     * @return void
     */
    public function failed( ?Throwable $exception = null ): void
    {
        $message = null === $exception
            ? __( 'The PageSpeed run did not complete and the queue gave up on it.' )
            : $exception->getMessage();

        Log::error(
            'A PageSpeed run failed permanently.',
            $this->logContext() + [
                'exception' => null === $exception ? null : $exception::class,
                'error'     => $message,
            ],
        );

        $this->recordFailure( $message, $this->monitoredUrl() );
    }

    /**
     * The job middleware this job runs through.
     *
     * @since 1.0.0
     *
     * @return array<int, object> The middleware stack.
     */
    public function middleware(): array
    {
        return [ app( RateLimitPageSpeedRequests::class ) ];
    }

    /**
     * Seconds to wait before each retry.
     *
     * @since 1.0.0
     *
     * @return array<int, int> The backoff schedule.
     */
    public function backoff(): array
    {
        return $this->retryBackoff;
    }

    /**
     * Store a completed run.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The parsed run.
     * @param  PageSpeedUrl|null  $monitored  The monitored row, when there still is one.
     *
     * @return void
     */
    protected function store( TestResult $result, ?PageSpeedUrl $monitored ): void
    {
        $row = PageSpeedResult::fromTestResult( $result, $monitored );
        $row->save();

        $this->touchMonitored( $monitored );

        // A run that completed while losing a category or a lab metric is
        // stored, not discarded — but it is also the reason a trend chart
        // will have a hole in it later, so it says so at the time.
        if ( $row->wasDegraded() ) {
            Log::warning(
                'A PageSpeed run completed with data missing.',
                $this->logContext() + [ 'warnings' => $row->warningList() ],
            );
        }

        $this->fireResultStored( $row, $result );
    }

    /**
     * Give up on a failure no retry could fix, and stop the queue retrying it.
     *
     * Failing the job is what records the row, by way of
     * {@see self::failed()}. Without a queue wrapper — a job invoked directly
     * — `fail()` quietly does nothing, so the callback is called by hand
     * instead. Either way the run is recorded exactly once.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedApiException  $exception  The failure.
     *
     * @return void
     */
    protected function abandon( PageSpeedApiException $exception ): void
    {
        Log::error(
            'A PageSpeed run was abandoned without retrying because retrying cannot fix it.',
            $this->logContext() + [
                'exception' => $exception::class,
                'error'     => $exception->getMessage(),
            ],
        );

        if ( null === $this->job ) {
            $this->failed( $exception );

            return;
        }

        $this->fail( $exception );
    }

    /**
     * Put a quota-rejected run back on the queue for later.
     *
     * @since 1.0.0
     *
     * @param  QuotaExceededException  $exception  The rejection.
     *
     * @return void
     */
    protected function postpone( QuotaExceededException $exception ): void
    {
        Log::warning(
            'A PageSpeed run was postponed because the API quota is exhausted.',
            $this->logContext() + [
                'delay'        => $this->quotaDelay,
                'quota_metric' => $exception->quotaMetric,
                'quota_limit'  => $exception->quotaLimit,
                'error'        => $exception->getMessage(),
            ],
        );

        $this->release( $this->quotaDelay );
    }

    /**
     * Write the row that records a run as failed.
     *
     * @since 1.0.0
     *
     * @param  string  $message  Why the run failed, written for a human.
     * @param  PageSpeedUrl|null  $monitored  The monitored row, when there still is one.
     *
     * @return void
     */
    protected function recordFailure( string $message, ?PageSpeedUrl $monitored ): void
    {
        $row = PageSpeedResult::fromFailure( $this->url, $this->strategy, $message, $monitored );
        $row->save();

        // `last_tested_at` is deliberately not stamped here. It means "when
        // this URL last produced a measurement", and a failed run produced
        // none — stamping it would let a URL that cannot be tested at all
        // read as freshly tested, which is precisely the state the failure
        // row exists to make visible. The cost is that a permanently broken
        // URL is re-dispatched by every cycle until somebody fixes or pauses
        // it; that is the intended noise.
        $this->fireResultStored( $row, null );
    }

    /**
     * Record that the monitored URL has now produced a measurement.
     *
     * Only a completed run gets here. `last_tested_at` drives due-ness, and
     * a failed run has nothing to show for itself, so letting one satisfy the
     * cadence would hide a URL that has quietly stopped being measurable.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl|null  $monitored  The monitored row, when there still is one.
     *
     * @return void
     */
    protected function touchMonitored( ?PageSpeedUrl $monitored ): void
    {
        if ( null === $monitored || ! $monitored->exists ) {
            return;
        }

        $monitored->forceFill( [ 'last_tested_at' => CarbonImmutable::now() ] )->save();
    }

    /**
     * The monitored row this run belongs to, if it is still there.
     *
     * @since 1.0.0
     *
     * @return PageSpeedUrl|null The row, or null for an ad hoc run or one whose URL was deleted.
     */
    protected function monitoredUrl(): ?PageSpeedUrl
    {
        if ( null === $this->monitoredUrlId ) {
            return null;
        }

        $monitored = PageSpeedUrl::query()->find( $this->monitoredUrlId );

        if ( null === $monitored ) {
            Log::info(
                'The monitored URL for a queued PageSpeed run no longer exists. The run continues and its result is kept as history for the URL itself.',
                $this->logContext(),
            );
        }

        return $monitored;
    }

    /**
     * Fire the pre-run action, if the hooks package is installed.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl|null  $monitored  The monitored row, when there is one.
     *
     * @return void
     */
    protected function fireBeforeTest( ?PageSpeedUrl $monitored ): void
    {
        if ( function_exists( 'doAction' ) ) {
            doAction( self::ACTION_BEFORE_TEST, $this->url, $this->strategy, $monitored );
        }
    }

    /**
     * Fire the post-persistence action, if the hooks package is installed.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedResult  $row  The saved row.
     * @param  TestResult|null  $result  The parsed run, or null when the row records a failure.
     *
     * @return void
     */
    protected function fireResultStored( PageSpeedResult $row, ?TestResult $result ): void
    {
        if ( function_exists( 'doAction' ) ) {
            doAction( self::ACTION_RESULT_STORED, $row, $result );
        }
    }

    /**
     * The context every log line from this job carries.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The structured context.
     */
    protected function logContext(): array
    {
        return [
            'url'               => $this->url,
            'strategy'          => $this->strategy,
            'monitored_url_id'  => $this->monitoredUrlId,
            'attempt'           => $this->safeAttempts(),
        ];
    }

    /**
     * Which attempt this is, when the job is running on a real queue.
     *
     * @since 1.0.0
     *
     * @return int|null The attempt number, or null when the job was invoked directly.
     */
    protected function safeAttempts(): ?int
    {
        return null === $this->job ? null : $this->attempts();
    }

    /**
     * Read a positive integer out of config.
     *
     * @since 1.0.0
     *
     * @param  string  $key  The config key.
     * @param  int  $fallback  The value to use when the config value is unusable.
     *
     * @return int The configured value, or the fallback.
     */
    protected function positiveConfig( string $key, int $fallback ): int
    {
        $value = config( $key, $fallback );

        return is_numeric( $value ) && (int) $value > 0 ? (int) $value : $fallback;
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

    /**
     * The configured retry backoff.
     *
     * @since 1.0.0
     *
     * @return array<int, int> Seconds to wait before each retry.
     */
    protected function configuredBackoff(): array
    {
        $configured = config( 'pagespeed-insights.job.backoff', self::DEFAULT_BACKOFF );

        if ( ! is_array( $configured ) ) {
            $configured = [ $configured ];
        }

        $backoff = [];

        foreach ( $configured as $seconds ) {
            if ( is_numeric( $seconds ) && (int) $seconds >= 0 ) {
                $backoff[] = (int) $seconds;
            }
        }

        return [] === $backoff ? self::DEFAULT_BACKOFF : $backoff;
    }
}
