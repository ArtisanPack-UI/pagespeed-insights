<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
} );

it( 'renders the no-API-key state when no key is configured and nothing has been stored', function (): void {
    config()->set( 'pagespeed-insights.api_key', null );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_NO_API_KEY )
        ->assertSet( 'apiKeyConfigured', false )
        ->assertSee( 'No PageSpeed API key is configured' )
        ->assertSee( 'PAGESPEED_API_KEY' )
        ->assertDontSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'renders the empty state when a key is configured but nothing has been stored', function (): void {
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_EMPTY )
        ->assertSet( 'apiKeyConfigured', true )
        ->assertSet( 'resultId', null )
        ->assertSee( 'No PageSpeed test has run for this URL yet.' )
        ->assertSee( 'Run test' )
        ->assertDontSee( 'No PageSpeed API key is configured' );
} );

it( 'keeps showing stored history when the API key is later removed', function (): void {
    config()->set( 'pagespeed-insights.api_key', null );

    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'performance_score' => 94,
    ] );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_LOADED )
        ->assertSet( 'apiKeyConfigured', false )
        ->assertSee( '94' );
} );

it( 'renders the loaded state with the four gauges from the latest run', function (): void {
    PageSpeedResult::factory()->create( [
        'url'                  => 'https://example.com/page',
        'strategy'             => 'mobile',
        'performance_score'    => 94,
        'accessibility_score'  => 72,
        'best_practices_score' => 41,
        'seo_score'            => 100,
    ] );

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_LOADED )
        ->assertSee( 'Performance' )
        ->assertSee( 'Accessibility' )
        ->assertSee( 'Best practices' )
        ->assertSee( 'SEO' );

    $gauges = collect( $component->get( 'gauges' ) )->keyBy( 'category' );

    expect( $gauges )->toHaveCount( 4 );

    expect( $gauges[ 'performance' ][ 'band' ] )->toBe( 'good' );
    expect( $gauges[ 'performance' ][ 'color' ] )->toBe( 'success' );
    expect( $gauges[ 'accessibility' ][ 'band' ] )->toBe( 'needs-improvement' );
    expect( $gauges[ 'accessibility' ][ 'color' ] )->toBe( 'warning' );
    expect( $gauges[ 'best-practices' ][ 'band' ] )->toBe( 'poor' );
    expect( $gauges[ 'best-practices' ][ 'color' ] )->toBe( 'error' );
    expect( $gauges[ 'seo' ][ 'band' ] )->toBe( 'good' );
} );

it( 'renders the error state with the stored failure message rather than an empty card', function (): void {
    PageSpeedResult::factory()->failed( 'PageSpeed returned HTTP 500 for this URL.' )->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_FAILED )
        ->assertSet( 'errorMessage', 'PageSpeed returned HTTP 500 for this URL.' )
        ->assertSee( 'The last PageSpeed run failed' )
        ->assertSee( 'PageSpeed returned HTTP 500 for this URL.' )
        ->assertDontSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'falls back to a written reason when a failed run recorded no message', function (): void {
    PageSpeedResult::factory()->failed()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'error_message' => null,
    ] );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_FAILED )
        ->assertSee( 'The run failed and did not record a reason.' );
} );

it( 'renders the degraded state with its warnings alongside the scores it did get', function (): void {
    PageSpeedResult::factory()->degraded()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', ScoreCard::STATE_DEGRADED )
        ->assertSee( 'The last run completed with data missing' );

    expect( $component->get( 'warnings' ) )->not->toBeEmpty();
} );

it( 'renders a null category score as unavailable rather than as a zero gauge', function (): void {
    PageSpeedResult::factory()->degraded()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSee( 'Unavailable' )
        ->assertSee( 'This run did not return a score for this category.' );

    $gauges = collect( $component->get( 'gauges' ) )->keyBy( 'category' );

    expect( $gauges[ 'best-practices' ][ 'available' ] )->toBeFalse();
    expect( $gauges[ 'best-practices' ][ 'score' ] )->toBeNull();
    expect( $gauges[ 'best-practices' ][ 'band' ] )->toBeNull();

    // The distinction that matters: a real zero still bands as poor and
    // still renders a number.
    expect( $gauges[ 'performance' ][ 'available' ] )->toBeTrue();
} );

it( 'renders a zero score as a poor gauge, not as an unavailable one', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'performance_score' => 0,
    ] );

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] );

    $gauges = collect( $component->get( 'gauges' ) )->keyBy( 'category' );

    expect( $gauges[ 'performance' ][ 'available' ] )->toBeTrue();
    expect( $gauges[ 'performance' ][ 'score' ] )->toBe( 0 );
    expect( $gauges[ 'performance' ][ 'band' ] )->toBe( 'poor' );
} );

it( 'reads the latest run for the requested form factor only', function (): void {
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'performance_score' => 40,
    ] );

    PageSpeedResult::factory()->desktop()->create( [
        'url'               => 'https://example.com/page',
        'performance_score' => 95,
    ] );

    $mobile = Livewire::test( ScoreCard::class, [
        'url'      => 'https://example.com/page',
        'strategy' => 'desktop',
    ] );

    $gauges = collect( $mobile->get( 'gauges' ) )->keyBy( 'category' );

    expect( $gauges[ 'performance' ][ 'score' ] )->toBe( 95 );
    expect( $mobile->get( 'strategy' ) )->toBe( 'desktop' );
} );

it( 'falls back to mobile for an unrecognised form factor', function (): void {
    Livewire::test( ScoreCard::class, [
        'url'      => 'https://example.com/page',
        'strategy' => 'watch',
    ] )->assertSet( 'strategy', 'mobile' );
} );

it( 'queues a run and starts polling when the run-test button is used', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'running', false )
        ->call( 'runTest' )
        ->assertSet( 'running', true )
        ->assertSet( 'actionMessage', null )
        ->assertSee( 'Test running' );

    Queue::assertPushed(
        RunPageSpeedTest::class,
        fn ( RunPageSpeedTest $job ): bool => 'https://example.com/page' === $job->url
            && 'mobile' === $job->strategy,
    );

    expect( $component->get( 'running' ) )->toBeTrue();
} );

it( 'stops polling once a newer result lands', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', true );

    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'performance_score' => 88,
    ] );

    $component->call( 'refresh' )
        ->assertSet( 'running', false )
        ->assertSet( 'state', ScoreCard::STATE_LOADED )
        ->assertSee( '88' );
} );

it( 'keeps polling while the queued run has not produced a row yet', function (): void {
    Queue::fake();

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->call( 'refresh' )
        ->assertSet( 'running', true )
        ->assertSet( 'actionMessage', null );
} );

it( 'stops polling and names the queue when a run never reports back', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', true );

    // A spinner with no end renders a dead worker as work in progress, so
    // the wait is bounded rather than open.
    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS + 1 )->seconds();

    $component->call( 'refresh' )
        ->assertSet( 'running', false )
        ->assertSet( 'runQueuedAt', null )
        ->assertSee( 'has not reported back yet' )
        ->assertSee( 'queue worker' );
} );

it( 'names the configured queue in the give-up message when the package uses one', function (): void {
    Queue::fake();
    config()->set( 'pagespeed-insights.queue.queue', 'pagespeed' );

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' );

    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS + 1 )->seconds();

    $component->call( 'refresh' )
        ->assertSee( 'pagespeed' );
} );

it( 'does not give up on a run that is still inside the wait window', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' );

    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS - 10 )->seconds();

    $component->call( 'refresh' )
        ->assertSet( 'running', true )
        ->assertSet( 'actionMessage', null );
} );

it( 'announces a run it was waiting on so the vitals card can catch up', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' );

    PageSpeedResult::factory()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component->call( 'refresh' )
        ->assertDispatched(
            ScoreCard::EVENT_RESULT_STORED,
            url: 'https://example.com/page',
            strategy: 'mobile',
        );
} );

it( 'does not announce the row it simply found on mount', function (): void {
    PageSpeedResult::factory()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    // Every mount sees a row it has not seen before. Announcing those would
    // have each card on a page refresh every other one on first paint.
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertNotDispatched( ScoreCard::EVENT_RESULT_STORED );
} );

it( 'does not announce anything when a run times out without producing a row', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' );

    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS + 1 )->seconds();

    $component->call( 'refresh' )
        ->assertNotDispatched( ScoreCard::EVENT_RESULT_STORED );
} );

it( 'clears the queued-at stamp once a result lands, so the next run starts its own clock', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertNotSet( 'runQueuedAt', null );

    PageSpeedResult::factory()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component->call( 'refresh' )
        ->assertSet( 'running', false )
        ->assertSet( 'runQueuedAt', null );
} );

it( 'does not time out a card that was never waiting on a run', function (): void {
    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS + 1 )->seconds();

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'refresh' )
        ->assertSet( 'running', false )
        ->assertSet( 'actionMessage', null );
} );

it( 'refuses to queue a run when no API key is configured', function (): void {
    Queue::fake();
    config()->set( 'pagespeed-insights.api_key', null );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', false );

    Queue::assertNothingPushed();
} );

it( 'refuses to queue a run when the card has no URL', function (): void {
    Queue::fake();

    Livewire::test( ScoreCard::class, [ 'url' => '' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', false )
        ->assertSet( 'actionMessage', 'This card has no URL to test.' );

    Queue::assertNothingPushed();
} );

it( 'reports a queue that would not take the job instead of claiming the run started', function (): void {
    Queue::fake();
    Queue::shouldReceive( 'connection' )->andThrow( new RuntimeException( 'No queue connection.' ) );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', false )
        ->assertSee( 'The test could not be queued' );
} );

it( 'reports the API key as missing rather than throwing when the driver cannot be read', function (): void {
    config()->set( 'pagespeed-insights.driver', 'database' );

    // The configurations table is dropped, so the driver throws on read.
    Schema::drop( 'pagespeed_configurations' );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'apiKeyConfigured', false )
        ->assertSet( 'state', ScoreCard::STATE_NO_API_KEY );
} );

it( 'renders an install notice instead of exploding when the component library is absent', function (): void {
    UiComponentsInstalled::setForTesting( false );

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'uiComponentsInstalled', false )
        ->assertSee( 'composer require artisanpack-ui/livewire-ui-components' );
} );

it( 'refuses a URL the browser asks it to test that it was not mounted with', function (): void {
    Queue::fake();

    // The URL decides what gets tested and what history is read, so it is
    // locked. Without this a user could retarget the card from the payload
    // and spend the application's API quota on an address of their choosing.
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'url', 'https://attacker.example/pwn' )
        ->call( 'runTest' );

    Queue::assertNothingPushed();
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a form factor the browser asks it to change', function (): void {
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'strategy', 'desktop' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a queued-at stamp the browser asks it to change', function (): void {
    // Tampering with the stamp would let a client hold the card waiting
    // indefinitely, or trip the give-up branch on a healthy run.
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'runQueuedAt', 1 );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses to queue a second run while one is already in flight', function (): void {
    Queue::fake();

    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSet( 'running', true )
        ->call( 'runTest' )
        ->assertSet( 'running', true );

    Queue::assertPushed( RunPageSpeedTest::class, 1 );
} );

it( 'refuses to queue a run for an address PageSpeed cannot test', function ( string $url ): void {
    Queue::fake();

    Livewire::test( ScoreCard::class, [ 'url' => $url ] )
        ->call( 'runTest' )
        ->assertSet( 'running', false )
        ->assertSee( 'is not an address PageSpeed can test' );

    Queue::assertNothingPushed();
} )->with( [
    // A scheme PageSpeed cannot fetch reaches the job as a guaranteed
    // failure, and the ones that are not merely useless are worth refusing
    // on their own account.
    'a local file'      => [ 'file:///etc/passwd' ],
    'inline script'     => [ 'javascript:alert(1)' ],
    'an inline payload' => [ 'data:text/html,<script>alert(1)</script>' ],
    'a non-web scheme'  => [ 'ftp://example.com/x' ],
] );

it( 'clears a stale give-up warning when a new run is started', function (): void {
    Queue::fake();

    $component = Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' );

    $this->travel( ScoreCard::RUN_TIMEOUT_SECONDS + 1 )->seconds();

    $component->call( 'refresh' )
        ->assertSee( 'has not reported back' )
        ->call( 'runTest' )
        ->assertSet( 'running', true )
        ->assertSet( 'actionMessage', null )
        ->assertDontSee( 'has not reported back' );
} );

it( 'redacts credentials out of a queue failure before rendering it', function (): void {
    Queue::fake();
    Queue::shouldReceive( 'connection' )->andThrow(
        new RuntimeException( 'Connection failed: https://sqs.example.com/q?key=SUPERSECRET&x=1' ),
    );

    // The message comes from the queue driver, not from this package, so it
    // can carry a connection string with a credential in it.
    Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/page' ] )
        ->call( 'runTest' )
        ->assertSee( 'The test could not be queued' )
        ->assertSee( '[redacted]' )
        ->assertDontSee( 'SUPERSECRET' );
} );
