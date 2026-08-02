<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;

it( 'sends the strategy explicitly, upper-cased', function ( string $given, string $expected ): void {
    $query = ( new PageSpeedRequest( 'https://example.com/', $given ) )->toQuery();

    expect( $query[ 'strategy' ] )->toBe( $expected );
} )->with( [
    'mobile'            => [ 'mobile', 'MOBILE' ],
    'desktop'           => [ 'desktop', 'DESKTOP' ],
    'mixed case mobile' => [ 'Mobile', 'MOBILE' ],
] );

it( 'defaults to mobile rather than letting the API default to desktop', function (): void {
    expect( ( new PageSpeedRequest( 'https://example.com/' ) )->strategy )->toBe( 'mobile' );
} );

it( 'repeats the category parameter once per requested category', function (): void {
    $query = ( new PageSpeedRequest( 'https://example.com/' ) )->toQuery();

    expect( $query[ 'category' ] )->toBe( [ 'PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO' ] );
} );

it( 'translates hyphenated category keys to the request enum', function (): void {
    $query = ( new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'best-practices' ] ) )->toQuery();

    expect( $query[ 'category' ] )->toBe( [ 'BEST_PRACTICES' ] );
} );

it( 'accepts categories given in the request enum spelling', function (): void {
    $request = new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'BEST_PRACTICES', 'SEO' ] );

    expect( $request->categories )->toBe( [ 'best-practices', 'seo' ] )
        ->and( $request->toQuery()[ 'category' ] )->toBe( [ 'BEST_PRACTICES', 'SEO' ] );
} );

it( 'deduplicates categories given in both spellings', function (): void {
    $request = new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'best-practices', 'BEST_PRACTICES' ] );

    expect( $request->categories )->toBe( [ 'best-practices' ] );
} );

it( 'falls back to the four defaults rather than asking for nothing', function (): void {
    $request = new PageSpeedRequest( 'https://example.com/', 'mobile', [] );

    expect( $request->categories )->toBe( [ 'performance', 'accessibility', 'best-practices', 'seo' ] );
} );

it( 'omits the locale when none was given', function (): void {
    expect( ( new PageSpeedRequest( 'https://example.com/' ) )->toQuery() )->not->toHaveKey( 'locale' );
} );

it( 'sends the locale when one was given', function (): void {
    $request = new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'performance' ], 'fr-FR' );

    expect( $request->toQuery()[ 'locale' ] )->toBe( 'fr-FR' );
} );

it( 'treats a blank locale as none', function (): void {
    $request = new PageSpeedRequest( 'https://example.com/', 'mobile', [ 'performance' ], '   ' );

    expect( $request->locale )->toBeNull();
} );

it( 'rejects a URL PageSpeed could never fetch', function ( string $url ): void {
    new PageSpeedRequest( $url );
} )->with( [
    'empty'         => [ '' ],
    'relative path' => [ '/about' ],
    'no scheme'     => [ 'example.com' ],
    'wrong scheme'  => [ 'ftp://example.com/' ],
] )->throws( InvalidArgumentException::class );

it( 'rejects a strategy PageSpeed does not have', function (): void {
    new PageSpeedRequest( 'https://example.com/', 'tablet' );
} )->throws( InvalidArgumentException::class );

it( 'builds log context naming the url and strategy', function (): void {
    expect( ( new PageSpeedRequest( 'https://example.com/', 'desktop' ) )->logContext() )
        ->toBe( [ 'url' => 'https://example.com/', 'strategy' => 'desktop' ] );
} );
