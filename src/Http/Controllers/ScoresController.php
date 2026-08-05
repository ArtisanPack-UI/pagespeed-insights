<?php

/**
 * HTTP controller serving the latest category scores and lab metrics.
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
 * `GET /pagespeed/scores?url=&strategy=` — the newest stored run's four
 * category scores and its Lighthouse lab metrics.
 *
 * The four states mirror {@see \ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard}
 * because they are the same four pieces of news, and a React card and a
 * Livewire card describing the same row must not disagree about which one it
 * is. `apiKeyConfigured` is carried alongside rather than folded into the
 * state: an install that had a key, ran tests, and then lost it still has real
 * history worth rendering, and collapsing the two would hide the very numbers
 * that make the missing key worth fixing.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ScoresController extends Controller
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
     * The most recent run failed.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The most recent run completed but lost data on the way.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_DEGRADED = 'degraded';

    /**
     * The most recent run completed cleanly.
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
     * Serve the latest scores for one URL and form factor.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The scores payload.
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
            'url'              => $url,
            'strategy'         => $strategy,
            'apiKeyConfigured' => $this->hasApiKey(),
            'state'            => self::STATE_EMPTY,
            'result'           => null,
            'scores'           => [],
            'labMetrics'       => [],
        ];

        if ( null === $result ) {
            return response()->json( $payload );
        }

        $payload[ 'result' ] = $this->presenter->summary( $result );

        if ( $result->isFailed() ) {
            $payload[ 'state' ] = self::STATE_FAILED;

            return response()->json( $payload );
        }

        $payload[ 'state' ]      = $result->wasDegraded() ? self::STATE_DEGRADED : self::STATE_LOADED;
        $payload[ 'scores' ]     = $this->presenter->scores( $result );
        $payload[ 'labMetrics' ] = $this->presenter->labMetrics( $result );

        return response()->json( $payload );
    }
}
