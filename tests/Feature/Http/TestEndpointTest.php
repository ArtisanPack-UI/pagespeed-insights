<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\Controller;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\QueueTestController;
use ArtisanPackUI\PageSpeedInsights\Http\Controllers\ResultController;
use ArtisanPackUI\PageSpeedInsights\Http\Support\TestTicketStore;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HttpUser;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'app.url', 'https://example.com' );
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );

    Queue::fake();

    $this->actingAs( new HttpUser() );
} );

/*
|--------------------------------------------------------------------------
| Queueing
|--------------------------------------------------------------------------
*/

it( 'queues a run for a monitored URL', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://elsewhere.example/page' ] );

    $this->postJson( '/pagespeed/test', [ 'url' => 'https://elsewhere.example/page' ] )
        ->assertAccepted()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_QUEUED )
        ->assertJsonPath( 'url', 'https://elsewhere.example/page' )
        ->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_MOBILE )
        ->assertJsonStructure( [ 'id' ] );

    Queue::assertPushed(
        RunPageSpeedTest::class,
        static fn ( RunPageSpeedTest $job ): bool => 'https://elsewhere.example/page' === $job->url
            && PageSpeedRequest::STRATEGY_MOBILE === $job->strategy,
    );
} );

it( 'queues a run for an unmonitored page of this application\'s own site', function (): void {
    $this->postJson( '/pagespeed/test', [
        'url'      => 'https://example.com/never-added',
        'strategy' => 'desktop',
    ] )
        ->assertAccepted()
        ->assertJsonPath( 'strategy', PageSpeedRequest::STRATEGY_DESKTOP );

    Queue::assertPushed( RunPageSpeedTest::class );
} );

it( 'refuses to spend quota on a third-party site', function (): void {
    $this->postJson( '/pagespeed/test', [ 'url' => 'https://competitor.example/pricing' ] )
        ->assertForbidden()
        ->assertJsonPath( 'error', Controller::ERROR_URL_NOT_ALLOWED );

    Queue::assertNothingPushed();
} );

it( 'tests a third-party site when allow_external_urls is on', function (): void {
    config()->set( 'pagespeed-insights.routes.allow_external_urls', true );

    $this->postJson( '/pagespeed/test', [ 'url' => 'https://competitor.example/pricing' ] )
        ->assertAccepted();

    Queue::assertPushed( RunPageSpeedTest::class );
} );

it( 'refuses a URL PageSpeed cannot test', function (): void {
    $this->postJson( '/pagespeed/test', [ 'url' => 'mailto:someone@example.com' ] )
        ->assertStatus( 422 )
        ->assertJsonPath( 'error', Controller::ERROR_INVALID_URL );

    Queue::assertNothingPushed();
} );

it( 'refuses to queue a run that could not succeed without an API key', function (): void {
    config()->set( 'pagespeed-insights.api_key', null );

    $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )
        ->assertStatus( 409 )
        ->assertJsonPath( 'error', QueueTestController::ERROR_NO_API_KEY );

    Queue::assertNothingPushed();
} );

/*
|--------------------------------------------------------------------------
| Polling
|--------------------------------------------------------------------------
*/

it( 'reports a queued run as queued until a row lands', function (): void {
    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )
        ->assertAccepted()
        ->json( 'id' );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_QUEUED )
        ->assertJsonPath( 'result', null );
} );

it( 'resolves the ticket to the row the run produced', function (): void {
    // History that predates the run, to prove the ticket waits for something
    // newer rather than resolving to whatever is already there.
    PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/page',
        'created_at' => CarbonImmutable::now()->subDay(),
    ] );

    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )
        ->assertAccepted()
        ->json( 'id' );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_QUEUED );

    $landed = PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'performance_score' => 88,
    ] );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_COMPLETED )
        ->assertJsonPath( 'id', $landed->getKey() )
        ->assertJsonPath( 'result.scores.0.score', 88 );
} );

it( 'answers a repeated poll of a resolved ticket the same way', function (): void {
    // Polling is not a one-shot read. A retry, a component that mounts twice,
    // or a second tab must not be told 404 for a run it has already been told
    // completed.
    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )->json( 'id' );

    $landed = PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/page' ] );

    foreach ( range( 1, 3 ) as $ignored ) {
        $this->getJson( '/pagespeed/results/' . $id )
            ->assertOk()
            ->assertJsonPath( 'status', TestTicketStore::STATUS_COMPLETED )
            ->assertJsonPath( 'id', $landed->getKey() );
    }
} );

it( 'stays pinned to the run it was opened for when a later one lands', function (): void {
    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )->json( 'id' );

    $landed = PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/page' ] );
    PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/page' ] );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'id', $landed->getKey() );
} );

it( 'resolves the ticket to a failed row as failed', function (): void {
    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )
        ->json( 'id' );

    PageSpeedResult::factory()->failed( 'No PageSpeed API key is configured.' )->create( [
        'url' => 'https://example.com/page',
    ] );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_FAILED )
        ->assertJsonPath( 'result.errorMessage', 'No PageSpeed API key is configured.' );
} );

it( 'ignores a row for a different form factor', function (): void {
    $id = $this->postJson( '/pagespeed/test', [
        'url'      => 'https://example.com/page',
        'strategy' => 'desktop',
    ] )->json( 'id' );

    PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/page' ] );

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_QUEUED );
} );

it( 'stops waiting on a run that never reported back', function (): void {
    $id = $this->postJson( '/pagespeed/test', [ 'url' => 'https://example.com/page' ] )->json( 'id' );

    $this->travel( TestTicketStore::TIMEOUT_SECONDS + 1 )->seconds();

    $this->getJson( '/pagespeed/results/' . $id )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_TIMED_OUT )
        ->assertJsonPath( 'result', null );
} );

it( 'serves a stored run by its own id', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://elsewhere.example/page' ] );

    $result = PageSpeedResult::factory()->poor()->create( [ 'url' => 'https://elsewhere.example/page' ] );

    $this->getJson( '/pagespeed/results/' . $result->getKey() )
        ->assertOk()
        ->assertJsonPath( 'status', TestTicketStore::STATUS_COMPLETED )
        ->assertJsonPath( 'id', $result->getKey() )
        ->assertJsonPath( 'result.opportunities.0.id', 'render-blocking-resources' )
        ->assertJsonPath( 'result.fieldData.percentile', 75 );
} );

it( 'refuses a stored run for a URL this caller may not ask about', function (): void {
    $result = PageSpeedResult::factory()->create( [ 'url' => 'https://competitor.example/pricing' ] );

    $this->getJson( '/pagespeed/results/' . $result->getKey() )
        ->assertNotFound()
        ->assertJsonPath( 'error', ResultController::ERROR_NOT_FOUND );
} );

it( 'answers 404 for an id that names nothing', function ( string $id ): void {
    $this->getJson( '/pagespeed/results/' . $id )
        ->assertNotFound()
        ->assertJsonPath( 'error', ResultController::ERROR_NOT_FOUND );
} )->with( [
    'no such row'    => '9999',
    'no such ticket' => 'b2a4c0de-0000-4000-8000-000000000000',
] );
