<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

it( 'keeps a score column for every category the package claims to score', function (): void {
    // If a category is added to CategoryTranslator::KNOWN without a matching
    // column here, its score silently disappears into unrecognized_categories
    // — exactly the quiet data loss the warnings column exists to prevent.
    expect( array_keys( PageSpeedResult::SCORE_COLUMNS ) )
        ->toBe( CategoryTranslator::KNOWN );
} );

it( 'stores a redirect target longer than a bounded string column', function (): void {
    $finalUrl = 'https://example.com/' . str_repeat( 'b', 900 );

    $result = PageSpeedResult::factory()->create( [ 'final_url' => $finalUrl ] );

    expect( $result->fresh()->final_url )->toBe( $finalUrl );
} );

it( 'casts its json and timestamp columns', function (): void {
    $result = PageSpeedResult::factory()->create()->fresh();

    expect( $result->lab_metrics )->toBeArray()
        ->and( $result->field_data )->toBeArray()
        ->and( $result->warnings )->toBeArray()
        ->and( $result->opportunities )->toBeArray()
        ->and( $result->fetched_at )->toBeInstanceOf( CarbonImmutable::class )
        ->and( $result->performance_score )->toBeInt();
} );

it( 'defaults to the completed status', function (): void {
    $result = PageSpeedResult::create( [
        'url'      => 'https://example.com/',
        'strategy' => 'mobile',
    ] );

    expect( $result->status )->toBe( PageSpeedResult::STATUS_COMPLETED )
        ->and( $result->isCompleted() )->toBeTrue()
        ->and( $result->isFailed() )->toBeFalse();
} );

it( 'stores an ad hoc run with no monitored URL', function (): void {
    $result = PageSpeedResult::factory()->create();

    expect( $result->pagespeed_url_id )->toBeNull()
        ->and( $result->pageSpeedUrl )->toBeNull();
} );

it( 'belongs to a monitored URL when there is one', function (): void {
    $url    = PageSpeedUrl::factory()->create();
    $result = PageSpeedResult::factory()->create( [
        'pagespeed_url_id' => $url->id,
        'url'              => $url->url,
    ] );

    expect( $result->pageSpeedUrl->id )->toBe( $url->id );
} );

it( 'scopes results to one URL', function (): void {
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => 'https://example.com/a' ] );
    PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/b' ] );

    expect( PageSpeedResult::forUrl( 'https://example.com/a' )->count() )->toBe( 2 );
} );

it( 'accepts a monitored URL model in the forUrl scope', function (): void {
    $url = PageSpeedUrl::factory()->create();
    PageSpeedResult::factory()->count( 2 )->create( [ 'url' => $url->url ] );
    PageSpeedResult::factory()->create();

    expect( PageSpeedResult::forUrl( $url )->count() )->toBe( 2 );
} );

it( 'narrows the forUrl scope to one form factor', function (): void {
    PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/a' ] );
    PageSpeedResult::factory()->desktop()->create( [ 'url' => 'https://example.com/a' ] );

    expect( PageSpeedResult::forUrl( 'https://example.com/a', 'desktop' )->count() )->toBe( 1 )
        ->and( PageSpeedResult::forUrl( 'https://example.com/a', 'DESKTOP' )->count() )->toBe( 1 );
} );

it( 'orders latestFor newest first', function (): void {
    $older = PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/a',
        'created_at' => CarbonImmutable::parse( '2026-07-01 00:00:00' ),
    ] );
    $newer = PageSpeedResult::factory()->create( [
        'url'        => 'https://example.com/a',
        'created_at' => CarbonImmutable::parse( '2026-08-01 00:00:00' ),
    ] );

    expect( PageSpeedResult::latestFor( 'https://example.com/a' )->pluck( 'id' )->all() )
        ->toBe( [ $newer->id, $older->id ] );
} );

it( 'breaks a latestFor tie on the id so the order is stable', function (): void {
    $timestamp = CarbonImmutable::parse( '2026-08-01 00:00:00' );

    $first  = PageSpeedResult::factory()->create( [ 'url' => 'https://example.com/a', 'created_at' => $timestamp ] );
    $second = PageSpeedResult::factory()->desktop()->create( [ 'url' => 'https://example.com/a', 'created_at' => $timestamp ] );

    expect( PageSpeedResult::latestFor( 'https://example.com/a' )->pluck( 'id' )->all() )
        ->toBe( [ $second->id, $first->id ] );
} );

it( 'separates completed runs from failed ones', function (): void {
    PageSpeedResult::factory()->count( 2 )->create();
    PageSpeedResult::factory()->failed()->create();

    expect( PageSpeedResult::completed()->count() )->toBe( 2 )
        ->and( PageSpeedResult::failed()->count() )->toBe( 1 );
} );

it( 'leaves every measurement null on a failed run', function (): void {
    $result = PageSpeedResult::factory()->failed( 'Quota exceeded.' )->create();

    expect( $result->isFailed() )->toBeTrue()
        ->and( $result->error_message )->toBe( 'Quota exceeded.' )
        ->and( $result->performance_score )->toBeNull()
        ->and( $result->lab_metrics )->toBeNull()
        ->and( $result->wasDegraded() )->toBeFalse();
} );

it( 'builds a failed row from the static helper', function (): void {
    $url = PageSpeedUrl::factory()->create();

    $result = PageSpeedResult::fromFailure( $url->url, 'mobile', 'Timed out.', $url );
    $result->save();

    expect( $result->status )->toBe( PageSpeedResult::STATUS_FAILED )
        ->and( $result->pagespeed_url_id )->toBe( $url->id )
        ->and( $result->error_message )->toBe( 'Timed out.' )
        ->and( $result->fetched_at )->not->toBeNull();
} );

it( 'reports no warnings on a clean run', function (): void {
    $result = PageSpeedResult::factory()->create();

    expect( $result->hasWarnings() )->toBeFalse()
        ->and( $result->wasDegraded() )->toBeFalse()
        ->and( $result->warningList() )->toBe( [] );
} );

it( 'reports absent CrUX data as a warning but not as degradation', function (): void {
    $result = PageSpeedResult::factory()->withoutFieldData()->create();

    expect( $result->hasWarnings() )->toBeTrue()
        ->and( $result->wasDegraded() )->toBeFalse()
        ->and( $result->warningList() )->toHaveCount( 1 );
} );

it( 'reports a completed run that lost data as degraded', function (): void {
    $result = PageSpeedResult::factory()->degraded()->create();

    expect( $result->isCompleted() )->toBeTrue()
        ->and( $result->hasWarnings() )->toBeTrue()
        ->and( $result->wasDegraded() )->toBeTrue()
        ->and( $result->best_practices_score )->toBeNull();

    expect( $result->warningList() )
        ->toHaveCount( 5 )
        ->each->toBeString();
} );

it( 'survives a warnings column holding the wrong shape', function (): void {
    $result = PageSpeedResult::factory()->create( [
        'warnings' => [
            'run_warnings'       => 'not a list',
            'missing_categories' => [ '', 42, 'seo' ],
        ],
    ] );

    expect( $result->warningList() )->toBe( [
        'PageSpeed did not return the "seo" category, so its score is null for this run.',
    ] )->and( $result->wasDegraded() )->toBeTrue();
} );

it( 'produces poor scores and real opportunities in the poor state', function (): void {
    $result = PageSpeedResult::factory()->poor()->create();

    expect( $result->performance_score )->toBeLessThan( 50 )
        ->and( $result->opportunities )->toHaveCount( 2 )
        ->and( $result->toTestResult()->opportunities[ 0 ]->weight() )->toBeGreaterThan( 0.0 );
} );
