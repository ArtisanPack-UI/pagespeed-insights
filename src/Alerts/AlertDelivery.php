<?php

/**
 * The outcome of handing one alert to the notification dispatcher.
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

/**
 * Why an alert did or did not reach anybody.
 *
 * A boolean cannot carry this, and the difference decides whether the caller
 * hands the alert back for another try. Both suppression mechanisms in this
 * package — the staleness re-alert window and the regression digest buffer —
 * are limits on *repetition*, so a transient delivery failure has to be
 * retried while a permanent one must not be:
 *
 * - {@see self::Failed} is the mailer being down for five minutes. The next
 *   scheduled pass should say the same thing again.
 * - {@see self::NoRecipients} is the documented way to run this package on
 *   hooks and logs alone. Retrying it would re-fire the hooks on every pass
 *   for as long as the URL stays broken, which is the spam the suppression
 *   exists to prevent, arriving from the other direction.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
enum AlertDelivery: string
{
    /**
     * The notification went to at least one recipient.
     *
     * @since 1.0.0
     */
    case Delivered = 'delivered';

    /**
     * Nothing was sent because nothing is configured to receive it.
     *
     * @since 1.0.0
     */
    case NoRecipients = 'no_recipients';

    /**
     * Delivery was attempted and threw.
     *
     * @since 1.0.0
     */
    case Failed = 'failed';
}
