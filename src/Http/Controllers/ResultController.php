<?php

/**
 * HTTP controller serving one run, queued or stored.
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

use ArtisanPackUI\PageSpeedInsights\Http\Support\ResultPresenter;
use ArtisanPackUI\PageSpeedInsights\Http\Support\TestTicketStore;
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Illuminate\Http\JsonResponse;

/**
 * `GET /pagespeed/results/{id}` — the other half of `POST /pagespeed/test`, and
 * the way to fetch any single stored run.
 *
 * ### Two kinds of id, one route
 *
 * A queued run has no result row until it finishes, so `POST /pagespeed/test`
 * hands back a ticket id rather than a row id — see {@see TestTicketStore} for
 * why a pending row was the wrong answer. Once the run lands, the same ticket
 * resolves to the row it produced, and the row has an id of its own that is
 * worth being able to ask for later.
 *
 * Both are accepted here. A numeric id is a stored run; anything else is a
 * ticket. One route rather than two because they are the same question asked at
 * two moments — "what came of this run?" — and a client that has to know which
 * of two endpoints to call has to track which kind of id it is holding.
 *
 * ### Scope
 *
 * Held to {@see UrlScope::allowsTest()} rather than to the read scope the other
 * endpoints use. A run this endpoint's own `POST /test` sibling queued for a
 * page of this application's site that nobody has added to the monitored list
 * must be pollable, and holding the poll to the stricter rule would make the
 * queue-and-poll flow fail at the second step for exactly the URLs the test
 * endpoint was widened to accept.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ResultController extends Controller
{
    /**
     * No stored run or live ticket carries the id that was named.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_NOT_FOUND = 'not_found';

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param  UrlScope  $scope  Decides which URLs may be asked about.
     * @param  TestTicketStore  $tickets  Resolves ad hoc test tickets.
     * @param  ResultPresenter  $presenter  Shapes the payload.
     */
    public function __construct(
        UrlScope $scope,
        protected TestTicketStore $tickets,
        protected ResultPresenter $presenter,
    ) {
        parent::__construct( $scope );
    }

    /**
     * Serve a stored run, or the state of a queued one.
     *
     * @since 1.0.0
     *
     * @param  string  $id  A stored result id, or a ticket id.
     *
     * @return JsonResponse The run payload.
     */
    public function __invoke( string $id ): JsonResponse
    {
        if ( 1 === preg_match( '/^\d+$/', $id ) ) {
            return $this->storedResult( (int) $id );
        }

        return $this->ticket( $id );
    }

    /**
     * Serve one stored run.
     *
     * @since 1.0.0
     *
     * @param  int  $id  The result id.
     *
     * @return JsonResponse The run payload.
     */
    protected function storedResult( int $id ): JsonResponse
    {
        $result = PageSpeedResult::query()->whereKey( $id )->first();

        if ( null === $result || ! $this->scope->allowsTest( (string) $result->url ) ) {
            // The same answer either way, so that a caller cannot map the
            // monitored set — or the id space — by probing it.
            return $this->notFound();
        }

        return response()->json( [
            'id'       => $result->getKey(),
            'status'   => $result->isFailed()
                ? TestTicketStore::STATUS_FAILED
                : TestTicketStore::STATUS_COMPLETED,
            'url'      => (string) $result->url,
            'strategy' => (string) $result->strategy,
            'result'   => $this->presenter->full( $result ),
        ] );
    }

    /**
     * Serve the state of a queued run.
     *
     * @since 1.0.0
     *
     * @param  string  $id  The ticket id.
     *
     * @return JsonResponse The ticket payload.
     */
    protected function ticket( string $id ): JsonResponse
    {
        $ticket = $this->tickets->poll( $id );

        if ( null === $ticket || ! $this->scope->allowsTest( $ticket[ 'url' ] ) ) {
            return $this->notFound();
        }

        $result = $ticket[ 'result' ];

        return response()->json( [
            'id'       => $result?->getKey() ?? $id,
            'status'   => $ticket[ 'status' ],
            'url'      => $ticket[ 'url' ],
            'strategy' => $ticket[ 'strategy' ],
            'result'   => null === $result ? null : $this->presenter->full( $result ),
        ] );
    }

    /**
     * The response for an id that names nothing this caller may see.
     *
     * @since 1.0.0
     *
     * @return JsonResponse The refusal.
     */
    protected function notFound(): JsonResponse
    {
        return $this->error(
            self::ERROR_NOT_FOUND,
            __( 'No run has that id. A queued run is only pollable for a limited time after it is started.' ),
            404,
        );
    }
}
