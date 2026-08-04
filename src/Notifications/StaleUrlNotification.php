<?php

/**
 * Stale monitored URL notification.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Notifications;

use ArtisanPackUI\PageSpeedInsights\Alerts\StaleUrl;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "These pages stopped reporting", as one message.
 *
 * Carries every URL found stale in one pass rather than one apiece: the
 * failures that stop runs happening — a revoked key, a dead worker, a
 * switched-off scheduler — stop them for the whole monitored set at once, so
 * one notification per URL would mean an inbox full of the same news.
 *
 * The likely cause travels with each line. It is the difference between an
 * alert that sends somebody hunting and one that is acted on.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class StaleUrlNotification extends Notification
{
    /**
     * The most URLs one mail message spells out in full.
     *
     * Past this the message says how many were left over rather than running
     * to several screens — and says it, rather than truncating quietly.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_LINES = 25;

    /**
     * Build the notification.
     *
     * @since 1.0.0
     *
     * @param  array<int, StaleUrl>  $stale  The URLs that stopped reporting.
     * @param  array<int, string>  $channels  The channels to deliver on.
     */
    public function __construct(
        public readonly array $stale,
        protected array $channels = [ 'mail' ],
    ) {
    }

    /**
     * The channels this notification is delivered on.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return array<int, string> The channel names.
     */
    public function via( mixed $notifiable ): array
    {
        return $this->channels;
    }

    /**
     * The email.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return MailMessage The message.
     */
    public function toMail( mixed $notifiable ): MailMessage
    {
        $message = ( new MailMessage() )
            ->subject( $this->subject() )
            ->line( $this->summary() );

        foreach ( array_slice( $this->stale, 0, self::MAX_LINES ) as $url ) {
            $message->line( '- ' . $url->describe() );
        }

        $remaining = count( $this->stale ) - self::MAX_LINES;

        if ( $remaining > 0 ) {
            $message->line( trans_choice(
                'And :count more URL not listed here.|And :count more URLs not listed here.',
                $remaining,
                [ 'count' => (string) $remaining ],
            ) );
        }

        $message->line( trans_choice(
            'Its scores have not changed because nothing new was measured, so this page\'s trend chart is showing the last run that succeeded rather than how the page is performing now.'
                . '|Their scores have not changed because nothing new was measured, so the trend charts for these pages are showing the last run that succeeded rather than how the pages are performing now.',
            count( $this->stale ),
        ) );

        return $message;
    }

    /**
     * The notification as an array, for the database and broadcast channels.
     *
     * @since 1.0.0
     *
     * @param  mixed  $notifiable  The recipient.
     *
     * @return array<string, mixed> The payload.
     */
    public function toArray( mixed $notifiable ): array
    {
        return [
            'summary' => $this->summary(),
            'urls'    => count( $this->stale ),
            'stale'   => array_map(
                static fn ( StaleUrl $url ): array => $url->toArray(),
                array_values( $this->stale ),
            ),
        ];
    }

    /**
     * The subject line.
     *
     * @since 1.0.0
     *
     * @return string The subject.
     */
    public function subject(): string
    {
        $count = count( $this->stale );

        return trans_choice(
            ':count monitored page has stopped reporting PageSpeed results|:count monitored pages have stopped reporting PageSpeed results',
            $count,
            [ 'count' => (string) $count ],
        );
    }

    /**
     * The opening line, naming the shared cause when there is one.
     *
     * @since 1.0.0
     *
     * @return string The summary.
     */
    public function summary(): string
    {
        $count = count( $this->stale );

        $opening = trans_choice(
            ':count monitored URL has no completed test inside the window its schedule expects one in.|:count monitored URLs have no completed test inside the window their schedules expect one in.',
            $count,
            [ 'count' => (string) $count ],
        );

        // With one URL in the list its own line already carries the cause, so
        // repeating it here would say the same sentence twice.
        $shared = 1 === $count ? null : $this->sharedCause();

        return null === $shared ? $opening : $opening . ' ' . $shared->describeCause();
    }

    /**
     * The one diagnosis every stale URL shares, when they all share one.
     *
     * A missing key or a switched-off scheduler explains the whole list at
     * once, and saying so up front beats making somebody read twenty
     * identical lines to notice it.
     *
     * @since 1.0.0
     *
     * @return StaleUrl|null A URL carrying the shared cause, or null when the causes differ.
     */
    public function sharedCause(): ?StaleUrl
    {
        $first = null;

        foreach ( $this->stale as $url ) {
            if ( null === $first ) {
                $first = $url;

                continue;
            }

            if ( $url->cause !== $first->cause ) {
                return null;
            }

            // The detail is only part of the sentence for a failing URL —
            // every other cause renders the same words whatever it holds. A
            // page still carrying last week's 404 in `causeDetail` must not
            // stop "no API key is configured" being said once, up front,
            // which is the whole reason this line exists.
            if ( StaleUrl::CAUSE_FAILING === $first->cause && $url->causeDetail !== $first->causeDetail ) {
                return null;
            }
        }

        return $first;
    }
}
