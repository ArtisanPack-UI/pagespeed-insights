<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;

it( 'translates response keys to the request enum', function ( string $responseKey, string $enum ): void {
    expect( CategoryTranslator::toRequestEnum( $responseKey ) )->toBe( $enum );
} )->with( [
    'single word'          => [ 'performance', 'PERFORMANCE' ],
    'hyphenated'           => [ 'best-practices', 'BEST_PRACTICES' ],
    'acronym'              => [ 'seo', 'SEO' ],
    'category added later' => [ 'agentic-browsing', 'AGENTIC_BROWSING' ],
] );

it( 'translates the request enum back to response keys', function ( string $enum, string $responseKey ): void {
    expect( CategoryTranslator::toResponseKey( $enum ) )->toBe( $responseKey );
} )->with( [
    'single word'          => [ 'PERFORMANCE', 'performance' ],
    'underscored'          => [ 'BEST_PRACTICES', 'best-practices' ],
    'acronym'              => [ 'SEO', 'seo' ],
    'category added later' => [ 'AGENTIC_BROWSING', 'agentic-browsing' ],
] );

it( 'round-trips every known category', function (): void {
    foreach ( CategoryTranslator::KNOWN as $category ) {
        expect( CategoryTranslator::toResponseKey( CategoryTranslator::toRequestEnum( $category ) ) )
            ->toBe( $category );
    }
} );

it( 'recognizes the four categories this package scores', function (): void {
    expect( CategoryTranslator::isKnown( 'performance' ) )->toBeTrue()
        ->and( CategoryTranslator::isKnown( 'BEST_PRACTICES' ) )->toBeTrue()
        ->and( CategoryTranslator::isKnown( 'pwa' ) )->toBeFalse()
        ->and( CategoryTranslator::isKnown( 'AGENTIC_BROWSING' ) )->toBeFalse();
} );

it( 'tolerates surrounding whitespace', function (): void {
    expect( CategoryTranslator::toRequestEnum( '  best-practices ' ) )->toBe( 'BEST_PRACTICES' )
        ->and( CategoryTranslator::toResponseKey( ' BEST_PRACTICES  ' ) )->toBe( 'best-practices' );
} );
