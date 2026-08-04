<?php

/**
 * Alert delivery and digest buffering.
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

use ArtisanPackUI\PageSpeedInsights\Jobs\SendRegressionDigest;
use ArtisanPackUI\PageSpeedInsights\Notifications\ScoreRegressionNotification;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Who alerts go to, on which channels, and how they are batched.
 *
 * Split out of the detector because the alerting half is not specific to
 * score regressions: the staleness detector delivers to the same recipients,
 * on the same channels, batched the same way.
 *
 * ### Why the digest exists
 *
 * One monitoring cycle produces one result per URL per form factor, each
 * inspected in its own queued job, in any order, possibly on different
 * workers. Alerting on each one directly means a site-wide regression — the
 * common case, since it is usually one deploy that caused it — arrives as
 * dozens of near-identical emails. Regressions are therefore buffered in the
 * cache for `alerts.digest.wait` seconds and sent as one notification, with
 * exactly one flush job scheduled per window.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class AlertDispatcher
{
    /**
     * The cache key holding the buffered regressions.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DIGEST_KEY = 'pagespeed-insights:alerts:regressions';

    /**
     * The cache key marking a flush as already scheduled.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DIGEST_PENDING_KEY = 'pagespeed-insights:alerts:regressions:pending';

    /**
     * The cache key counting consecutive failed digest deliveries.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DIGEST_RETRIES_KEY = 'pagespeed-insights:alerts:regressions:retries';

    /**
     * Seconds to gather regressions for when config carries no usable value.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const DEFAULT_DIGEST_WAIT = 300;

    /**
     * The most regressions one digest window buffers.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_BUFFERED = 500;

    /**
     * How many times a digest window is handed back after a failed delivery
     * before it is given up on.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_DELIVERY_RETRIES = 3;

    /**
     * Build the dispatcher.
     *
     * @since 1.0.0
     *
     * @param  NotificationDispatcher  $notifications  Laravel's notification dispatcher.
     * @param  CacheFactory  $cache  The cache manager, for the digest buffer.
     * @param  ConfigRepository  $config  The application config repository.
     * @param  LoggerInterface  $logger  Where delivery diagnostics go.
     */
    public function __construct(
        protected NotificationDispatcher $notifications,
        protected CacheFactory $cache,
        protected ConfigRepository $config,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Take regressions that have just been detected.
     *
     * Buffers them when digesting is on, sends them straight out when it is
     * off. A straight-out send that fails is logged and dropped rather than
     * retried: there is no window holding it back from being said again, and
     * the only machinery that could carry it to a later attempt is the digest
     * this caller has switched off.
     *
     * @since 1.0.0
     *
     * @param  array<int, Regression>  $regressions  What regressed.
     *
     * @return void
     */
    public function report( array $regressions ): void
    {
        if ( [] === $regressions ) {
            return;
        }

        if ( ! $this->digesting() ) {
            $this->send( $regressions );

            return;
        }

        $this->buffer( $regressions );
    }

    /**
     * Send everything the buffer has gathered, and empty it.
     *
     * A window whose delivery threw is put back rather than dropped, and
     * another flush is scheduled for it: the buffer exists to stop one deploy
     * arriving as thirty emails, not to swallow the one email it was
     * collapsed into. Putting it back is bounded by
     * {@see self::MAX_DELIVERY_RETRIES}, because a mailer that is broken
     * rather than briefly down would otherwise carry the same window forward
     * for as long as the queue runs.
     *
     * @since 1.0.0
     *
     * @return int How many regressions left the buffer; 0 when it was empty
     *             or was put back for another attempt.
     */
    public function flush(): int
    {
        $store = $this->store();

        // The pending marker is released before the buffer is read, not
        // after. Either order leaves a gap, and this is the harmless one: a
        // worker reporting in between opens a new window and queues a flush
        // for it, which at worst finds an empty buffer. The other order
        // strands whatever it wrote until some later regression happens to
        // open a window for it.
        $store->forget( self::DIGEST_PENDING_KEY );

        /** @var array<int, array<string, mixed>> $stored */
        $stored = (array) $store->pull( self::DIGEST_KEY, [] );

        if ( [] === $stored ) {
            return 0;
        }

        $regressions = array_map(
            static fn ( array $entry ): Regression => Regression::fromArray( $entry ),
            array_values( array_filter( $stored, 'is_array' ) ),
        );

        $delivery = $this->send( $regressions );

        if ( AlertDelivery::Failed === $delivery && $this->retry( $store, $regressions ) ) {
            return 0;
        }

        $store->forget( self::DIGEST_RETRIES_KEY );

        return count( $regressions );
    }

    /**
     * Deliver one notification to every configured recipient.
     *
     * Made public because the staleness detector delivers its own
     * notification to the same recipients on the same channels.
     *
     * A delivery failure is logged and swallowed rather than thrown: it
     * reaches here from inside the queued run that stored the result, and a
     * misconfigured mailer must not turn a successful PageSpeed run into a
     * failed job that gets retried — which would re-test the URL, cost quota,
     * and alert again.
     *
     * @since 1.0.0
     *
     * @param  Notification  $notification  The notification to deliver.
     *
     * @return AlertDelivery What became of it, so a caller holding a
     *                       suppression marker knows whether to release it.
     */
    public function notify( Notification $notification ): AlertDelivery
    {
        $recipients = $this->recipients();

        if ( [] === $recipients ) {
            $this->logger->debug(
                'A PageSpeed alert was raised but nobody is configured to receive it. Set alerts.mail_to or alerts.notifiable.',
                [ 'notification' => $notification::class ],
            );

            return AlertDelivery::NoRecipients;
        }

        try {
            $this->notifications->send( $recipients, $notification );
        } catch ( Throwable $exception ) {
            $this->logger->error(
                'A PageSpeed alert could not be delivered.',
                [ 'notification' => $notification::class, 'error' => $exception->getMessage() ],
            );

            return AlertDelivery::Failed;
        }

        return AlertDelivery::Delivered;
    }

    /**
     * The channels alerts are delivered on.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The channel names.
     */
    public function channels(): array
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.channels', [ 'mail' ] );

        if ( is_string( $configured ) ) {
            $configured = explode( ',', $configured );
        }

        if ( ! is_array( $configured ) ) {
            return [ 'mail' ];
        }

        $channels = [];

        foreach ( $configured as $channel ) {
            if ( is_string( $channel ) && '' !== trim( $channel ) ) {
                $channels[] = trim( $channel );
            }
        }

        if ( [] === $channels ) {
            // A notification with no channels is delivered to nobody without
            // erroring, which is the one outcome an alerting feature must
            // never produce quietly. Fall back to mail and say why.
            $this->logger->warning(
                'No usable PageSpeed alert channels are configured, so alerts fall back to mail. Set alerts.channels to a list of channel names.',
                [ 'configured' => $configured ],
            );

            return [ 'mail' ];
        }

        return array_values( array_unique( $channels ) );
    }

    /**
     * Send a set of regressions as one notification.
     *
     * @since 1.0.0
     *
     * @param  array<int, Regression>  $regressions  What regressed.
     *
     * @return AlertDelivery What became of it.
     */
    protected function send( array $regressions ): AlertDelivery
    {
        return $this->notify( new ScoreRegressionNotification( $regressions, $this->channels() ) );
    }

    /**
     * Put a window that could not be delivered back for another attempt.
     *
     * @since 1.0.0
     *
     * @param  CacheRepository  $store  The cache store holding the buffer.
     * @param  array<int, Regression>  $regressions  What could not be sent.
     *
     * @return bool True when it was put back, false when it was given up on.
     */
    protected function retry( CacheRepository $store, array $regressions ): bool
    {
        if ( ! $this->digesting() ) {
            // Retrying means buffering and scheduling a flush, which is the
            // digest. With no window to schedule into there is nowhere to put
            // this, and a zero-second buffer would expire before the job that
            // was meant to read it ran.
            return false;
        }

        $attempts = (int) $store->get( self::DIGEST_RETRIES_KEY, 0 ) + 1;

        if ( $attempts > self::MAX_DELIVERY_RETRIES ) {
            $this->logger->error(
                'PageSpeed regressions could not be delivered after repeated attempts, so the digest window was dropped. The regressions themselves are in the log above, one line per detection.',
                [ 'attempts' => self::MAX_DELIVERY_RETRIES, 'regressions' => count( $regressions ) ],
            );

            $store->forget( self::DIGEST_RETRIES_KEY );

            return false;
        }

        $store->put( self::DIGEST_RETRIES_KEY, $attempts, $this->digestWait() * 2 );

        $this->buffer( $regressions );

        return true;
    }

    /**
     * Add regressions to the buffer, scheduling a flush if none is pending.
     *
     * The read-modify-write is done under a cache lock where the store
     * supports one, because two workers finishing a run in the same instant
     * would otherwise each write a buffer containing only their own findings,
     * and one of the two would win.
     *
     * @since 1.0.0
     *
     * @param  array<int, Regression>  $regressions  What regressed.
     *
     * @return void
     */
    protected function buffer( array $regressions ): void
    {
        $store   = $this->store();
        $wait    = $this->digestWait();
        $entries = array_map(
            static fn ( Regression $regression ): array => $regression->toArray(),
            $regressions,
        );

        $append = function () use ( $store, $entries, $wait ): void {
            /** @var array<int, array<string, mixed>> $buffered */
            $buffered = (array) $store->get( self::DIGEST_KEY, [] );
            $merged   = array_merge( $buffered, $entries );

            // A cycle over a thousand URLs on a bad day can regress four
            // categories on each of two form factors, and the whole buffer is
            // one cache entry that has to be written, read, and rendered. The
            // cap is on the buffer rather than only on the email because the
            // entry itself has to stay a sane size — and it is announced, not
            // applied quietly, because a truncated alert that does not say it
            // was truncated is worse than a long one.
            if ( count( $merged ) > self::MAX_BUFFERED ) {
                $this->logger->warning(
                    'More PageSpeed regressions were detected in one digest window than the buffer holds, so the newest were not added to the alert. They are in the log above, one line per detection.',
                    [ 'kept' => self::MAX_BUFFERED, 'dropped' => count( $merged ) - self::MAX_BUFFERED ],
                );

                $merged = array_slice( $merged, 0, self::MAX_BUFFERED );
            }

            // Twice the window, so a flush job delayed by a busy queue still
            // finds what it was scheduled to send.
            $store->put( self::DIGEST_KEY, $merged, $wait * 2 );
        };

        $lock = $this->lock();

        try {
            null === $lock ? $append() : $lock->block( 5, $append );
        } catch ( LockTimeoutException ) {
            // Waiting longer would hold a queue worker on a mutex to avoid a
            // race that costs, at worst, one line in one email. Append
            // unguarded instead.
            $append();
        }

        $this->scheduleFlush( $store, $wait );
    }

    /**
     * Queue the flush for this window, unless one is already queued.
     *
     * @since 1.0.0
     *
     * @param  CacheRepository  $store  The cache store holding the buffer.
     * @param  int  $wait  Seconds to gather regressions for.
     *
     * @return void
     */
    protected function scheduleFlush( CacheRepository $store, int $wait ): void
    {
        // `add()` is the atomic half of this: the worker that creates the
        // marker is the one that queues the flush, and every other worker
        // reporting into the same window sees it already there.
        if ( ! $store->add( self::DIGEST_PENDING_KEY, true, $wait * 2 ) ) {
            return;
        }

        SendRegressionDigest::dispatch()->delay( $wait );
    }

    /**
     * Everyone configured to receive alerts.
     *
     * @since 1.0.0
     *
     * @return array<int, object> The notifiables.
     */
    protected function recipients(): array
    {
        $recipients = [];
        $addresses  = $this->mailTo();

        if ( [] !== $addresses ) {
            $recipients[] = ( new AnonymousNotifiable() )->route( 'mail', $addresses );
        }

        $notifiable = $this->notifiable();

        if ( null !== $notifiable ) {
            $recipients[] = $notifiable;
        }

        return $recipients;
    }

    /**
     * The configured email recipients.
     *
     * Accepts an array or a comma-separated string, because the value is as
     * likely to arrive from an env var as from the published config file.
     *
     * @since 1.0.0
     *
     * @return array<int, string> The addresses.
     */
    protected function mailTo(): array
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.mail_to', [] );

        if ( is_string( $configured ) ) {
            $configured = explode( ',', $configured );
        }

        if ( ! is_array( $configured ) ) {
            return [];
        }

        $addresses = [];

        foreach ( $configured as $address ) {
            if ( ! is_string( $address ) || '' === trim( $address ) ) {
                continue;
            }

            $addresses[] = trim( $address );
        }

        return array_values( array_unique( $addresses ) );
    }

    /**
     * The configured notifiable, when there is one the container can build.
     *
     * A class that cannot be resolved is logged rather than thrown, for the
     * same reason a delivery failure is: this runs inside the job that stored
     * a perfectly good result.
     *
     * @since 1.0.0
     *
     * @return object|null The notifiable.
     */
    protected function notifiable(): ?object
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.notifiable' );

        if ( is_object( $configured ) ) {
            return $configured;
        }

        if ( ! is_string( $configured ) || '' === trim( $configured ) ) {
            return null;
        }

        try {
            return app( trim( $configured ) );
        } catch ( Throwable $exception ) {
            $this->logger->error(
                'The configured PageSpeed alert notifiable could not be resolved, so nothing was sent to it.',
                [ 'notifiable' => $configured, 'error' => $exception->getMessage() ],
            );

            return null;
        }
    }

    /**
     * Whether regressions are batched rather than sent as they are found.
     *
     * @since 1.0.0
     *
     * @return bool True when digesting is on.
     */
    protected function digesting(): bool
    {
        return false !== $this->config->get( 'pagespeed-insights.alerts.digest.enabled', true )
            && $this->digestWait() > 0;
    }

    /**
     * Seconds to gather regressions for before sending.
     *
     * @since 1.0.0
     *
     * @return int The window.
     */
    protected function digestWait(): int
    {
        $configured = $this->config->get( 'pagespeed-insights.alerts.digest.wait', self::DEFAULT_DIGEST_WAIT );

        return is_numeric( $configured ) && (int) $configured >= 0
            ? (int) $configured
            : self::DEFAULT_DIGEST_WAIT;
    }

    /**
     * The cache store the buffer lives in.
     *
     * @since 1.0.0
     *
     * @return CacheRepository The store.
     */
    protected function store(): CacheRepository
    {
        $name = $this->config->get( 'pagespeed-insights.alerts.digest.store' );

        return $this->cache->store( is_string( $name ) && '' !== trim( $name ) ? trim( $name ) : null );
    }

    /**
     * A lock over the buffer, when the store can provide one.
     *
     * @since 1.0.0
     *
     * @return Lock|null The lock, or null on a store without locking.
     */
    protected function lock(): ?Lock
    {
        $store = $this->store()->getStore();

        if ( ! $store instanceof LockProvider ) {
            return null;
        }

        return $store->lock( self::DIGEST_KEY . ':lock', 10 );
    }
}
