<?php

/**
 * Base PageSpeed Insights API exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Anything that goes wrong talking to, or reading a response from, the
 * PageSpeed Insights API.
 *
 * Three exception types cover three genuinely different causes, because at
 * the HTTP layer they look alike and a developer has to respond to each one
 * differently:
 *
 * - This class — the target failed, the transport failed, or the API said
 *   something unexpected. Usually worth a retry.
 * - {@see QuotaExceededException} — transient; back off and try later.
 * - {@see MissingApiKeyException} — configuration; retrying never helps.
 *
 * Callers that need the retry posture without matching on class names should
 * read {@see self::isRetryable()} rather than inspecting the HTTP status,
 * because keyless PageSpeed returns 429 for a configuration problem.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class PageSpeedApiException extends RuntimeException
{
    /**
     * The Lighthouse runtime error code, when this exception came from a
     * `lighthouseResult.runtimeError`. Preserved verbatim from the response.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $lighthouseErrorCode = null;

    /**
     * The HTTP status that produced this exception, when there was one.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    public ?int $status = null;

    /**
     * The network never reached Google.
     *
     * @since 1.0.0
     *
     * @param  string     $url       The URL being tested.
     * @param  Throwable  $previous  The underlying transport exception.
     *
     * @return static The built exception.
     */
    public static function transportFailure( string $url, Throwable $previous ): static
    {
        return new static(
            __(
                'Could not reach the PageSpeed Insights API while testing :url. Check outbound network access and try again. Transport error: :message',
                [ 'url' => $url, 'message' => self::redactCredentials( $previous->getMessage() ) ],
            ),
            0,
            $previous,
        );
    }

    /**
     * Strip API keys out of a message before it reaches a log.
     *
     * Guzzle appends the full request URI to its connection-error messages,
     * so anything carried in the query string ends up in exception reports
     * verbatim. This client sends the key on a header for exactly that
     * reason, but an application is free to point `pagespeed-insights.endpoint`
     * at a URL that already has one embedded.
     *
     * @since 1.0.0
     *
     * @param  string  $message  The raw message.
     *
     * @return string The message with any key parameter redacted.
     */
    public static function redactCredentials( string $message ): string
    {
        return (string) preg_replace( '/([?&](?:key|access_token)=)[^&\s]+/i', '$1[redacted]', $message );
    }

    /**
     * The API answered with a non-success status.
     *
     * @since 1.0.0
     *
     * @param  int          $status   The HTTP status code.
     * @param  string       $url      The URL being tested.
     * @param  string|null  $detail   The API's own error message, when it sent one.
     *
     * @return static The built exception.
     */
    public static function apiError( int $status, string $url, ?string $detail = null ): static
    {
        $exception = new static(
            __(
                'The PageSpeed Insights API returned HTTP :status while testing :url. :detail',
                [
                    'status' => (string) $status,
                    'url'    => $url,
                    'detail' => $detail ?? __( 'No further detail was returned.' ),
                ],
            ),
        );

        $exception->status = $status;

        return $exception;
    }

    /**
     * Lighthouse itself failed. PageSpeed reports this as HTTP 200 with a
     * populated `lighthouseResult.runtimeError`, so a 2xx status alone is not
     * enough to call a run successful.
     *
     * @since 1.0.0
     *
     * @param  string  $code     The Lighthouse error code, e.g. ERRORED_DOCUMENT_REQUEST.
     * @param  string  $message  Lighthouse's own message, preserved verbatim.
     * @param  string  $url      The URL being tested.
     *
     * @return static The built exception.
     */
    public static function lighthouseRuntimeError( string $code, string $message, string $url ): static
    {
        $exception = new static(
            __(
                'Lighthouse could not analyze :url (:code): :message',
                [ 'url' => $url, 'code' => $code, 'message' => $message ],
            ),
        );

        $exception->lighthouseErrorCode = $code;
        $exception->status              = 200;

        return $exception;
    }

    /**
     * The response parsed as JSON but was not a PageSpeed result.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL being tested.
     *
     * @return static The built exception.
     */
    public static function malformedResponse( string $url ): static
    {
        return new static(
            __(
                'The PageSpeed Insights API returned a response for :url that could not be read as a PageSpeed result. This usually means the endpoint config points somewhere unexpected.',
                [ 'url' => $url ],
            ),
        );
    }

    /**
     * Whether retrying this request could ever succeed without a developer
     * changing something first.
     *
     * @since 1.0.0
     *
     * @return bool True when a retry is worth attempting.
     */
    public function isRetryable(): bool
    {
        return true;
    }
}
