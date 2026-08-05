<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;

it( 'lowercases the scheme and host but not the path', function (): void {
    expect( UrlNormalizer::normalize( 'HTTPS://Example.COM/About/Team' ) )
        ->toBe( 'https://example.com/About/Team' );
} );

it( 'assumes https for a bare host', function (): void {
    expect( UrlNormalizer::normalize( 'example.com/pricing' ) )
        ->toBe( 'https://example.com/pricing' );
} );

it( 'assumes https for a protocol-relative URL', function (): void {
    expect( UrlNormalizer::normalize( '//example.com/pricing' ) )
        ->toBe( 'https://example.com/pricing' );
} );

it( 'writes the site root as a single slash', function (): void {
    expect( UrlNormalizer::normalize( 'https://example.com' ) )->toBe( 'https://example.com/' )
        ->and( UrlNormalizer::normalize( 'https://example.com/' ) )->toBe( 'https://example.com/' );
} );

it( 'drops a trailing slash from a non-root path', function (): void {
    expect( UrlNormalizer::normalize( 'https://example.com/about/' ) )
        ->toBe( UrlNormalizer::normalize( 'https://example.com/about' ) );
} );

it( 'drops the fragment', function (): void {
    expect( UrlNormalizer::normalize( 'https://example.com/about#team' ) )
        ->toBe( 'https://example.com/about' );
} );

it( 'keeps the query string', function (): void {
    expect( UrlNormalizer::normalize( 'https://example.com/blog?page=2' ) )
        ->toBe( 'https://example.com/blog?page=2' );
} );

it( 'drops a default port but keeps a custom one', function (): void {
    expect( UrlNormalizer::normalize( 'https://example.com:443/about' ) )->toBe( 'https://example.com/about' )
        ->and( UrlNormalizer::normalize( 'http://example.com:80/about' ) )->toBe( 'http://example.com/about' )
        ->and( UrlNormalizer::normalize( 'https://example.com:8443/about' ) )->toBe( 'https://example.com:8443/about' );
} );

it( 'does not treat www as the same host', function (): void {
    expect( UrlNormalizer::normalize( 'https://www.example.com/about' ) )
        ->not->toBe( UrlNormalizer::normalize( 'https://example.com/about' ) );
} );

it( 'drops embedded credentials rather than storing a password in plaintext', function (): void {
    expect( UrlNormalizer::normalize( 'https://user:secret@example.com/staging' ) )
        ->toBe( 'https://example.com/staging' )
        ->and( UrlNormalizer::normalize( 'https://user@example.com/staging' ) )
        ->toBe( 'https://example.com/staging' );
} );

it( 'reads a bare host with a port as a host and not as a scheme', function (): void {
    expect( UrlNormalizer::normalize( 'example.com:8080/about' ) )
        ->toBe( 'https://example.com:8080/about' )
        ->and( UrlNormalizer::normalize( 'localhost:3000' ) )
        ->toBe( 'https://localhost:3000/' );
} );

it( 'rejects a URL that cannot be tested', function ( string $url ): void {
    expect( UrlNormalizer::normalize( $url ) )->toBeNull()
        ->and( UrlNormalizer::isValid( $url ) )->toBeFalse();
} )->with( [
    'blank'        => '',
    'whitespace'   => '   ',
    'mailto'       => 'mailto:someone@example.com',
    'javascript'   => 'javascript:alert(1)',
    'ftp'          => 'ftp://example.com/file.txt',
    'file'         => 'file:///etc/passwd',
    'scheme only'  => 'https://',
    'path only'    => '/about',
] );

it( 'rejects a URL longer than the column can hold', function (): void {
    $long = 'https://example.com/' . str_repeat( 'a', UrlNormalizer::MAX_LENGTH );

    expect( UrlNormalizer::normalize( $long ) )->toBeNull();
} );

it( 'accepts a URL that exactly fills the column', function (): void {
    $path = str_repeat( 'a', UrlNormalizer::MAX_LENGTH - strlen( 'https://example.com/' ) );

    expect( UrlNormalizer::normalize( 'https://example.com/' . $path ) )
        ->toBe( 'https://example.com/' . $path );
} );

it( 'accepts a valid URL', function (): void {
    expect( UrlNormalizer::isValid( 'https://example.com/about' ) )->toBeTrue();
} );
