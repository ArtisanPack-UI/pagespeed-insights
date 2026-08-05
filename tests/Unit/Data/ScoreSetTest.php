<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Data\ScoreSet;

it( 'exposes the four scored categories', function (): void {
    $scores = new ScoreSet( [
        'performance'    => 97,
        'accessibility'  => 100,
        'best-practices' => 96,
        'seo'            => 100,
    ] );

    expect( $scores->performance() )->toBe( 97 )
        ->and( $scores->accessibility() )->toBe( 100 )
        ->and( $scores->bestPractices() )->toBe( 96 )
        ->and( $scores->seo() )->toBe( 100 );
} );

it( 'returns null for a category the response omitted', function (): void {
    $scores = new ScoreSet( [ 'performance' => 88 ] );

    expect( $scores->seo() )->toBeNull()
        ->and( $scores->has( 'seo' ) )->toBeFalse()
        ->and( $scores->has( 'performance' ) )->toBeTrue();
} );

it( 'distinguishes an unscored category from an absent one', function (): void {
    $scores = new ScoreSet( [ 'accessibility' => null ] );

    expect( $scores->accessibility() )->toBeNull()
        ->and( $scores->has( 'accessibility' ) )->toBeTrue();
} );

it( 'keeps a category it has no accessor for instead of dropping it', function (): void {
    $scores = new ScoreSet( [ 'performance' => 74, 'agentic-browsing' => 52 ] );

    expect( $scores->all() )->toHaveKey( 'agentic-browsing' )
        ->and( $scores->get( 'agentic-browsing' ) )->toBe( 52 )
        ->and( $scores->unrecognized() )->toBe( [ 'agentic-browsing' ] );
} );

it( 'reports nothing unrecognized when only the four are present', function (): void {
    $scores = new ScoreSet( [ 'performance' => 90, 'seo' => 80 ] );

    expect( $scores->unrecognized() )->toBe( [] );
} );

it( 'accepts either spelling when reading a score', function (): void {
    $scores = new ScoreSet( [ 'best-practices' => 96 ] );

    expect( $scores->get( 'BEST_PRACTICES' ) )->toBe( 96 )
        ->and( $scores->get( 'best-practices' ) )->toBe( 96 );
} );
