<?php

/**
 * HTTP controller queueing an ad hoc PageSpeed run.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Http\Controllers;

use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Http\Support\TestTicketStore;
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /pagespeed/test` — queue one run and hand back an id to poll it with.
 *
 * ### This is the endpoint that spends money
 *
 * Every other endpoint in this package reads rows that already exist. This one
 * makes a request against an API key the application pays for and Google rate-
 * limits, so it is the one with a scope of its own: the URL must be monitored,
 * or on this application's origin, unless `routes.allow_external_urls` says
 * otherwise. Authentication alone is not enough — an authenticated user must
 * not be able to point the installation's quota at arbitrary third-party sites.
 *
 * ### Refusing without a key
 *
 * A keyless run is refused here rather than queued, for the reason
 * {@see \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard} refuses one: it
 * would cost 20-60 seconds of a worker to learn something knowable now, and it
 * would come back as an HTTP 429 that reads as a rate limit rather than as the
 * one-line configuration fix it is.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class QueueTestController extends Controller
{
    /**
     * No API key is configured, so no run could succeed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_NO_API_KEY = 'no_api_key';

    /**
     * The queue would not take the job.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_QUEUE_UNAVAILABLE = 'queue_unavailable';

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param  UrlScope  $scope  Decides which URLs may be tested.
     * @param  TestTicketStore  $tickets  Issues the id the run is polled with.
     */
    public function __construct( UrlScope $scope, protected TestTicketStore $tickets )
    {
        parent::__construct( $scope );
    }

    /**
     * Queue a run.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The ticket, or the response refusing the run.
     */
    public function __invoke( Request $request ): JsonResponse
    {
        $url = $this->scope->normalize( $request->input( 'url' ) );

        if ( null === $url ) {
            return $this->invalidUrl();
        }

        if ( ! $this->scope->allowsTest( $url ) ) {
            return $this->error(
                self::ERROR_URL_NOT_ALLOWED,
                __( 'This installation will not spend API quota on a URL outside its own site. Enable pagespeed-insights.routes.allow_external_urls to change that.' ),
                403,
            );
        }

        if ( ! $this->hasApiKey() ) {
            return $this->error(
                self::ERROR_NO_API_KEY,
                __( 'No PageSpeed API key is configured, so no run can succeed. Set PAGESPEED_API_KEY, or store a key through the configured driver.' ),
                409,
            );
        }

        $strategy = self::strategy( $request->input( 'strategy' ) );

        // The ticket is opened before the job is dispatched so that its record
        // of the newest existing result cannot miss a run that finished between
        // the two — a synchronous queue driver stores the row inside
        // `dispatch()`, and a ticket opened afterwards would treat that row as
        // pre-existing history and wait for a second one that never comes.
        $id = $this->tickets->open( $url, $strategy );

        try {
            RunPageSpeedTest::dispatch( $url, $strategy );
        } catch ( Throwable $exception ) {
            // Logged in full, reported in general terms. This message comes
            // from the queue driver rather than from this package: redaction
            // takes the credentials out of a connection string, but the
            // internal hostname, port, and file path it also carries are not
            // things a client needs in order to know the run did not start.
            Log::error(
                'A PageSpeed run could not be queued.',
                [
                    'url'       => $url,
                    'strategy'  => $strategy,
                    'exception' => $exception::class,
                    'error'     => PageSpeedApiException::redactCredentials( $exception->getMessage() ),
                ],
            );

            return $this->error(
                self::ERROR_QUEUE_UNAVAILABLE,
                __( 'The test could not be queued. Check the application log for why.' ),
                503,
            );
        }

        return response()->json( [
            'id'       => $id,
            'status'   => TestTicketStore::STATUS_QUEUED,
            'url'      => $url,
            'strategy' => $strategy,
        ], 202 );
    }
}
