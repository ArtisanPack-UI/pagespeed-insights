<?php

/**
 * HTTP controller managing the monitored URL set.
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

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET`, `POST /pagespeed/urls` and `DELETE /pagespeed/urls/{id}` — the
 * monitored set, as something a front end can change.
 *
 * ### Hook-contributed rows are listed and not editable
 *
 * URLs registered through `ap.pageSpeed.registerUrls` are listed because a
 * caller asking "which URLs are monitored?" has to see them — they cost quota
 * on every cycle exactly like a stored row does. A contribution that has never
 * been saved carries a null `id` and `editable: false`, because it is an
 * unsaved model owned by whichever package registered it and the next request
 * would rebuild it from the filter whatever this endpoint did to it. Posting
 * such a URL is allowed, and is the documented way to take one over: it creates
 * a stored row, which then wins the merge in {@see UrlRegistry}.
 *
 * ### Adding is scoped like testing, not like reading
 *
 * Adding a URL is a *recurring* commitment of API quota — every cycle, on every
 * configured form factor, for as long as the row lives — so it is held to
 * {@see UrlScope::allowsTest()} rather than being open to any address an
 * authenticated user can type. An installation that legitimately monitors
 * somebody else's site turns on `routes.allow_external_urls`, which is the same
 * switch that governs ad hoc runs and is off by default.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class MonitoredUrlController extends Controller
{
    /**
     * The most URLs one listing will return.
     *
     * A monitored set larger than this is unusual, and returning one as a
     * single unbounded document is how an admin screen becomes the slowest
     * thing in the application. When the cap bites the payload says so rather
     * than quietly serving a prefix of the list.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_URLS = 250;

    /**
     * The longest label the `label` column holds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LABEL_LENGTH = 255;

    /**
     * A URL was posted that another row already monitors.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_ALREADY_MONITORED = 'already_monitored';

    /**
     * One of the supplied attributes cannot be stored.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_INVALID_ATTRIBUTE = 'invalid_attribute';

    /**
     * No editable monitored URL carries the id that was named.
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
     * @param  UrlScope  $scope  Decides which URLs may be added.
     * @param  UrlRegistry  $registry  The monitored set.
     */
    public function __construct( UrlScope $scope, protected UrlRegistry $registry )
    {
        parent::__construct( $scope );
    }

    /**
     * List the monitored set.
     *
     * @since 1.0.0
     *
     * @return JsonResponse The monitored URLs.
     */
    public function index(): JsonResponse
    {
        $monitored = $this->registry->all();
        $total     = $monitored->count();

        $urls = $monitored
            ->take( self::MAX_URLS )
            ->map( static fn ( PageSpeedUrl $url ): array => self::present( $url ) )
            ->values()
            ->all();

        return response()->json( [
            'urls'      => $urls,
            'total'     => $total,
            'truncated' => $total > self::MAX_URLS,
        ] );
    }

    /**
     * Start monitoring a URL.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return JsonResponse The stored row, or the response refusing it.
     */
    public function store( Request $request ): JsonResponse
    {
        $url = $this->scope->normalize( $request->input( 'url' ) );

        if ( null === $url ) {
            return $this->invalidUrl();
        }

        if ( ! $this->scope->allowsTest( $url ) ) {
            return $this->error(
                self::ERROR_URL_NOT_ALLOWED,
                __( 'This installation will not monitor a URL outside its own site. Enable pagespeed-insights.routes.allow_external_urls to change that.' ),
                403,
            );
        }

        // Checked against the canonical form rather than against what was
        // sent, so posting "https://example.com/about/" when
        // "https://example.com/about" is already monitored is refused as the
        // duplicate it is rather than accepted and silently merged.
        if ( null !== $this->registry->findStored( $url ) ) {
            return $this->error(
                self::ERROR_ALREADY_MONITORED,
                __( '":url" is already monitored.', [ 'url' => $url ] ),
                409,
            );
        }

        $attributes = $this->attributes( $request );

        if ( $attributes instanceof JsonResponse ) {
            return $attributes;
        }

        $stored = $this->registry->add( $url, $attributes );

        if ( null === $stored ) {
            return $this->invalidUrl();
        }

        return response()->json( self::present( $stored ), 201 );
    }

    /**
     * Stop monitoring a URL.
     *
     * The row's result history is kept. `pagespeed_results` carries the URL in
     * its own column and its foreign key nulls rather than cascades, precisely
     * so that un-monitoring a page does not destroy the measurements that make
     * it worth monitoring again later.
     *
     * @since 1.0.0
     *
     * @param  int|string  $id  The row to delete.
     *
     * @return SymfonyResponse An empty 204, or the response refusing it.
     */
    public function destroy( int|string $id ): SymfonyResponse
    {
        $url = PageSpeedUrl::query()->whereKey( (int) $id )->first();

        if ( null === $url ) {
            return $this->error(
                self::ERROR_NOT_FOUND,
                __( 'No monitored URL has that id.' ),
                404,
            );
        }

        $this->registry->delete( $url );

        return response()->noContent();
    }

    /**
     * Read the writable attributes off a request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  The incoming request.
     *
     * @return array<string, mixed>|JsonResponse The attributes, or the response refusing them.
     */
    protected function attributes( Request $request ): array|JsonResponse
    {
        $attributes = [];

        $label = $request->input( 'label' );

        if ( null !== $label && '' !== $label ) {
            if ( ! is_string( $label ) ) {
                return $this->invalidAttribute( 'label', __( 'A label must be text.' ) );
            }

            // Counted in characters rather than bytes, because that is what
            // the varchar(255) column counts. Measuring in bytes would refuse
            // a perfectly storable label the moment it was written in a script
            // that does not fit in one byte a character.
            if ( mb_strlen( trim( $label ) ) > self::MAX_LABEL_LENGTH ) {
                return $this->invalidAttribute( 'label', __(
                    'A label can be at most :max characters.',
                    [ 'max' => self::MAX_LABEL_LENGTH ],
                ) );
            }

            $attributes[ 'label' ] = trim( $label );
        }

        if ( $request->has( 'strategies' ) ) {
            $strategies = $request->input( 'strategies' );

            if ( ! is_array( $strategies ) ) {
                return $this->invalidAttribute( 'strategies', __( 'Strategies must be a list.' ) );
            }

            $known = [ PageSpeedRequest::STRATEGY_MOBILE, PageSpeedRequest::STRATEGY_DESKTOP ];

            foreach ( $strategies as $strategy ) {
                if ( ! is_string( $strategy ) || ! in_array( strtolower( trim( $strategy ) ), $known, true ) ) {
                    return $this->invalidAttribute( 'strategies', __(
                        'A strategy must be one of: :known.',
                        [ 'known' => implode( ', ', $known ) ],
                    ) );
                }
            }

            if ( [] === $strategies ) {
                // Refused rather than stored. An empty list reads as "test
                // nothing", which is a URL that is monitored and never tested
                // — the one state the monitored set must not be able to hold
                // silently. Pausing a URL is what "do not test this" is for.
                return $this->invalidAttribute( 'strategies', __( 'Name at least one strategy to test.' ) );
            }

            $attributes[ 'strategies' ] = array_values( array_unique( array_map(
                static fn ( string $strategy ): string => strtolower( trim( $strategy ) ),
                $strategies,
            ) ) );
        }

        if ( $request->has( 'isActive' ) ) {
            $attributes[ 'is_active' ] = $request->boolean( 'isActive' );
        }

        if ( $request->has( 'testFrequency' ) ) {
            $frequency = $request->input( 'testFrequency' );

            if ( null !== $frequency && '' !== $frequency ) {
                if ( ! is_string( $frequency ) || ! array_key_exists( $frequency, PageSpeedUrl::FREQUENCY_INTERVALS ) ) {
                    return $this->invalidAttribute( 'testFrequency', __(
                        'A test frequency must be one of: :known.',
                        [ 'known' => implode( ', ', array_keys( PageSpeedUrl::FREQUENCY_INTERVALS ) ) ],
                    ) );
                }

                $attributes[ 'test_frequency' ] = $frequency;
            }
        }

        return $attributes;
    }

    /**
     * The response refusing one supplied attribute.
     *
     * @since 1.0.0
     *
     * @param  string  $field  The attribute that was refused.
     * @param  string  $message  Why it was refused.
     *
     * @return JsonResponse The refusal.
     */
    protected function invalidAttribute( string $field, string $message ): JsonResponse
    {
        return response()->json( [
            'error'   => self::ERROR_INVALID_ATTRIBUTE,
            'field'   => $field,
            'message' => $message,
        ], 422 );
    }

    /**
     * Shape one monitored URL for the wire.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedUrl  $url  The monitored URL.
     *
     * @return array{id: int|null, url: string, label: string|null, source: string, editable: bool, isActive: bool, strategies: array<int, string>, testFrequency: string|null, frequency: string, lastTestedAt: string|null} The payload.
     */
    protected static function present( PageSpeedUrl $url ): array
    {
        $id = $url->getKey();

        return [
            'id'            => is_int( $id ) ? $id : null,
            'url'           => (string) $url->url,
            'label'         => $url->label,
            'source'        => (string) $url->source,
            // A hook contribution is an unsaved model with no key, and nothing
            // about it can be changed here.
            'editable'      => is_int( $id ),
            'isActive'      => (bool) $url->is_active,
            'strategies'    => $url->effectiveStrategies(),
            'testFrequency' => $url->test_frequency,
            'frequency'     => $url->frequency(),
            'lastTestedAt'  => $url->last_tested_at?->toIso8601String(),
        ];
    }
}
