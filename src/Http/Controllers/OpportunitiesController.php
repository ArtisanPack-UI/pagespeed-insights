<?php

/**
 * HTTP controller serving the latest Lighthouse opportunities.
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
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /pagespeed/opportunities?url=&strategy=` — what Lighthouse says is worth
 * fixing on one URL, heaviest first.
 *
 * An empty list is three different pieces of news, and the `state` is what
 * separates them: a run that never asked for the performance category found
 * nothing because nothing was looked for, and reporting that as "no
 * opportunities" tells an operator their page is clean on the strength of a
 * test that never examined it.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class OpportunitiesController extends Controller
{
    /**
     * No run has been stored for this URL and form factor yet.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_EMPTY = 'empty';

    /**
     * The most recent run failed, so there is nothing to have found.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The run did not measure performance, so no audits were collected.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NOT_MEASURED = 'not-measured';

    /**
     * Performance was measured and Lighthouse found nothing worth listing.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NONE = 'none';

    /**
     * There are opportunities to show.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_LOADED = 'loaded';

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param  UrlScope  $scope  Decides which URLs may be asked about.
     * @param  ResultPresenter  $presenter  Shapes the payload.
     */
    public function __construct( UrlScope $scope, protected ResultPresenter $presenter )
    {
        parent::__construct( $scope );
    }

    /**
     * Serve the latest opportunities for one URL and form factor.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The opportunities payload.
     */
    public function __invoke( Request $request ): JsonResponse
    {
        $url = $this->readableUrl( $request->query( 'url' ) );

        if ( $url instanceof JsonResponse ) {
            return $url;
        }

        $strategy = self::strategy( $request->query( 'strategy' ) );

        $result = PageSpeedResult::query()
            ->latestFor( $url, $strategy )
            ->first();

        $payload = [
            'url'           => $url,
            'strategy'      => $strategy,
            'state'         => self::STATE_EMPTY,
            'result'        => null,
            'opportunities' => [],
        ];

        if ( null === $result ) {
            return response()->json( $payload );
        }

        $payload[ 'result' ] = $this->presenter->summary( $result );

        if ( $result->isFailed() ) {
            $payload[ 'state' ] = self::STATE_FAILED;

            return response()->json( $payload );
        }

        $payload[ 'opportunities' ] = $this->presenter->opportunities( $result );

        if ( [] !== $payload[ 'opportunities' ] ) {
            $payload[ 'state' ] = self::STATE_LOADED;

            return response()->json( $payload );
        }

        // `has()` is what tells a category the response never carried from one
        // it carried unscored — the difference between nothing found and
        // nothing looked for.
        $payload[ 'state' ] = $result->scores()->has( 'performance' )
            ? self::STATE_NONE
            : self::STATE_NOT_MEASURED;

        return response()->json( $payload );
    }
}
