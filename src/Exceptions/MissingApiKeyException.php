<?php

/**
 * Missing PageSpeed Insights API key exception.
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

/**
 * No usable PageSpeed Insights credential is configured.
 *
 * This is a configuration failure, not a transient one: {@see self::isRetryable()}
 * returns false, and queued jobs should fail fast rather than back off, because
 * no amount of waiting will produce an API key.
 *
 * It covers two situations that look different but need the same fix:
 *
 * - Nothing is configured at all, caught before a request is sent so a worker
 *   does not spend 20-60 seconds on a run that is guaranteed to fail.
 * - A request went out without an API key and came back 429. Google's shared
 *   anonymous project has a daily quota of zero, so an unkeyed 429 means "no
 *   key", not "out of quota". Reporting it as quota exhaustion is what makes a
 *   never-configured install present as a broken queue.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class MissingApiKeyException extends PageSpeedApiException
{
    /**
     * Nothing is configured: no API key, and no connected Google account to
     * borrow an access token from.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL that was going to be tested.
     *
     * @return static The built exception.
     */
    public static function notConfigured( string $url ): static
    {
        return new static(
            __(
                'No PageSpeed Insights API key is configured, so :url was not tested. Create a key in the Google Cloud Console with the PageSpeed Insights API enabled, then set PAGESPEED_API_KEY (or store it through the "database" or "cms" driver). PageSpeed has no working keyless mode: Google\'s shared anonymous project has a daily quota of zero.',
                [ 'url' => $url ],
            ),
        );
    }

    /**
     * The request was made without an API key and came back 429.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL being tested.
     *
     * @return static The built exception.
     */
    public static function quotaBlockedWithoutKey( string $url ): static
    {
        $exception = new static(
            __(
                'PageSpeed Insights refused the request for :url with a quota error because no API key was sent. An OAuth token alone does not carry usable PageSpeed quota. Set PAGESPEED_API_KEY to a key from a Google Cloud project with the PageSpeed Insights API enabled.',
                [ 'url' => $url ],
            ),
        );

        $exception->status = 429;

        return $exception;
    }

    /**
     * Configuration problems never resolve on their own.
     *
     * @since 1.0.0
     *
     * @return bool Always false.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
