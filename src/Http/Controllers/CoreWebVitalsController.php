<?php

/**
 * HTTP controller serving the latest Core Web Vitals field data.
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

use ArtisanPackUI\PageSpeedInsights\Data\FieldData;
use ArtisanPackUI\PageSpeedInsights\Http\Support\ResultPresenter;
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /pagespeed/core-web-vitals?url=&strategy=` — the newest stored run's
 * CrUX field data, page-level and origin-level.
 *
 * ### Both sets are sent, always
 *
 * Page level and origin level are not interchangeable, and a payload that
 * silently substitutes one for the other misrepresents the page: origin-level
 * numbers describe the whole site. Both are carried under their own keys, and
 * either may be null, so a client renders what it has and says which it is
 * rather than guessing from the numbers.
 *
 * The `state` names which of them is the honest headline, so a client that
 * wants one answer has one.
 *
 * ### Why there is still a strategy
 *
 * CrUX collects field data separately for phone and desktop, so a run's field
 * data belongs to the form factor it was requested with. The parameter is
 * optional and defaults to mobile — which is both the form factor most sites'
 * traffic arrives on and the default the rest of this package uses.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class CoreWebVitalsController extends Controller
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
     * The most recent run failed, so there is no field data to read.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_FAILED = 'failed';

    /**
     * The run completed, but CrUX has no data for the page or the origin.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_NO_FIELD_DATA = 'no-field-data';

    /**
     * The only data available describes the origin, not this page.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATE_ORIGIN_LEVEL = 'origin-level';

    /**
     * Page-level field data for this exact URL.
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
     * Serve the latest field data for one URL.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The vitals payload.
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
            'url'        => $url,
            'strategy'   => $strategy,
            'percentile' => FieldData::PERCENTILE,
            'state'      => self::STATE_EMPTY,
            'result'     => null,
            'page'       => null,
            'origin'     => null,
        ];

        if ( null === $result ) {
            return response()->json( $payload );
        }

        $payload[ 'result' ] = $this->presenter->summary( $result );

        if ( $result->isFailed() ) {
            $payload[ 'state' ] = self::STATE_FAILED;

            return response()->json( $payload );
        }

        $parsed = $result->toTestResult();

        $payload[ 'page' ]   = $this->presenter->fieldData( $parsed->fieldData );
        $payload[ 'origin' ] = $this->presenter->fieldData( $parsed->originFieldData );

        // A page-level set that CrUX filled in from the origin is origin-level
        // data wearing the page's name, so it is stated as such rather than as
        // a measurement of this URL.
        $pageIsOriginLevel = null !== $payload[ 'page' ]
            && ( true === $payload[ 'page' ][ 'originFallback' ] || true === $payload[ 'page' ][ 'originLevel' ] );

        if ( null !== $payload[ 'page' ] && ! $pageIsOriginLevel ) {
            $payload[ 'state' ] = self::STATE_LOADED;
        } elseif ( null !== $payload[ 'page' ] || null !== $payload[ 'origin' ] ) {
            $payload[ 'state' ] = self::STATE_ORIGIN_LEVEL;
        } else {
            $payload[ 'state' ] = self::STATE_NO_FIELD_DATA;
        }

        return response()->json( $payload );
    }
}
