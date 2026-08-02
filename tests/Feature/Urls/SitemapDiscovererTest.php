<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Exceptions\SitemapException;
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;
use Illuminate\Support\Facades\Http;

beforeEach( function (): void {
    $this->discoverer = app( SitemapDiscoverer::class );
} );

it( 'reads the URLs out of a sitemap', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/',
            'https://example.com/about',
            'https://example.com/pricing',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )->toBe( [
        'https://example.com/',
        'https://example.com/about',
        'https://example.com/pricing',
    ] );
} );

it( 'normalizes and dedupes the URLs it finds', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about',
            'https://example.com/about/',
            'HTTPS://Example.com/about#team',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'skips locations that cannot be tested', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about',
            'mailto:someone@example.com',
            '',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'reads a sitemap written with a namespace prefix', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<sm:url><sm:loc>https://example.com/about</sm:loc></sm:url>'
            . '</sm:urlset>',
        ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'follows a sitemap index', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/pages.xml',
            'https://example.com/posts.xml',
        ] ) ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
        'https://example.com/posts.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/blog/one' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about', 'https://example.com/blog/one' ] );
} );

it( 'follows a nested sitemap index', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml'  => Http::response( psiSitemapIndex( [ 'https://example.com/sections.xml' ] ) ),
        'https://example.com/sections.xml' => Http::response( psiSitemapIndex( [ 'https://example.com/pages.xml' ] ) ),
        'https://example.com/pages.xml'    => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'stops rather than looping when a sitemap index points at itself', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/sitemap.xml',
            'https://example.com/pages.xml',
        ] ) ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'skips a nested sitemap that cannot be fetched', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/broken.xml',
            'https://example.com/pages.xml',
        ] ) ),
        'https://example.com/broken.xml'  => Http::response( 'Not found', 404 ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'keeps the entries it could read from a sitemap truncated mid-document', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/about</loc></url>'
            . '<url><lo',
        ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'keeps the entries it could read from a sitemap with unescaped markup', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/search?q=a&b=c</loc></url>'
            . '<url><loc>https://example.com/about</loc>'
            . '</urlset>',
        ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toContain( 'https://example.com/about' );
} );

it( 'refuses to follow a sitemap index onto another host', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'http://169.254.169.254/latest/meta-data/',
            'http://127.0.0.1:6379/sitemap.xml',
            'https://evil.test/sitemap.xml',
            'https://example.com/pages.xml',
        ] ) ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );

    Http::assertNotSent( static fn ( $request ): bool => ! str_starts_with( $request->url(), 'https://example.com/' ) );
} );

it( 'does not let a nested sitemap redirect off the host the index named', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/pages.xml',
        ] ) ),
        'https://example.com/pages.xml'   => Http::response( '', 302, [ 'Location' => 'http://169.254.169.254/' ] ),
        'http://169.254.169.254/*'        => Http::response( psiSitemapUrlset( [ 'https://example.com/leaked' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )->toBe( [] );

    Http::assertNotSent( static fn ( $request ): bool => str_contains( $request->url(), '169.254.169.254' ) );
} );

it( 'follows a redirect on the sitemap the caller named', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml'     => Http::response( '', 301, [ 'Location' => 'https://www.example.com/sitemap.xml' ] ),
        'https://www.example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [ 'https://www.example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://www.example.com/about' ] );
} );

it( 'still lists page URLs on other hosts, which are fetched by Google and not by us', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about',
            'https://cdn.example.net/asset-page',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about', 'https://cdn.example.net/asset-page' ] );
} );

it( 'does not fetch anything a sitemap names through an external entity', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<!DOCTYPE urlset [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<url><loc>https://example.com/about</loc></url>'
            . '<url><loc>&xxe;</loc></url>'
            . '</urlset>',
        ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'throws when the named sitemap is empty', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( '' ),
    ] );

    $this->discoverer->discover( 'https://example.com/sitemap.xml' );
} )->throws( SitemapException::class );

it( 'throws when the named sitemap is an HTML error page served with a 200', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response(
            '<html><body><h1>404 Not Found</h1></body></html>',
        ),
    ] );

    $this->discoverer->discover( 'https://example.com/sitemap.xml' );
} )->throws( SitemapException::class, 'rooted at <html>' );

it( 'throws when the named sitemap is not XML at all', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( 'Not found' ),
    ] );

    $this->discoverer->discover( 'https://example.com/sitemap.xml' );
} )->throws( SitemapException::class );

it( 'skips a nested sitemap that answers with an HTML error page', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/broken.xml',
            'https://example.com/pages.xml',
        ] ) ),
        'https://example.com/broken.xml'  => Http::response( '<html><body>Oops</body></html>' ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/about' ] );
} );

it( 'throws when the named sitemap cannot be fetched', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( 'Not found', 404 ),
    ] );

    $this->discoverer->discover( 'https://example.com/sitemap.xml' );
} )->throws( SitemapException::class );

it( 'throws when the sitemap address is not an http URL', function (): void {
    $this->discoverer->discover( 'mailto:someone@example.com' );
} )->throws( SitemapException::class );

it( 'returns nothing for an empty but valid sitemap', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )->toBe( [] );
} );

it( 'enforces the limit it is given', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/one',
            'https://example.com/two',
            'https://example.com/three',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml', 2 ) )->toBe( [
        'https://example.com/one',
        'https://example.com/two',
    ] );
} );

it( 'enforces the limit across a sitemap index', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapIndex( [
            'https://example.com/pages.xml',
            'https://example.com/posts.xml',
        ] ) ),
        'https://example.com/pages.xml'   => Http::response( psiSitemapUrlset( [
            'https://example.com/one',
            'https://example.com/two',
        ] ) ),
        'https://example.com/posts.xml'   => Http::response( psiSitemapUrlset( [ 'https://example.com/three' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml', 2 ) )->toBe( [
        'https://example.com/one',
        'https://example.com/two',
    ] );
} );

it( 'falls back to the configured limit', function (): void {
    config()->set( 'pagespeed-insights.sitemap.limit', 1 );

    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/one',
            'https://example.com/two',
        ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/one' ] );
} );

it( 'falls back to the default limit when the configured one is nonsense', function (): void {
    config()->set( 'pagespeed-insights.sitemap.limit', 'lots' );

    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [ 'https://example.com/one' ] ) ),
    ] );

    expect( $this->discoverer->discover( 'https://example.com/sitemap.xml' ) )
        ->toBe( [ 'https://example.com/one' ] );
} );

it( 'defaults to sitemap.xml at the app URL', function (): void {
    config()->set( 'app.url', 'https://example.com/' );
    config()->set( 'pagespeed-insights.sitemap.url', null );

    expect( $this->discoverer->defaultSitemapUrl() )->toBe( 'https://example.com/sitemap.xml' );
} );

it( 'prefers the configured sitemap URL', function (): void {
    config()->set( 'app.url', 'https://example.com' );
    config()->set( 'pagespeed-insights.sitemap.url', 'https://example.com/sitemap_index.xml' );

    expect( $this->discoverer->defaultSitemapUrl() )->toBe( 'https://example.com/sitemap_index.xml' );
} );

it( 'reads the default sitemap when none is named', function (): void {
    config()->set( 'pagespeed-insights.sitemap.url', 'https://example.com/sitemap.xml' );

    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [ 'https://example.com/about' ] ) ),
    ] );

    expect( $this->discoverer->discover() )->toBe( [ 'https://example.com/about' ] );
} );
