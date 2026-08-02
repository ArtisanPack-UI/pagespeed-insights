<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedResponse;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\NullLogger;

uses( RefreshDatabase::class );

/**
 * Parse a checked-in fixture into a TestResult, the way the client does.
 */
function parsedFixture( string $name, string $url = 'https://example.com/', string $strategy = 'mobile' ): TestResult
{
    return ( new PageSpeedResponse(
        psiFixture( $name ),
        new PageSpeedRequest( $url, $strategy ),
        new NullLogger(),
    ) )->toTestResult();
}

it( 'maps a parsed run onto the result columns', function (): void {
    $parsed = parsedFixture( 'healthy' );

    $model = PageSpeedResult::fromTestResult( $parsed );

    expect( $model->url )->toBe( $parsed->url )
        ->and( $model->strategy )->toBe( $parsed->strategy )
        ->and( $model->final_url )->toBe( $parsed->finalUrl )
        ->and( $model->lighthouse_version )->toBe( $parsed->lighthouseVersion )
        ->and( $model->status )->toBe( PageSpeedResult::STATUS_COMPLETED )
        ->and( $model->error_message )->toBeNull()
        ->and( $model->performance_score )->toBe( $parsed->scores->performance() )
        ->and( $model->accessibility_score )->toBe( $parsed->scores->accessibility() )
        ->and( $model->best_practices_score )->toBe( $parsed->scores->bestPractices() )
        ->and( $model->seo_score )->toBe( $parsed->scores->seo() )
        // Loose comparison on the json columns: JSON has one number type, so
        // a 45.0 the parser produced reads back as the integer 45. The DTO
        // round trip below restores the float; the column itself cannot.
        ->and( $model->lab_metrics )->toEqual( $parsed->labMetrics->toArray() )
        ->and( $model->field_data )->toEqual( $parsed->fieldData?->toArray() )
        ->and( $model->warnings )->toBe( $parsed->warnings() );
} );

it( 'links a mapped result to its monitored URL', function (): void {
    $url = PageSpeedUrl::factory()->create();

    $model = PageSpeedResult::fromTestResult( parsedFixture( 'healthy', $url->url ), $url );
    $model->save();

    expect( $model->pagespeed_url_id )->toBe( $url->id )
        ->and( PageSpeedResult::fromTestResult( parsedFixture( 'healthy' ), $url->id )->pagespeed_url_id )
        ->toBe( $url->id );
} );

it( 'drops the raw response unless it is asked for', function (): void {
    expect( PageSpeedResult::fromTestResult( parsedFixture( 'healthy' ) )->raw_response )->toBeNull();

    config()->set( 'pagespeed-insights.store_raw_response', true );

    expect( PageSpeedResult::fromTestResult( parsedFixture( 'healthy' ) )->raw_response )->toBeArray();
} );

it( 'lets the caller override the raw response setting either way', function (): void {
    config()->set( 'pagespeed-insights.store_raw_response', true );

    expect( PageSpeedResult::fromTestResult( parsedFixture( 'healthy' ), null, false )->raw_response )->toBeNull();

    config()->set( 'pagespeed-insights.store_raw_response', false );

    expect( PageSpeedResult::fromTestResult( parsedFixture( 'healthy' ), null, true )->raw_response )->toBeArray();
} );

it( 'round-trips a healthy run through the database', function (): void {
    $parsed = parsedFixture( 'healthy' );

    PageSpeedResult::fromTestResult( $parsed, null, true )->save();

    $restored = PageSpeedResult::latestFor( $parsed->url )->firstOrFail()->toTestResult();

    expect( $restored->url )->toBe( $parsed->url )
        ->and( $restored->strategy )->toBe( $parsed->strategy )
        ->and( $restored->finalUrl )->toBe( $parsed->finalUrl )
        ->and( $restored->lighthouseVersion )->toBe( $parsed->lighthouseVersion )
        ->and( $restored->scores->toArray() )->toBe( $parsed->scores->toArray() )
        ->and( $restored->labMetrics->toArray() )->toBe( $parsed->labMetrics->toArray() )
        ->and( $restored->fieldData?->toArray() )->toBe( $parsed->fieldData?->toArray() )
        ->and( $restored->originFieldData?->toArray() )->toBe( $parsed->originFieldData?->toArray() )
        ->and( $restored->warnings() )->toBe( $parsed->warnings() )
        ->and( $restored->raw )->toBe( $parsed->raw )
        ->and( $restored->analyzedAt?->toIso8601String() )->toBe( $parsed->analyzedAt?->toIso8601String() )
        ->and( array_map(
            static fn ( $opportunity ): array => $opportunity->toArray(),
            $restored->opportunities,
        ) )->toBe( array_map(
            static fn ( $opportunity ): array => $opportunity->toArray(),
            $parsed->opportunities,
        ) );
} );

it( 'round-trips a run with no CrUX data', function (): void {
    $parsed = parsedFixture( 'no-field-data' );

    PageSpeedResult::fromTestResult( $parsed )->save();

    $restored = PageSpeedResult::latestFor( $parsed->url )->firstOrFail();

    expect( $restored->toTestResult()->hasFieldData() )->toBeFalse()
        ->and( $restored->toTestResult()->warnings() )->toBe( $parsed->warnings() )
        ->and( $restored->hasWarnings() )->toBeTrue()
        ->and( $restored->wasDegraded() )->toBeFalse();
} );

it( 'round-trips a poor-scoring run including its opportunities', function (): void {
    $parsed = parsedFixture( 'poor' );

    PageSpeedResult::fromTestResult( $parsed )->save();

    $restored = PageSpeedResult::latestFor( $parsed->url )->firstOrFail()->toTestResult();

    expect( $restored->opportunities )->not->toBeEmpty()
        ->and( array_map(
            static fn ( $opportunity ): array => $opportunity->toArray(),
            $restored->opportunities,
        ) )->toBe( array_map(
            static fn ( $opportunity ): array => $opportunity->toArray(),
            $parsed->opportunities,
        ) );
} );

it( 'stores a run carrying a category it has no column for, and says so', function (): void {
    Log::spy();

    $parsed = parsedFixture( 'emerging-categories' );

    expect( $parsed->unrecognizedCategories )->not->toBeEmpty();

    $model = PageSpeedResult::fromTestResult( $parsed );
    $model->save();

    // The write must not fail over a category Lighthouse added, but it must
    // leave a trace that one was dropped.
    expect( $model->exists )->toBeTrue()
        ->and( $model->warnings[ 'unrecognized_categories' ] )->toBe( $parsed->unrecognizedCategories )
        ->and( $model->wasDegraded() )->toBeTrue();

    Log::shouldHaveReceived( 'warning' )
        ->withArgs( fn ( string $message, array $context ): bool => str_contains( $message, 'no column for' )
            && in_array( $context[ 'category' ], $parsed->unrecognizedCategories, true ) );
} );

it( 'distinguishes an absent category from one that came back unscored', function (): void {
    $absent = PageSpeedResult::factory()->create( [
        'seo_score' => null,
        'warnings'  => [ 'missing_categories' => [ 'seo' ] ],
    ] )->toTestResult();

    $unscored = PageSpeedResult::factory()->create( [
        'seo_score' => null,
        'warnings'  => [ 'missing_categories' => [] ],
    ] )->toTestResult();

    expect( $absent->scores->has( 'seo' ) )->toBeFalse()
        ->and( $unscored->scores->has( 'seo' ) )->toBeTrue()
        ->and( $unscored->scores->seo() )->toBeNull();
} );
