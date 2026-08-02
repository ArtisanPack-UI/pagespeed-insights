<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Alerts\Regression;
use ArtisanPackUI\PageSpeedInsights\Notifications\ScoreRegressionNotification;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * A regression, with everything defaulted to a plain drop.
 *
 * @param  array<string, mixed>  $overrides  Constructor overrides.
 *
 * @return Regression The regression.
 */
function psiNotifiableRegression( array $overrides = [] ): Regression
{
    return new Regression(
        type: $overrides[ 'type' ] ?? Regression::TYPE_DROP,
        url: $overrides[ 'url' ] ?? 'https://example.com/pricing',
        strategy: $overrides[ 'strategy' ] ?? 'mobile',
        category: $overrides[ 'category' ] ?? 'performance',
        currentScore: array_key_exists( 'currentScore', $overrides ) ? $overrides[ 'currentScore' ] : 55,
        previousScore: array_key_exists( 'previousScore', $overrides ) ? $overrides[ 'previousScore' ] : 90,
        threshold: $overrides[ 'threshold' ] ?? null,
        degraded: $overrides[ 'degraded' ] ?? false,
    );
}

it( 'delivers on the channels it was built with', function (): void {
    $notification = new ScoreRegressionNotification( [ psiNotifiableRegression() ], [ 'mail', 'slack' ] );

    expect( $notification->via( new AnonymousNotifiable() ) )->toBe( [ 'mail', 'slack' ] );
} );

it( 'counts pages rather than regressions in the subject', function (): void {
    $notification = new ScoreRegressionNotification( [
        psiNotifiableRegression(),
        psiNotifiableRegression( [ 'strategy' => 'desktop' ] ),
        psiNotifiableRegression( [ 'category' => 'seo' ] ),
    ] );

    expect( $notification->subject() )->toContain( '1 page' )
        ->and( $notification->subject() )->not->toContain( '1 pages' )
        ->and( $notification->summary() )->toContain( '3 score regressions' )
        ->and( $notification->summary() )->toContain( '1 monitored URL' );
} );

it( 'spells out every regression in the mail body', function (): void {
    $notification = new ScoreRegressionNotification( [
        psiNotifiableRegression(),
        psiNotifiableRegression( [
            'category'      => 'seo',
            'type'          => Regression::TYPE_THRESHOLD,
            'currentScore'  => 40,
            'previousScore' => 44,
            'threshold'     => 90,
        ] ),
        psiNotifiableRegression( [
            'category'     => 'accessibility',
            'type'         => Regression::TYPE_STOPPED,
            'currentScore' => null,
        ] ),
    ] );

    $lines = implode( "\n", ( $notification->toMail( new AnonymousNotifiable() ) )->introLines );

    expect( $lines )->toContain( 'Performance on https://example.com/pricing (mobile) fell 35 points, from 90 to 55.' )
        ->toContain( 'SEO on https://example.com/pricing (mobile) scored 40, below the floor of 90, down from 44.' )
        ->toContain( 'Accessibility on https://example.com/pricing (mobile) has no score at all this run' );
} );

it( 'says when a run it is reporting on was degraded', function (): void {
    $notification = new ScoreRegressionNotification( [ psiNotifiableRegression( [ 'degraded' => true ] ) ] );

    $lines = implode( "\n", ( $notification->toMail( new AnonymousNotifiable() ) )->introLines );

    expect( $notification->hasDegraded() )->toBeTrue()
        ->and( $lines )->toContain( 'completed with data missing' );
} );

it( 'says how many regressions it left out rather than truncating quietly', function (): void {
    $regressions = [];

    for ( $index = 0; $index < ScoreRegressionNotification::MAX_LINES + 3; $index++ ) {
        $regressions[] = psiNotifiableRegression( [ 'url' => 'https://example.com/page-' . $index ] );
    }

    $lines = implode( "\n", ( new ScoreRegressionNotification( $regressions ) )
        ->toMail( new AnonymousNotifiable() )->introLines );

    expect( $lines )->toContain( 'And 3 more regressions not listed here.' );
} );

it( 'carries the whole set on the array channel', function (): void {
    $payload = ( new ScoreRegressionNotification( [
        psiNotifiableRegression(),
        psiNotifiableRegression( [ 'url' => 'https://example.com/blog' ] ),
    ] ) )->toArray( new AnonymousNotifiable() );

    expect( $payload[ 'urls' ] )->toBe( 2 )
        ->and( $payload[ 'regressions' ] )->toHaveCount( 2 )
        ->and( $payload[ 'regressions' ][ 0 ] )->toMatchArray( [
            'category'    => 'performance',
            'points_lost' => 35,
        ] );
} );

it( 'survives the round trip through the digest buffer', function (): void {
    $original = psiNotifiableRegression( [
        'type'      => Regression::TYPE_THRESHOLD,
        'threshold' => 60,
        'degraded'  => true,
    ] );

    $rebuilt = Regression::fromArray( $original->toArray() );

    expect( $rebuilt->toArray() )->toBe( $original->toArray() )
        ->and( $rebuilt->describe() )->toBe( $original->describe() );
} );

it( 'only says a floor breach came down when it actually came down', function (): void {
    $fell = psiNotifiableRegression( [
        'type'          => Regression::TYPE_THRESHOLD,
        'threshold'     => 90,
        'currentScore'  => 40,
        'previousScore' => 60,
    ] );

    $rose = psiNotifiableRegression( [
        'type'          => Regression::TYPE_THRESHOLD,
        'threshold'     => 90,
        'currentScore'  => 60,
        'previousScore' => 40,
    ] );

    expect( $fell->describe() )->toContain( 'down from 60' )
        ->and( $rose->describe() )->toContain( 'scored 60, below the floor of 90.' )
        ->and( $rose->describe() )->not->toContain( 'down from' );
} );
