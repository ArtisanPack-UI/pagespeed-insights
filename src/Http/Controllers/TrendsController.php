<?php

/**
 * HTTP controller serving one measurement's history as a time series.
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

use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Support\TrendSeries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /pagespeed/trends?url=&metric=&range=&strategy=` — one measurement
 * plotted over time, mobile against desktop.
 *
 * This is the endpoint the results table exists for. Every other read here
 * could be rebuilt from a single live API call; this one is the only thing a
 * year of stored history buys.
 *
 * ### Two points is the floor
 *
 * A trend drawn from one measurement is not a trend, it is a dot, and it
 * invites a reader to conclude something about a direction that has not been
 * measured yet. Below {@see TrendSeries::MINIMUM_POINTS} the payload says
 * `insufficient` and sends no series, and `hasOlderHistory` separates a URL
 * with no history at all from one whose history simply falls outside the range
 * — the second is fixed by widening the range, the first by running a test, and
 * offering the wrong remedy wastes somebody's afternoon.
 *
 * Omitting `strategy` returns every form factor that has data; naming one
 * narrows to it.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TrendsController extends Controller
{
    /**
     * No completed run has ever been stored for this URL.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_EMPTY = 'empty';

    /**
     * There is history, but none of it falls inside the selected range.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_OUT_OF_RANGE = 'out-of-range';

    /**
     * Fewer than two usable measurements, which is a dot rather than a trend.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_INSUFFICIENT = 'insufficient';

    /**
     * There is a trend to draw.
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
     * @param  TrendSeries  $trends  Builds the series.
     */
    public function __construct( UrlScope $scope, protected TrendSeries $trends )
    {
        parent::__construct( $scope );
    }

    /**
     * Serve one measurement's history for one URL.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The trend payload.
     */
    public function __invoke( Request $request ): JsonResponse
    {
        $url = $this->readableUrl( $request->query( 'url' ) );

        if ( $url instanceof JsonResponse ) {
            return $url;
        }

        $metric = TrendSeries::normalizeMetric( $request->query( 'metric' ) );
        $range  = TrendSeries::normalizeRange( $request->query( 'range' ) );

        // Absent means every form factor. Present but unrecognised is reduced
        // to mobile like everywhere else, rather than silently widening the
        // answer to both.
        $requested = $request->query( 'strategy' );
        $strategy  = null === $requested || '' === $requested ? null : self::strategy( $requested );

        $trend = $this->trends->build( $url, $metric, $range, $strategy );

        $payload = [
            'url'             => $url,
            'metric'          => $metric,
            'range'           => $range,
            'strategy'        => $strategy,
            'state'           => self::STATE_LOADED,
            'series'          => $trend[ 'series' ],
            'pointCount'      => $trend[ 'pointCount' ],
            'hasGaps'         => $trend[ 'hasGaps' ],
            'truncated'       => $trend[ 'truncated' ],
            'hasOlderHistory' => $trend[ 'hasOlderHistory' ],
        ];

        if ( $trend[ 'pointCount' ] >= TrendSeries::MINIMUM_POINTS ) {
            return response()->json( $payload );
        }

        $payload[ 'series' ] = [];

        if ( [] === $trend[ 'series' ] ) {
            $payload[ 'state' ] = $trend[ 'hasOlderHistory' ] ? self::STATE_OUT_OF_RANGE : self::STATE_EMPTY;

            return response()->json( $payload );
        }

        $payload[ 'state' ] = self::STATE_INSUFFICIENT;

        return response()->json( $payload );
    }
}
