<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable;
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config()->set( 'pagespeed-insights.driver', 'config' );
    config()->set( 'pagespeed-insights.api_key', 'test-key' );
} );

it( 'renders the empty state when nothing has been stored', function (): void {
    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_EMPTY )
        ->assertSet( 'rows', [] )
        ->assertSee( 'No PageSpeed test has run for this URL yet.' );
} );

it( 'renders the failure message rather than an empty table', function (): void {
    PageSpeedResult::factory()->failed( 'PageSpeed returned HTTP 500 for this URL.' )->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_FAILED )
        ->assertSee( 'The last PageSpeed run failed' )
        ->assertSee( 'PageSpeed returned HTTP 500 for this URL.' )
        ->assertDontSee( 'No opportunities found' );
} );

it( 'falls back to a written reason when a failed run recorded no message', function (): void {
    PageSpeedResult::factory()->failed()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'error_message' => null,
    ] );

    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSee( 'The run failed and did not record a reason.' );
} );

it( 'celebrates a clean run that did measure performance', function (): void {
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [],
    ] );

    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_NONE )
        ->assertSee( 'No opportunities found' )
        ->assertDontSee( 'Performance was not measured' );
} );

it( 'separates a run that never measured performance from one that found nothing', function (): void {
    // Nothing found and nothing looked for are opposite readings of the same
    // empty list. Reporting the first as the second tells an operator their
    // page is clean on the strength of a test that never examined it.
    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/page',
        'strategy'          => 'mobile',
        'opportunities'     => [],
        'performance_score' => null,
        'warnings'          => [ 'missing_categories' => [ 'performance' ] ],
    ] );

    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_NOT_MEASURED )
        ->assertSee( 'Performance was not measured' )
        ->assertDontSee( 'No opportunities found' );
} );

it( 'lists the stored opportunities with their estimated savings', function (): void {
    PageSpeedResult::factory()->poor()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_LOADED )
        ->assertSee( 'Eliminate render-blocking resources' )
        ->assertSee( 'Reduce unused JavaScript' )
        ->assertSee( 'Potential savings of 412 KiB' );

    $rows = $component->get( 'rows' );

    expect( $rows )->toHaveCount( 2 );
    expect( $rows[ 0 ][ 'id' ] )->toBe( 'render-blocking-resources' );
    expect( $rows[ 0 ][ 'savingsMs' ] )->toBe( 2100.0 );
    expect( $rows[ 0 ][ 'savings' ] )->toBe( '2.1 s' );
    expect( $rows[ 0 ][ 'score' ] )->toBe( 12 );
    expect( $rows[ 0 ][ 'band' ] )->toBe( 'poor' );
} );

it( 'orders the rows by estimated saving whatever order they were stored in', function (): void {
    // Rows written by an older version, or reshaped by a listener on the
    // opportunities filter, can arrive in any order. A table headed
    // "estimated saving" whose largest number is halfway down reads as broken.
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'small', 'title' => 'Small win', 'savings_ms' => 120.0 ],
            [ 'id' => 'large', 'title' => 'Large win', 'savings_ms' => 3400.0 ],
            [ 'id' => 'middle', 'title' => 'Middling win', 'savings_ms' => 800.0 ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->get( 'rows' );

    expect( array_column( $rows, 'id' ) )->toBe( [ 'large', 'middle', 'small' ] );
} );

it( 'weighs an insight audit by its per-metric estimate when it carries no overall one', function (): void {
    // Lighthouse 13's *-insight audits carry a real per-metric estimate with
    // no overallSavingsMs, so ranking on the overall figure alone would sort a
    // genuine opportunity to the bottom at zero.
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'overall-only', 'title' => 'Overall only', 'savings_ms' => 500.0 ],
            [
                'id'             => 'insight',
                'title'          => 'Insight audit',
                'savings_ms'     => null,
                'metric_savings' => [ 'LCP' => 1800.0 ],
            ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->get( 'rows' );

    expect( array_column( $rows, 'id' ) )->toBe( [ 'insight', 'overall-only' ] );
    expect( $rows[ 0 ][ 'savings' ] )->toBe( '1.8 s' );
} );

it( 'keeps a sub-second saving in milliseconds', function (): void {
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'quick', 'title' => 'Quick win', 'savings_ms' => 400.0 ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'savings' ] )->toBe( '400 ms' );
} );

it( 'renders no estimate as absent rather than as a saving of zero', function (): void {
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'unquantified', 'title' => 'Unquantified win', 'savings_ms' => null ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_LOADED )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'savings' ] )->toBeNull();
    expect( $rows[ 0 ][ 'savingsMs' ] )->toBeNull();
} );

it( 'renders an unscored audit as unscored rather than as a zero', function (): void {
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'unscored', 'title' => 'Unscored audit', 'score' => null, 'savings_ms' => 900.0 ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSee( 'Unscored' )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'score' ] )->toBeNull();
    expect( $rows[ 0 ][ 'band' ] )->toBeNull();
} );

it( 'falls back to the audit id when an opportunity carries no title', function (): void {
    PageSpeedResult::factory()->create( [
        'url'           => 'https://example.com/page',
        'strategy'      => 'mobile',
        'opportunities' => [
            [ 'id' => 'render-blocking-resources', 'title' => '', 'savings_ms' => 900.0 ],
        ],
    ] );

    $rows = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'title' ] )->toBe( 'render-blocking-resources' );
} );

it( 'reads the latest run for the requested form factor only', function (): void {
    PageSpeedResult::factory()->poor()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    PageSpeedResult::factory()->desktop()->create( [
        'url'           => 'https://example.com/page',
        'opportunities' => [],
    ] );

    Livewire::test( OpportunitiesTable::class, [
        'url'      => 'https://example.com/page',
        'strategy' => 'desktop',
    ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_NONE )
        ->assertSet( 'strategy', 'desktop' );
} );

it( 'falls back to mobile for an unrecognised form factor', function (): void {
    Livewire::test( OpportunitiesTable::class, [
        'url'      => 'https://example.com/page',
        'strategy' => 'watch',
    ] )->assertSet( 'strategy', 'mobile' );
} );

it( 'picks up a run the score card announced for its own URL', function (): void {
    $component = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'state', OpportunitiesTable::STATE_EMPTY );

    PageSpeedResult::factory()->poor()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component->dispatch( ScoreCard::EVENT_RESULT_STORED, url: 'https://example.com/page', strategy: 'mobile' )
        ->assertSet( 'state', OpportunitiesTable::STATE_LOADED )
        ->assertSee( 'Eliminate render-blocking resources' );
} );

it( 'ignores a run announced for another URL or form factor', function ( string $url, string $strategy ): void {
    $component = Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] );

    PageSpeedResult::factory()->poor()->create( [
        'url'      => 'https://example.com/page',
        'strategy' => 'mobile',
    ] );

    $component->dispatch( ScoreCard::EVENT_RESULT_STORED, url: $url, strategy: $strategy )
        ->assertSet( 'state', OpportunitiesTable::STATE_EMPTY );
} )->with( [
    'another URL'         => [ 'https://example.com/other', 'mobile' ],
    'another form factor' => [ 'https://example.com/page', 'desktop' ],
] );

it( 'renders an install notice instead of exploding when the component library is absent', function (): void {
    UiComponentsInstalled::setForTesting( false );

    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->assertSet( 'uiComponentsInstalled', false )
        ->assertSee( 'composer require artisanpack-ui/livewire-ui-components' );
} );

it( 'refuses a URL the browser asks it to read', function (): void {
    // The URL decides whose history is shown, so it is locked for the same
    // reason it is on the score card.
    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'url', 'https://attacker.example/pwn' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a form factor the browser asks it to change', function (): void {
    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'strategy', 'desktop' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses rows the browser asks it to display', function (): void {
    // The rows are derived from a stored run. A client authoring its own has
    // nothing to gain and a table full of invented findings to give.
    Livewire::test( OpportunitiesTable::class, [ 'url' => 'https://example.com/page' ] )
        ->set( 'rows', [ [ 'id' => 'invented', 'title' => 'Invented finding' ] ] );
} )->throws( CannotUpdateLockedPropertyException::class );
