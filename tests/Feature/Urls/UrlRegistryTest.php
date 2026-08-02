<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    removeAllFilters( UrlRegistry::FILTER_REGISTER_URLS );

    $this->registry = app( UrlRegistry::class );
} );

afterEach( function (): void {
    removeAllFilters( UrlRegistry::FILTER_REGISTER_URLS );
} );

it( 'returns the stored URLs when nothing registers through the hook', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/one' ] );
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/two' ] );

    expect( $this->registry->all()->pluck( 'url' )->all() )
        ->toBe( [ 'https://example.com/one', 'https://example.com/two' ] );
} );

it( 'merges hook-contributed URLs into the stored set', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/one' ] );

    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/from-a-package',
    ] );

    $all = $this->registry->all();

    expect( $all )->toHaveCount( 2 )
        ->and( $all->pluck( 'url' )->all() )
        ->toBe( [ 'https://example.com/one', 'https://example.com/from-a-package' ] );
} );

it( 'marks hook-contributed URLs with the hook source and leaves them unsaved', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/from-a-package',
    ] );

    $hooked = $this->registry->hooked()->first();

    expect( $hooked->source )->toBe( PageSpeedUrl::SOURCE_HOOK )
        ->and( $hooked->exists )->toBeFalse()
        ->and( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'lets the stored row win when both sources contribute the same URL', function (): void {
    $stored = PageSpeedUrl::factory()->create( [
        'url'   => 'https://example.com/about',
        'label' => 'Stored label',
    ] );

    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        [ 'url' => 'https://example.com/about', 'label' => 'Hook label' ],
    ] );

    $all = $this->registry->all();

    expect( $all )->toHaveCount( 1 )
        ->and( $all->first()->id )->toBe( $stored->id )
        ->and( $all->first()->label )->toBe( 'Stored label' );
} );

it( 'dedupes a hook URL against a stored one written differently', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'HTTPS://Example.com/about/#team',
    ] );

    expect( $this->registry->all() )->toHaveCount( 1 );
} );

it( 'dedupes hook URLs against each other', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/about',
        'https://example.com/about/',
    ] );

    expect( $this->registry->hooked() )->toHaveCount( 1 );
} );

it( 'accepts a URL string, an attribute array, and a model from the hook', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/one',
        [ 'url' => 'https://example.com/two', 'label' => 'Two', 'strategies' => [ 'mobile' ] ],
        new PageSpeedUrl( [ 'url' => 'https://example.com/three', 'label' => 'Three' ] ),
    ] );

    $hooked = $this->registry->hooked();

    expect( $hooked )->toHaveCount( 3 )
        ->and( $hooked[ 1 ]->label )->toBe( 'Two' )
        ->and( $hooked[ 1 ]->strategies )->toBe( [ 'mobile' ] )
        ->and( $hooked[ 2 ]->label )->toBe( 'Three' );
} );

it( 'drops hook entries that are not usable', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/good',
        'mailto:someone@example.com',
        '',
        [ 'label' => 'No URL key' ],
        42,
        null,
    ] );

    expect( $this->registry->hooked()->pluck( 'url' )->all() )
        ->toBe( [ 'https://example.com/good' ] );
} );

it( 'ignores a hook callback that returns something other than an array', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn (): string => 'not an array' );

    expect( $this->registry->hooked() )->toBeEmpty();
} );

it( 'refuses to let a hook entry set its own source', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        [ 'url' => 'https://example.com/one', 'source' => PageSpeedUrl::SOURCE_MANUAL ],
    ] );

    expect( $this->registry->hooked()->first()->source )->toBe( PageSpeedUrl::SOURCE_HOOK );
} );

it( 'filters the merged set down to active URLs', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/active' ] );
    PageSpeedUrl::factory()->inactive()->create( [ 'url' => 'https://example.com/paused' ] );

    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/from-a-package',
        [ 'url' => 'https://example.com/hook-paused', 'is_active' => false ],
    ] );

    expect( $this->registry->active()->pluck( 'url' )->all() )
        ->toBe( [ 'https://example.com/active', 'https://example.com/from-a-package' ] );
} );

it( 'finds a monitored URL however it is spelled', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    expect( $this->registry->find( 'HTTP://example.com/about' ) )->toBeNull()
        ->and( $this->registry->find( 'https://example.com/about/' )?->url )->toBe( 'https://example.com/about' )
        ->and( $this->registry->find( 'not a url' ) )->toBeNull();
} );

it( 'adds a URL in its normalized form', function (): void {
    $url = $this->registry->add( 'HTTPS://Example.com/About/#team', [ 'label' => 'About' ] );

    expect( $url->url )->toBe( 'https://example.com/About' )
        ->and( $url->label )->toBe( 'About' )
        ->and( $url->source )->toBe( PageSpeedUrl::SOURCE_MANUAL )
        ->and( $url->exists )->toBeTrue();
} );

it( 'refuses to add a URL that cannot be tested', function (): void {
    expect( $this->registry->add( 'mailto:someone@example.com' ) )->toBeNull()
        ->and( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'updates the existing row rather than duplicating it', function (): void {
    $first  = $this->registry->add( 'https://example.com/about', [ 'label' => 'First' ] );
    $second = $this->registry->add( 'https://example.com/about/', [ 'label' => 'Second' ] );

    expect( $second->id )->toBe( $first->id )
        ->and( $second->label )->toBe( 'Second' )
        ->and( PageSpeedUrl::query()->count() )->toBe( 1 );
} );

it( 'refuses to let a caller set the source through add attributes', function (): void {
    $url = $this->registry->add(
        'https://example.com/about',
        [ 'source' => PageSpeedUrl::SOURCE_HOOK ],
        PageSpeedUrl::SOURCE_SITEMAP,
    );

    expect( $url->source )->toBe( PageSpeedUrl::SOURCE_SITEMAP );
} );

it( 'falls back to the manual source when an unknown one is given', function (): void {
    expect( $this->registry->add( 'https://example.com/about', [], 'imported-from-mars' )->source )
        ->toBe( PageSpeedUrl::SOURCE_MANUAL );
} );

it( 'updates a stored URL', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    $this->registry->update( $url, [ 'label' => 'Renamed', 'test_frequency' => 'daily' ] );

    expect( $url->fresh()->label )->toBe( 'Renamed' )
        ->and( $url->fresh()->test_frequency )->toBe( 'daily' );
} );

it( 'normalizes a URL changed through update', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    $this->registry->update( $url, [ 'url' => 'HTTPS://Example.com/contact/' ] );

    expect( $url->fresh()->url )->toBe( 'https://example.com/contact' );
} );

it( 'leaves the address alone when an update supplies an untestable one', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    $this->registry->update( $url, [ 'url' => 'mailto:someone@example.com', 'label' => 'Kept' ] );

    expect( $url->fresh()->url )->toBe( 'https://example.com/about' )
        ->and( $url->fresh()->label )->toBe( 'Kept' );
} );

it( 'pauses and resumes a URL without touching its history', function (): void {
    $url = PageSpeedUrl::factory()->create();

    $this->registry->deactivate( $url );
    expect( $url->fresh()->is_active )->toBeFalse();

    $this->registry->activate( $url );
    expect( $url->fresh()->is_active )->toBeTrue();
} );

it( 'deletes a URL', function (): void {
    $url = PageSpeedUrl::factory()->create();

    expect( $this->registry->delete( $url ) )->toBeTrue()
        ->and( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'imports a batch, reporting what happened to each URL', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/existing' ] );

    $result = $this->registry->import(
        [
            'https://example.com/new',
            'https://example.com/existing/',
            'mailto:someone@example.com',
        ],
        PageSpeedUrl::SOURCE_SITEMAP,
    );

    expect( $result[ 'added' ] )->toHaveCount( 1 )
        ->and( $result[ 'added' ][ 0 ]->url )->toBe( 'https://example.com/new' )
        ->and( $result[ 'added' ][ 0 ]->source )->toBe( PageSpeedUrl::SOURCE_SITEMAP )
        ->and( $result[ 'added' ][ 0 ]->is_active )->toBeFalse()
        ->and( $result[ 'existing' ] )->toHaveCount( 1 )
        ->and( $result[ 'skipped' ] )->toBe( [ 'mailto:someone@example.com' ] );
} );

it( 'activates imported URLs on request', function (): void {
    $result = $this->registry->import( [ 'https://example.com/new' ], PageSpeedUrl::SOURCE_SITEMAP, true );

    expect( $result[ 'added' ][ 0 ]->is_active )->toBeTrue();
} );

it( 'leaves a paused URL paused when it is imported again', function (): void {
    $paused = PageSpeedUrl::factory()->inactive()->create( [ 'url' => 'https://example.com/paused' ] );

    $this->registry->import( [ 'https://example.com/paused' ], PageSpeedUrl::SOURCE_SITEMAP, true );

    expect( $paused->fresh()->is_active )->toBeFalse();
} );

it( 'persists hook URLs only when asked to', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/already-stored' ] );

    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        [ 'url' => 'https://example.com/from-a-package', 'label' => 'Package page' ],
        'https://example.com/already-stored',
    ] );

    $created = $this->registry->persistHookUrls();

    expect( $created )->toHaveCount( 1 )
        ->and( $created[ 0 ]->url )->toBe( 'https://example.com/from-a-package' )
        ->and( $created[ 0 ]->label )->toBe( 'Package page' )
        ->and( $created[ 0 ]->source )->toBe( PageSpeedUrl::SOURCE_HOOK )
        ->and( PageSpeedUrl::query()->count() )->toBe( 2 );
} );
