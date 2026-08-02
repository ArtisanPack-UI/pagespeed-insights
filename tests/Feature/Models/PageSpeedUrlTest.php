<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'creates a monitored URL with sensible defaults', function (): void {
    $url = PageSpeedUrl::create( [ 'url' => 'https://example.com/pricing' ] );

    expect( $url->source )->toBe( PageSpeedUrl::SOURCE_MANUAL )
        ->and( $url->is_active )->toBeTrue()
        ->and( $url->strategies )->toBe( [ 'mobile', 'desktop' ] )
        ->and( $url->test_frequency )->toBeNull()
        ->and( $url->last_tested_at )->toBeNull();
} );

it( 'casts its columns', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'strategies'     => [ 'mobile' ],
        'last_tested_at' => '2026-08-01 09:30:00',
    ] );

    $url->refresh();

    expect( $url->strategies )->toBe( [ 'mobile' ] )
        ->and( $url->is_active )->toBeTrue()
        ->and( $url->last_tested_at )->toBeInstanceOf( CarbonImmutable::class );
} );

it( 'rejects a duplicate URL', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/one' ] );

    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/one' ] );
} )->throws( Illuminate\Database\QueryException::class );

it( 'stores a URL longer than the default string length', function (): void {
    $url = 'https://example.com/' . str_repeat( 'a', 400 );

    PageSpeedUrl::factory()->create( [ 'url' => $url ] );

    expect( PageSpeedUrl::forUrl( $url )->exists() )->toBeTrue();
} );

it( 'scopes to active URLs only', function (): void {
    PageSpeedUrl::factory()->count( 2 )->create();
    PageSpeedUrl::factory()->inactive()->create();

    expect( PageSpeedUrl::active()->count() )->toBe( 2 );
} );

it( 'scopes to URLs that have never been tested', function (): void {
    $fresh = PageSpeedUrl::factory()->neverTested()->create();
    PageSpeedUrl::factory()->notDue()->create();

    expect( PageSpeedUrl::due()->pluck( 'id' )->all() )->toBe( [ $fresh->id ] );
} );

it( 'never brings an inactive URL due', function (): void {
    PageSpeedUrl::factory()->inactive()->neverTested()->create();

    expect( PageSpeedUrl::due()->count() )->toBe( 0 );
} );

it( 'respects the per-URL cadence override', function ( string $frequency ): void {
    $due = PageSpeedUrl::factory()->due( $frequency )->create();
    PageSpeedUrl::factory()->notDue( $frequency )->create();

    expect( PageSpeedUrl::due()->pluck( 'id' )->all() )->toBe( [ $due->id ] );
} )->with( [ 'hourly', 'daily', 'weekly', 'monthly' ] );

it( 'falls back to the configured default for a URL with no override', function (): void {
    config()->set( 'pagespeed-insights.test_frequency', 'daily' );

    $due = PageSpeedUrl::factory()->create( [
        'test_frequency' => null,
        'last_tested_at' => CarbonImmutable::now()->subDays( 2 ),
    ] );

    PageSpeedUrl::factory()->create( [
        'test_frequency' => null,
        'last_tested_at' => CarbonImmutable::now()->subHours( 2 ),
    ] );

    expect( PageSpeedUrl::due()->pluck( 'id' )->all() )->toBe( [ $due->id ] )
        ->and( $due->frequency() )->toBe( 'daily' );
} );

it( 'treats an unrecognized cadence as the default rather than never testing it', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'test_frequency' => 'fortnightly',
        'last_tested_at' => CarbonImmutable::now()->subMonths( 2 ),
    ] );

    expect( $url->frequency() )->toBe( PageSpeedUrl::DEFAULT_FREQUENCY )
        ->and( PageSpeedUrl::due()->pluck( 'id' )->all() )->toBe( [ $url->id ] );
} );

it( 'ignores an unsupported configured default', function (): void {
    config()->set( 'pagespeed-insights.test_frequency', 'fortnightly' );

    expect( PageSpeedUrl::defaultFrequency() )->toBe( PageSpeedUrl::DEFAULT_FREQUENCY );
} );

it( 'measures due-ness from a supplied moment', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'test_frequency' => 'daily',
        'last_tested_at' => CarbonImmutable::parse( '2026-08-01 00:00:00' ),
    ] );

    expect( PageSpeedUrl::due( CarbonImmutable::parse( '2026-08-01 12:00:00' ) )->count() )->toBe( 0 )
        ->and( PageSpeedUrl::due( CarbonImmutable::parse( '2026-08-02 12:00:00' ) )->count() )->toBe( 1 )
        ->and( $url->isDue( CarbonImmutable::parse( '2026-08-01 12:00:00' ) ) )->toBeFalse()
        ->and( $url->isDue( CarbonImmutable::parse( '2026-08-02 12:00:00' ) ) )->toBeTrue();
} );

it( 'agrees between the due scope and the single-row check', function (): void {
    PageSpeedUrl::factory()->neverTested()->create();
    PageSpeedUrl::factory()->due( 'hourly' )->create();
    PageSpeedUrl::factory()->notDue( 'weekly' )->create();
    PageSpeedUrl::factory()->inactive()->neverTested()->create();
    PageSpeedUrl::factory()->create( [ 'test_frequency' => 'fortnightly', 'last_tested_at' => CarbonImmutable::now()->subYear() ] );

    $scoped  = PageSpeedUrl::due()->pluck( 'id' )->sort()->values()->all();
    $checked = PageSpeedUrl::all()->filter->isDue()->pluck( 'id' )->sort()->values()->all();

    expect( $scoped )->toBe( $checked )
        ->and( $scoped )->toHaveCount( 3 );
} );

it( 'falls back to both form factors when the strategies column is unusable', function ( mixed $stored ): void {
    $url = PageSpeedUrl::factory()->create( [ 'strategies' => $stored ] );

    expect( $url->effectiveStrategies() )->toBe( [ 'mobile', 'desktop' ] );
} )->with( [
    'empty'   => [ [] ],
    'null'    => [ null ],
    'garbage' => [ [ 'tablet', 'watch' ] ],
] );

it( 'keeps only the valid entries of the strategies column', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'strategies' => [ 'desktop', 'tablet' ] ] );

    expect( $url->effectiveStrategies() )->toBe( [ 'desktop' ] );
} );

it( 'has many results', function (): void {
    $url = PageSpeedUrl::factory()->create();
    PageSpeedResult::factory()->count( 3 )->create( [
        'pagespeed_url_id' => $url->id,
        'url'              => $url->url,
    ] );

    expect( $url->results )->toHaveCount( 3 );
} );

it( 'keeps result history when the monitored URL is deleted', function (): void {
    $url    = PageSpeedUrl::factory()->create();
    $result = PageSpeedResult::factory()->create( [
        'pagespeed_url_id' => $url->id,
        'url'              => $url->url,
    ] );

    $url->delete();

    expect( $result->fresh()->pagespeed_url_id )->toBeNull()
        ->and( PageSpeedResult::forUrl( $result->url )->count() )->toBe( 1 );
} );
