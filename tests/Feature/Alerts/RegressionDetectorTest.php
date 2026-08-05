<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Alerts\Regression;
use ArtisanPackUI\PageSpeedInsights\Alerts\RegressionDetector;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Notifications\ScoreRegressionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.alerts.enabled', true );
    config()->set( 'pagespeed-insights.alerts.drop_points', 10 );
    config()->set( 'pagespeed-insights.alerts.thresholds', [] );
    config()->set( 'pagespeed-insights.alerts.skip_degraded', true );
    config()->set( 'pagespeed-insights.alerts.digest.enabled', false );
    config()->set( 'pagespeed-insights.alerts.mail_to', [ 'ops@example.com' ] );
} );

if ( ! function_exists( 'psiStoreRun' ) ) {
    /**
     * Store one run for the shared test URL, with every score pinned.
     *
     * The factory randomises all four scores, which is exactly the wrong
     * default here: two runs of it differ by enough to trip drop detection
     * roughly one time in ten, in a category the test never mentioned.
     *
     * @param  array<string, mixed>  $attributes  Attribute overrides.
     * @param  string|null  $state  An optional factory state: failed or degraded.
     *
     * @return PageSpeedResult The stored run.
     */
    function psiStoreRun( array $attributes = [], ?string $state = null ): PageSpeedResult
    {
        $factory = PageSpeedResult::factory();

        $factory = match ( $state ) {
            'failed'   => $factory->failed(),
            'degraded' => $factory->degraded(),
            default    => $factory,
        };

        $pinned = [
            'url'                  => 'https://example.com/pricing',
            'strategy'             => 'mobile',
            'performance_score'    => 95,
            'accessibility_score'  => 95,
            'best_practices_score' => 95,
            'seo_score'            => 95,
        ];

        // Explicit attributes beat a factory state, so the scores a state
        // deliberately nulls must not be pinned over the top of it.
        $nulled = match ( $state ) {
            'failed'   => [ 'performance_score', 'accessibility_score', 'best_practices_score', 'seo_score' ],
            'degraded' => [ 'best_practices_score', 'seo_score' ],
            default    => [],
        };

        return $factory->create( array_merge(
            array_diff_key( $pinned, array_flip( $nulled ) ),
            $attributes,
        ) );
    }
}

it( 'reports a category that fell by at least the configured points', function (): void {
    psiStoreRun( [ 'performance_score' => 92 ] );
    $later = psiStoreRun( [ 'performance_score' => 61 ] );

    $regressions = app( RegressionDetector::class )->inspect( $later );

    expect( $regressions )->toHaveCount( 1 )
        ->and( $regressions[ 0 ]->type )->toBe( Regression::TYPE_DROP )
        ->and( $regressions[ 0 ]->category )->toBe( 'performance' )
        ->and( $regressions[ 0 ]->previousScore )->toBe( 92 )
        ->and( $regressions[ 0 ]->currentScore )->toBe( 61 )
        ->and( $regressions[ 0 ]->pointsLost() )->toBe( 31 )
        ->and( $regressions[ 0 ]->describe() )->toContain( 'fell 31 points' );
} );

it( 'ignores a fall smaller than the configured points', function (): void {
    psiStoreRun( [ 'performance_score' => 92 ] );
    $later = psiStoreRun( [ 'performance_score' => 87 ] );

    expect( app( RegressionDetector::class )->inspect( $later ) )->toBe( [] );
} );

it( 'reports a category below its configured floor', function (): void {
    config()->set( 'pagespeed-insights.alerts.thresholds', [ 'seo' => 90 ] );

    psiStoreRun( [ 'seo_score' => 88 ] );
    $later = psiStoreRun( [ 'seo_score' => 85 ] );

    $regressions = app( RegressionDetector::class )->inspect( $later );

    expect( $regressions )->toHaveCount( 1 )
        ->and( $regressions[ 0 ]->type )->toBe( Regression::TYPE_THRESHOLD )
        ->and( $regressions[ 0 ]->category )->toBe( 'seo' )
        ->and( $regressions[ 0 ]->threshold )->toBe( 90 )
        ->and( $regressions[ 0 ]->describe() )->toContain( 'below the floor of 90' );
} );

it( 'applies a floor on a first run that has nothing to be compared with', function (): void {
    config()->set( 'pagespeed-insights.alerts.thresholds', [ 'performance' => 50 ] );

    $result = psiStoreRun( [ 'performance_score' => 30 ] );

    $regressions = app( RegressionDetector::class )->inspect( $result );

    expect( $regressions )->toHaveCount( 1 )
        ->and( $regressions[ 0 ]->type )->toBe( Regression::TYPE_THRESHOLD )
        ->and( $regressions[ 0 ]->previousScore )->toBeNull();
} );

it( 'ignores a floor that is not a usable score', function (): void {
    config()->set( 'pagespeed-insights.alerts.thresholds', [ 'performance' => 250, 'seo' => 'high' ] );

    $result = psiStoreRun( [ 'performance_score' => 4, 'seo_score' => 4 ] );

    expect( app( RegressionDetector::class )->inspect( $result ) )->toBe( [] );
} );

it( 'does nothing comparative when there is no earlier run, and says so at debug level', function (): void {
    Log::spy();

    $result = psiStoreRun( [ 'performance_score' => 12 ] );

    expect( app( RegressionDetector::class )->inspect( $result ) )->toBe( [] );

    Log::shouldHaveReceived( 'debug' )
        ->once()
        ->withArgs( fn ( string $message ): bool => str_contains( $message, 'no earlier completed run' ) );
} );

it( 'never compares against a failed run', function (): void {
    psiStoreRun( [ 'performance_score' => 95 ] );
    psiStoreRun( [], 'failed' );

    $newest = psiStoreRun( [ 'performance_score' => 94 ] );

    expect( app( RegressionDetector::class )->inspect( $newest ) )->toBe( [] );
} );

it( 'reports a category that stopped being measured as its own kind of regression', function (): void {
    psiStoreRun( [ 'performance_score' => 91 ] );
    $later = psiStoreRun( [ 'performance_score' => null ] );

    $regressions = app( RegressionDetector::class )->inspect( $later );

    expect( $regressions )->toHaveCount( 1 )
        ->and( $regressions[ 0 ]->type )->toBe( Regression::TYPE_STOPPED )
        ->and( $regressions[ 0 ]->currentScore )->toBeNull()
        ->and( $regressions[ 0 ]->describe() )
        ->toContain( 'no longer being measured' )
        ->not->toContain( 'fell' );
} );

it( 'says nothing about a category neither run scored', function (): void {
    psiStoreRun( [ 'seo_score' => null ] );
    $later = psiStoreRun( [ 'seo_score' => null ] );

    expect( app( RegressionDetector::class )->inspect( $later ) )->toBe( [] );
} );

it( 'skips a degraded run when looking for something to compare against', function (): void {
    psiStoreRun( [ 'performance_score' => 90 ] );
    psiStoreRun( [ 'performance_score' => 40 ], 'degraded' );

    $later = psiStoreRun( [ 'performance_score' => 75 ] );

    $regressions = app( RegressionDetector::class )->inspect( $later );

    // Compared against the clean 90 rather than the degraded 40, so this
    // reads as a 15-point fall and not a 35-point recovery.
    expect( $regressions )->toHaveCount( 1 )
        ->and( $regressions[ 0 ]->previousScore )->toBe( 90 );
} );

it( 'annotates a regression detected on a degraded run', function (): void {
    psiStoreRun( [ 'performance_score' => 95 ] );

    $later = psiStoreRun( [ 'performance_score' => 60 ], 'degraded' );

    $regressions = app( RegressionDetector::class )->inspect( $later );

    expect( $regressions[ 0 ]->category )->toBe( 'performance' )
        ->and( $regressions[ 0 ]->degraded )->toBeTrue()
        ->and( $regressions[ 0 ]->describe() )->toContain( 'data missing' );
} );

it( 'reports the categories a degraded run stopped scoring', function (): void {
    psiStoreRun();

    $later = psiStoreRun( [], 'degraded' );

    $categories = array_map(
        static fn ( Regression $regression ): string => $regression->category,
        app( RegressionDetector::class )->inspect( $later ),
    );

    expect( $categories )->toBe( [ 'best-practices', 'seo' ] );
} );

it( 'compares against a degraded run when the skip is switched off', function (): void {
    config()->set( 'pagespeed-insights.alerts.skip_degraded', false );

    psiStoreRun( [ 'performance_score' => 90 ] );
    psiStoreRun( [ 'performance_score' => 40 ], 'degraded' );

    $later = psiStoreRun( [ 'performance_score' => 75 ] );

    expect( app( RegressionDetector::class )->inspect( $later ) )->toBe( [] );
} );

it( 'keeps the two form factors apart', function (): void {
    psiStoreRun( [ 'strategy' => 'desktop', 'performance_score' => 99 ] );

    $mobile = psiStoreRun( [ 'performance_score' => 55 ] );

    expect( app( RegressionDetector::class )->inspect( $mobile ) )->toBe( [] );
} );

it( 'says nothing about a failed run', function (): void {
    psiStoreRun( [ 'performance_score' => 95 ] );

    $failed = psiStoreRun( [], 'failed' );

    expect( app( RegressionDetector::class )->inspect( $failed ) )->toBe( [] );
} );

it( 'carries the monitored label so the notification can name the page', function (): void {
    $monitored = PageSpeedUrl::factory()->create( [
        'url'   => 'https://example.com/pricing',
        'label' => 'Pricing',
    ] );

    psiStoreRun( [ 'pagespeed_url_id' => $monitored->getKey(), 'performance_score' => 95 ] );

    $later = psiStoreRun( [ 'pagespeed_url_id' => $monitored->getKey(), 'performance_score' => 40 ] );

    expect( app( RegressionDetector::class )->inspect( $later )[ 0 ]->label )->toBe( 'Pricing' );
} );

it( 'detects nothing at all when the master switch is off', function (): void {
    Notification::fake();

    config()->set( 'pagespeed-insights.alerts.enabled', false );

    psiStoreRun( [ 'performance_score' => 95 ] );
    $later = psiStoreRun( [ 'performance_score' => 10 ] );

    expect( app( RegressionDetector::class )->handle( $later ) )->toBe( [] );

    Notification::assertNothingSent();
} );

it( 'turns drop detection off when the margin is zero, leaving the floors', function (): void {
    config()->set( 'pagespeed-insights.alerts.drop_points', 0 );

    psiStoreRun( [ 'performance_score' => 95 ] );
    $later = psiStoreRun( [ 'performance_score' => 10 ] );

    expect( app( RegressionDetector::class )->inspect( $later ) )->toBe( [] );

    config()->set( 'pagespeed-insights.alerts.thresholds', [ 'performance' => 50 ] );

    expect( app( RegressionDetector::class )->inspect( $later ) )->toHaveCount( 1 );
} );

it( 'sends the alert when a regression is handled', function (): void {
    Notification::fake();

    psiStoreRun( [ 'performance_score' => 95 ] );
    $later = psiStoreRun( [ 'performance_score' => 40 ] );

    app( RegressionDetector::class )->handle( $later );

    Notification::assertSentOnDemand(
        ScoreRegressionNotification::class,
        fn ( ScoreRegressionNotification $notification, array $channels, object $notifiable ): bool => [ 'ops@example.com' ] === $notifiable->routes[ 'mail' ]
            && 1 === count( $notification->regressions ),
    );
} );

it( 'fires the score regression action with the result and the details', function (): void {
    Notification::fake();

    $seen = [];

    addAction(
        RegressionDetector::ACTION_SCORE_REGRESSED,
        function ( PageSpeedResult $result, array $regressions ) use ( &$seen ): void {
            $seen[] = [ 'result' => $result, 'regressions' => $regressions ];
        },
    );

    psiStoreRun( [ 'performance_score' => 95 ] );
    $later = psiStoreRun( [ 'performance_score' => 40 ] );

    app( RegressionDetector::class )->handle( $later );

    removeAllActions( RegressionDetector::ACTION_SCORE_REGRESSED );

    expect( $seen )->toHaveCount( 1 )
        ->and( $seen[ 0 ][ 'result' ]->getKey() )->toBe( $later->getKey() )
        ->and( $seen[ 0 ][ 'regressions' ][ 0 ] )->toMatchArray( [
            'type'           => Regression::TYPE_DROP,
            'category'       => 'performance',
            'previous_score' => 95,
            'current_score'  => 40,
            'points_lost'    => 55,
            'strategy'       => 'mobile',
            'url'            => 'https://example.com/pricing',
        ] );
} );

it( 'does not fire the action when nothing regressed', function (): void {
    Notification::fake();

    $fired = false;

    addAction( RegressionDetector::ACTION_SCORE_REGRESSED, function () use ( &$fired ): void {
        $fired = true;
    } );

    psiStoreRun( [ 'performance_score' => 95 ] );
    $later = psiStoreRun( [ 'performance_score' => 94 ] );

    app( RegressionDetector::class )->handle( $later );

    removeAllActions( RegressionDetector::ACTION_SCORE_REGRESSED );

    expect( $fired )->toBeFalse();
} );
