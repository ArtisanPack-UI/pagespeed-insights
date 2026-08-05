<?php

/**
 * PageSpeed Insights quota exhaustion exception.
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
 * A keyed project ran out of PageSpeed Insights quota.
 *
 * Transient: the same request will succeed once the quota window rolls over,
 * so queued jobs should release with a long delay rather than burning
 * retries.
 *
 * Only thrown when an API key *is* configured. A 429 with no key is a
 * configuration problem wearing a quota error's clothes — Google's shared
 * anonymous project has a daily limit of zero — and surfaces as
 * {@see MissingApiKeyException} instead.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class QuotaExceededException extends PageSpeedApiException
{
    /**
     * The quota metric Google named, when the error carried one.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $quotaMetric = null;

    /**
     * The quota limit Google named, when the error carried one.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $quotaLimit = null;

    /**
     * Build the exception from a parsed PageSpeed error body.
     *
     * @since 1.0.0
     *
     * @param  string       $url     The URL being tested.
     * @param  string|null  $detail  Google's own error message, when present.
     * @param  string|null  $metric  The exhausted quota metric.
     * @param  string|null  $limit   The exhausted quota limit.
     *
     * @return static The built exception.
     */
    public static function forUrl( string $url, ?string $detail = null, ?string $metric = null, ?string $limit = null ): static
    {
        $exception = new static(
            __(
                'The PageSpeed Insights quota for your API key is exhausted, so :url was not tested. Wait for the quota window to reset, lower the test frequency, or request more quota in the Google Cloud Console. :detail',
                [
                    'url'    => $url,
                    'detail' => $detail ?? '',
                ],
            ),
        );

        $exception->status      = 429;
        $exception->quotaMetric = $metric;
        $exception->quotaLimit  = $limit;

        return $exception;
    }
}
