<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Jobs\Middleware\RateLimitPageSpeedRequests;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use Illuminate\Cache\RateLimiter;
use Tests\Support\RecordingQueueJob;

/**
 * Push a job through the middleware, reporting whether it got through.
 *
 * @param  RunPageSpeedTest  $job  The job to pass.
 *
 * @return array{passed: bool, queueJob: RecordingQueueJob} Whether the pipeline ran, and the queue double.
 */
function psiThrottle( RunPageSpeedTest $job ): array
{
    $queueJob = new RecordingQueueJob( $job );
    $job->setJob( $queueJob );

    $passed = false;

    app( RateLimitPageSpeedRequests::class )->handle( $job, function () use ( &$passed ): void {
        $passed = true;
    } );

    return [ 'passed' => $passed, 'queueJob' => $queueJob ];
}

it( 'lets jobs through while there is budget', function (): void {
    config( [ 'pagespeed-insights.rate_limit.per_minute' => 3 ] );

    foreach ( range( 1, 3 ) as $ignored ) {
        expect( psiThrottle( new RunPageSpeedTest( 'https://example.com/about' ) )[ 'passed' ] )->toBeTrue();
    }
} );

it( 'releases a job that would exceed the budget', function (): void {
    config( [ 'pagespeed-insights.rate_limit.per_minute' => 2 ] );

    psiThrottle( new RunPageSpeedTest( 'https://example.com/one' ) );
    psiThrottle( new RunPageSpeedTest( 'https://example.com/two' ) );

    $third = psiThrottle( new RunPageSpeedTest( 'https://example.com/three' ) );

    expect( $third[ 'passed' ] )->toBeFalse()
        ->and( $third[ 'queueJob' ]->isReleased() )->toBeTrue()
        ->and( $third[ 'queueJob' ]->releasedFor )->toBeGreaterThan( 0 )
        ->and( $third[ 'queueJob' ]->failed )->toBeFalse();
} );

it( 'counts the budget across every URL rather than per URL', function (): void {
    config( [ 'pagespeed-insights.rate_limit.per_minute' => 1 ] );

    psiThrottle( new RunPageSpeedTest( 'https://example.com/one' ) );

    expect( psiThrottle( new RunPageSpeedTest( 'https://example.com/two' ) )[ 'passed' ] )->toBeFalse();
} );

it( 'disables throttling when the budget is zero or less', function (): void {
    config( [ 'pagespeed-insights.rate_limit.per_minute' => 0 ] );

    foreach ( range( 1, 50 ) as $ignored ) {
        expect( psiThrottle( new RunPageSpeedTest( 'https://example.com/about' ) )[ 'passed' ] )->toBeTrue();
    }

    expect( app( RateLimiter::class )->attempts( RateLimitPageSpeedRequests::LIMITER_KEY ) )->toBe( 0 );
} );

it( 'falls back to the default budget when config is not a number', function (): void {
    config( [ 'pagespeed-insights.rate_limit.per_minute' => 'lots' ] );

    foreach ( range( 1, RateLimitPageSpeedRequests::DEFAULT_PER_MINUTE ) as $ignored ) {
        psiThrottle( new RunPageSpeedTest( 'https://example.com/about' ) );
    }

    expect( psiThrottle( new RunPageSpeedTest( 'https://example.com/about' ) )[ 'passed' ] )->toBeFalse();
} );

it( 'is attached to the job', function (): void {
    expect( ( new RunPageSpeedTest( 'https://example.com/about' ) )->middleware() )
        ->toHaveCount( 1 )
        ->and( ( new RunPageSpeedTest( 'https://example.com/about' ) )->middleware()[ 0 ] )
        ->toBeInstanceOf( RateLimitPageSpeedRequests::class );
} );
