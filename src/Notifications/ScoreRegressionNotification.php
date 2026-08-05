<?php

/**
 * Score regression notification.
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

use ArtisanPackUI\PageSpeedInsights\Alerts\Regression;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "These PageSpeed scores got worse", as one message.
 *
 * Carries every regression from one digest window rather than one apiece:
 * a single deploy usually regresses every page at once, and thirty emails
 * saying so is how an alert becomes something people filter into a folder.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class ScoreRegressionNotification extends Notification
{
    /**
     * The most regressions one mail message spells out in full.
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
     * @param  array<int, Regression>  $regressions  What regressed.
     * @param  array<int, string>  $channels  The channels to deliver on.
     */
    public function __construct(
        public readonly array $regressions,
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

        foreach ( array_slice( $this->regressions, 0, self::MAX_LINES ) as $regression ) {
            $message->line( '- ' . $regression->describe() );
        }

        $remaining = count( $this->regressions ) - self::MAX_LINES;

        if ( $remaining > 0 ) {
            $message->line( trans_choice(
                'And :count more regression not listed here.|And :count more regressions not listed here.',
                $remaining,
                [ 'count' => (string) $remaining ],
            ) );
        }

        if ( $this->hasDegraded() ) {
            $message->line( __(
                'At least one of these runs completed with data missing, which makes it a weaker basis for comparison than a clean run.',
            ) );
        }

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
            'summary'     => $this->summary(),
            'urls'        => count( $this->urls() ),
            'regressions' => array_map(
                static fn ( Regression $regression ): array => $regression->toArray(),
                array_values( $this->regressions ),
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
        $urls = count( $this->urls() );

        return trans_choice(
            'PageSpeed scores dropped on :count page|PageSpeed scores dropped on :count pages',
            $urls,
            [ 'count' => (string) $urls ],
        );
    }

    /**
     * The opening line.
     *
     * @since 1.0.0
     *
     * @return string The summary.
     */
    public function summary(): string
    {
        $count = count( $this->regressions );
        $urls  = count( $this->urls() );

        return __(
            ':regressions across :urls.',
            [
                'regressions' => trans_choice(
                    ':count score regression|:count score regressions',
                    $count,
                    [ 'count' => (string) $count ],
                ),
                'urls'        => trans_choice(
                    ':count monitored URL|:count monitored URLs',
                    $urls,
                    [ 'count' => (string) $urls ],
                ),
            ],
        );
    }

    /**
     * Whether any of these were detected on a run that lost data.
     *
     * @since 1.0.0
     *
     * @return bool True when at least one run was degraded.
     */
    public function hasDegraded(): bool
    {
        foreach ( $this->regressions as $regression ) {
            if ( $regression->degraded ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The distinct URLs these regressions cover.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The URLs.
     */
    protected function urls(): array
    {
        $urls = [];

        foreach ( $this->regressions as $regression ) {
            $urls[] = $regression->url;
        }

        return array_values( array_unique( $urls ) );
    }
}
