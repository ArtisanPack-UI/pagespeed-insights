<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Alerts\AlertDispatcher;
use ArtisanPackUI\PageSpeedInsights\Alerts\Regression;
use ArtisanPackUI\PageSpeedInsights\Jobs\SendRegressionDigest;
use ArtisanPackUI\PageSpeedInsights\Notifications\ScoreRegressionNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.alerts.enabled', true );
    config()->set( 'pagespeed-insights.alerts.mail_to', [ 'ops@example.com' ] );
    config()->set( 'pagespeed-insights.alerts.notifiable', null );
    config()->set( 'pagespeed-insights.alerts.channels', [ 'mail' ] );
    config()->set( 'pagespeed-insights.alerts.digest.enabled', true );
    config()->set( 'pagespeed-insights.alerts.digest.wait', 300 );

    Cache::flush();

    // The digest is a delayed job, and the sync queue ignores delays: without
    // this every test in this file would flush the moment it buffered.
    Queue::fake();
} );

/**
 * A regression on one URL, form factor, and category.
 *
 * @param  string  $url  The URL that regressed.
 * @param  string  $strategy  The form factor.
 * @param  string  $category  The category.
 *
 * @return Regression The regression.
 */
function psiRegression(
    string $url = 'https://example.com/',
    string $strategy = 'mobile',
    string $category = 'performance',
): Regression {
    return new Regression(
        type: Regression::TYPE_DROP,
        url: $url,
        strategy: $strategy,
        category: $category,
        currentScore: 55,
        previousScore: 90,
    );
}

it( 'buffers regressions and queues exactly one flush per window', function (): void {
    $dispatcher = app( AlertDispatcher::class );

    $dispatcher->report( [ psiRegression( 'https://example.com/a' ) ] );
    $dispatcher->report( [ psiRegression( 'https://example.com/a', 'desktop' ) ] );
    $dispatcher->report( [ psiRegression( 'https://example.com/b' ) ] );

    Queue::assertPushed( SendRegressionDigest::class, 1 );

    expect( Cache::get( AlertDispatcher::DIGEST_KEY ) )->toHaveCount( 3 );
} );

it( 'sends one notification for the whole window when the digest is flushed', function (): void {
    Notification::fake();

    $dispatcher = app( AlertDispatcher::class );

    $dispatcher->report( [ psiRegression( 'https://example.com/a' ) ] );
    $dispatcher->report( [ psiRegression( 'https://example.com/a', 'desktop' ) ] );
    $dispatcher->report( [ psiRegression( 'https://example.com/b' ) ] );

    Notification::assertNothingSent();

    expect( $dispatcher->flush() )->toBe( 3 );

    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );

    Notification::assertSentOnDemand(
        ScoreRegressionNotification::class,
        fn ( ScoreRegressionNotification $notification ): bool => 3 === count( $notification->regressions )
            && str_contains( $notification->subject(), '2 pages' ),
    );
} );

it( 'empties the buffer once it has been flushed', function (): void {
    Notification::fake();

    $dispatcher = app( AlertDispatcher::class );
    $dispatcher->report( [ psiRegression() ] );

    expect( $dispatcher->flush() )->toBe( 1 )
        ->and( $dispatcher->flush() )->toBe( 0 )
        ->and( Cache::has( AlertDispatcher::DIGEST_KEY ) )->toBeFalse()
        ->and( Cache::has( AlertDispatcher::DIGEST_PENDING_KEY ) )->toBeFalse();

    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );
} );

it( 'queues a new flush for the next window after one has gone out', function (): void {
    Notification::fake();

    $dispatcher = app( AlertDispatcher::class );

    $dispatcher->report( [ psiRegression() ] );
    $dispatcher->flush();
    $dispatcher->report( [ psiRegression() ] );

    Queue::assertPushed( SendRegressionDigest::class, 2 );
} );

it( 'sends immediately when digesting is switched off', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );

    app( AlertDispatcher::class )->report( [ psiRegression(), psiRegression( 'https://example.com/b' ) ] );

    Queue::assertNotPushed( SendRegressionDigest::class );

    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );
} );

it( 'sends nothing when nothing regressed', function (): void {
    Notification::fake();

    app( AlertDispatcher::class )->report( [] );

    Queue::assertNotPushed( SendRegressionDigest::class );
    Notification::assertNothingSent();
} );

it( 'sends nothing when nobody is configured to receive it', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.mail_to', [] );
    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertNothingSent();
} );

it( 'reads a comma-separated recipient list, as an env var would supply it', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.mail_to', 'ops@example.com, dev@example.com ,' );
    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertSentOnDemand(
        ScoreRegressionNotification::class,
        fn ( ScoreRegressionNotification $notification, array $channels, AnonymousNotifiable $notifiable ): bool => [
            'ops@example.com',
            'dev@example.com',
        ] === $notifiable->routes[ 'mail' ],
    );
} );

it( 'notifies a configured notifiable alongside the mail recipients', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );
    config()->set( 'pagespeed-insights.alerts.notifiable', PsiTestNotifiable::class );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertSentTo( new PsiTestNotifiable(), ScoreRegressionNotification::class );
    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );
} );

it( 'carries on when the configured notifiable cannot be resolved', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );
    config()->set( 'pagespeed-insights.alerts.notifiable', 'App\\Nothing\\AtAll' );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );
} );

it( 'delivers on the configured channels', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );
    config()->set( 'pagespeed-insights.alerts.channels', [ 'mail', 'database' ] );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertSentOnDemand(
        ScoreRegressionNotification::class,
        fn ( ScoreRegressionNotification $notification, array $channels ): bool => [ 'mail', 'database' ] === $channels,
    );
} );

it( 'flushes the buffer from the queued digest job', function (): void {
    Notification::fake();

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    ( new SendRegressionDigest() )->handle( app( AlertDispatcher::class ) );

    Notification::assertSentOnDemandTimes( ScoreRegressionNotification::class, 1 );
} );

/**
 * A stand-in for an application's own notifiable.
 */
class PsiTestNotifiable
{
    use Notifiable;

    /**
     * The key the notification fake records deliveries under.
     *
     * @return int The key.
     */
    public function getKey(): int
    {
        return 1;
    }

    /**
     * Where mail to this notifiable goes.
     *
     * @return string The address.
     */
    public function routeNotificationForMail(): string
    {
        return 'team@example.com';
    }
}

it( 'falls back to mail rather than delivering an alert to no channel at all', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );
    config()->set( 'pagespeed-insights.alerts.channels', [ '', '   ' ] );

    app( AlertDispatcher::class )->report( [ psiRegression() ] );

    Notification::assertSentOnDemand(
        ScoreRegressionNotification::class,
        fn ( ScoreRegressionNotification $notification, array $channels ): bool => [ 'mail' ] === $channels,
    );
} );

it( 'caps the buffer and says what it left out rather than truncating quietly', function (): void {
    Log::spy();

    $regressions = [];

    for ( $index = 0; $index < AlertDispatcher::MAX_BUFFERED + 2; $index++ ) {
        $regressions[] = psiRegression( 'https://example.com/page-' . $index );
    }

    app( AlertDispatcher::class )->report( $regressions );

    expect( Cache::get( AlertDispatcher::DIGEST_KEY ) )->toHaveCount( AlertDispatcher::MAX_BUFFERED );

    Log::shouldHaveReceived( 'warning' )
        ->once()
        ->withArgs( fn ( string $message, array $context ): bool => str_contains( $message, 'than the buffer holds' )
            && 2 === $context[ 'dropped' ] );
} );

it( 'opens a new window for a regression reported while a flush is under way', function (): void {
    Notification::fake();

    $dispatcher = app( AlertDispatcher::class );

    $dispatcher->report( [ psiRegression() ] );
    $dispatcher->flush();

    // The pending marker is released by the flush, so the next regression
    // queues its own flush instead of being stranded in the buffer.
    $dispatcher->report( [ psiRegression( 'https://example.com/late' ) ] );

    expect( Cache::get( AlertDispatcher::DIGEST_KEY ) )->toHaveCount( 1 );

    Queue::assertPushed( SendRegressionDigest::class, 2 );
} );
