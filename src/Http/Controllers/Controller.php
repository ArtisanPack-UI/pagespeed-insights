<?php

/**
 * Shared behaviour for the package's HTTP controllers.
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
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Http\Support\UrlScope;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * The error vocabulary and input reduction every endpoint shares.
 *
 * ### Errors are codes, not sentences
 *
 * Every failure carries a stable machine-readable `error` alongside its
 * human-readable `message`. A front end that has to branch on prose is a front
 * end that breaks when somebody improves the wording, or when the server is
 * running in a locale the client does not read.
 *
 * ### Input is reduced, not rejected
 *
 * A strategy, a metric, or a range the endpoint does not recognise falls back
 * to the documented default rather than answering 422, which is what the
 * Livewire components do with the same values and for the same reason: these
 * are selectors with a fixed set of options, and a payload naming something
 * outside the set is not a request for the nearest one.
 *
 * The URL is the exception. It is the subject of the request rather than one of
 * its options, so there is no sensible default to fall back to and an
 * unusable one is answered rather than guessed at.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
abstract class Controller
{
    /**
     * No `url` was supplied, or the one that was cannot be tested.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_INVALID_URL = 'invalid_url';

    /**
     * The URL is real, but this installation does not monitor it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_URL_NOT_MONITORED = 'url_not_monitored';

    /**
     * The URL is real, but this installation will not spend quota on it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ERROR_URL_NOT_ALLOWED = 'url_not_allowed';

    /**
     * Build the controller.
     *
     * @since 1.0.0
     *
     * @param  UrlScope  $scope  Decides which URLs may be asked about.
     */
    public function __construct( protected UrlScope $scope )
    {
    }

    /**
     * Reduce a requested URL to one the read endpoints may answer for.
     *
     * @since 1.0.0
     *
     * @param  mixed  $url  The URL as the request wrote it.
     *
     * @return JsonResponse|string The canonical URL, or the response refusing it.
     */
    protected function readableUrl( mixed $url ): string|JsonResponse
    {
        $normalized = $this->scope->normalize( $url );

        if ( null === $normalized ) {
            return $this->invalidUrl();
        }

        if ( ! $this->scope->allowsRead( $normalized ) ) {
            // Deliberately the same answer whether the URL is somebody else's
            // site or simply one this installation has never added: a 404 here
            // would let a caller map the monitored set by probing it.
            return $this->error(
                self::ERROR_URL_NOT_MONITORED,
                __( 'This installation does not monitor that URL.' ),
                403,
            );
        }

        return $normalized;
    }

    /**
     * The response refusing a URL the package cannot use at all.
     *
     * @since 1.0.0
     *
     * @return JsonResponse The refusal.
     */
    protected function invalidUrl(): JsonResponse
    {
        return $this->error(
            self::ERROR_INVALID_URL,
            __(
                'Supply a full http:// or https:// address of at most :max characters. PageSpeed cannot test anything else.',
                [ 'max' => UrlNormalizer::MAX_LENGTH ],
            ),
            422,
        );
    }

    /**
     * Build an error response.
     *
     * @since 1.0.0
     *
     * @param  string  $code  The machine-readable error code.
     * @param  string  $message  The human-readable explanation.
     * @param  int  $status  The HTTP status.
     *
     * @return JsonResponse The response.
     */
    protected function error( string $code, string $message, int $status ): JsonResponse
    {
        return response()->json( [
            'error'   => $code,
            'message' => $message,
        ], $status );
    }

    /**
     * Whether a usable API key is configured.
     *
     * Wrapped because the repository reaches storage — the database driver
     * queries a table an application may not have migrated yet — and an
     * endpoint that 500s on render is worse than one that reports the key as
     * missing.
     *
     * @since 1.0.0
     *
     * @return bool True when a key is available.
     */
    protected function hasApiKey(): bool
    {
        try {
            return app( ApiKeyRepository::class )->isConfigured();
        } catch ( Throwable ) {
            return false;
        }
    }

    /**
     * Reduce a requested form factor to one this package tests.
     *
     * @since 1.0.0
     *
     * @param  mixed  $strategy  The requested form factor.
     *
     * @return string mobile or desktop.
     */
    protected static function strategy( mixed $strategy ): string
    {
        $normalized = is_scalar( $strategy ) ? strtolower( trim( (string) $strategy ) ) : '';

        return PageSpeedRequest::STRATEGY_DESKTOP === $normalized
            ? PageSpeedRequest::STRATEGY_DESKTOP
            : PageSpeedRequest::STRATEGY_MOBILE;
    }
}
