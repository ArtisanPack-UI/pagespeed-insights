<?php

/**
 * One monitored URL that stopped reporting.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Alerts;

use Carbon\CarbonImmutable;

/**
 * A URL with no completed run inside the window its cadence says to expect one.
 *
 * Carries the diagnosis alongside the fact, because the two questions an
 * operator asks on reading "this page stopped reporting" are *since when* and
 * *why*, and the second one is answerable from data the detector already had
 * to look at. An alert that only says three URLs went quiet sends somebody
 * hunting through logs; one that names a revoked API key is fixed in a minute.
 *
 * Immutable, and holds everything a notification line needs so that rendering
 * it never goes back to the database.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class StaleUrl
{
    /**
     * Recent runs for this URL failed, and said why.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAUSE_FAILING = 'failing';

    /**
     * No API key is configured, so no run could have succeeded.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAUSE_NO_API_KEY = 'no_api_key';

    /**
     * Scheduling is switched off, so nothing is dispatching runs.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAUSE_SCHEDULING_DISABLED = 'scheduling_disabled';

    /**
     * Nothing was written at all — not even a failure — so the runs are not
     * reaching a worker.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAUSE_NOT_RUNNING = 'not_running';

    /**
     * Build a stale URL.
     *
     * @since 1.0.0
     *
     * @param  string  $url  The URL that stopped reporting.
     * @param  string  $frequency  The cadence it is expected on, resolved against the package default.
     * @param  int  $missedCycles  How many expected runs may be missed before this counts as stale.
     * @param  string  $cause  One of the CAUSE_* constants.
     * @param  CarbonImmutable|null  $lastResultAt  When it last completed a run, null when it never has.
     * @param  string|null  $causeDetail  The most recent error message, when there was one.
     * @param  int|null  $urlId  The monitored row's key.
     * @param  string|null  $label  The operator-set label, when it has one.
     * @param  int  $failures  How many runs failed inside the window.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $frequency,
        public readonly int $missedCycles,
        public readonly string $cause = self::CAUSE_NOT_RUNNING,
        public readonly ?CarbonImmutable $lastResultAt = null,
        public readonly ?string $causeDetail = null,
        public readonly ?int $urlId = null,
        public readonly ?string $label = null,
        public readonly int $failures = 0,
    ) {
    }

    /**
     * The cadence name as a person reads it.
     *
     * @since 1.0.0
     *
     * @return string The display name.
     */
    public function frequencyName(): string
    {
        return match ( $this->frequency ) {
            'hourly'  => __( 'hourly' ),
            'daily'   => __( 'daily' ),
            'weekly'  => __( 'weekly' ),
            'monthly' => __( 'monthly' ),
            default   => $this->frequency,
        };
    }

    /**
     * How the URL is named in a notification: its label, or the URL itself.
     *
     * @since 1.0.0
     *
     * @return string The display name.
     */
    public function name(): string
    {
        return null === $this->label ? $this->url : $this->label . ' (' . $this->url . ')';
    }

    /**
     * Why this URL is probably not reporting, in a sentence.
     *
     * @since 1.0.0
     *
     * @return string The diagnosis.
     */
    public function describeCause(): string
    {
        return match ( $this->cause ) {
            self::CAUSE_NO_API_KEY          => __(
                'No PageSpeed Insights API key is configured, so no run can succeed. Set PAGESPEED_API_KEY, or store a key through the configured driver.',
            ),
            self::CAUSE_SCHEDULING_DISABLED => __(
                'Scheduling is turned off (pagespeed-insights.scheduling.enabled), so no runs are being queued.',
            ),
            self::CAUSE_FAILING             => null === $this->causeDetail
                ? trans_choice(
                    ':count run failed since then, without recording why.|:count runs failed since then, without recording why.',
                    $this->failures,
                    [ 'count' => (string) $this->failures ],
                )
                : __(
                    ':runs since then. Last error: :error',
                    [
                        'runs'  => trans_choice(
                            ':count failed run|:count failed runs',
                            $this->failures,
                            [ 'count' => (string) $this->failures ],
                        ),
                        'error' => $this->causeDetail,
                    ],
                ),
            default                         => __(
                'Nothing was recorded at all — not even a failure — so the runs are not reaching a worker. Check that the scheduler is running and that the queue is being worked.',
            ),
        };
    }

    /**
     * One line describing this URL, ready for a notification.
     *
     * @since 1.0.0
     *
     * @return string The line.
     */
    public function describe(): string
    {
        $line = null === $this->lastResultAt
            ? __(
                ':name has never completed a test, and is meant to be tested :frequency.',
                [ 'name' => $this->name(), 'frequency' => $this->frequencyName() ],
            )
            : __(
                ':name last completed a test on :date, and is meant to be tested :frequency.',
                [
                    'name'      => $this->name(),
                    'date'      => $this->lastResultAt->toDayDateTimeString(),
                    'frequency' => $this->frequencyName(),
                ],
            );

        return $line . ' ' . $this->describeCause();
    }

    /**
     * The stale URL as an array, for the hook payload and the array channels.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> The array form.
     */
    public function toArray(): array
    {
        return [
            'url'            => $this->url,
            'url_id'         => $this->urlId,
            'label'          => $this->label,
            'frequency'      => $this->frequency,
            'missed_cycles'  => $this->missedCycles,
            'last_result_at' => $this->lastResultAt?->toIso8601String(),
            'cause'          => $this->cause,
            'cause_detail'   => $this->causeDetail,
            'failures'       => $this->failures,
        ];
    }
}
