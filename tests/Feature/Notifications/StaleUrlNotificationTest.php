<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Alerts\StaleUrl;
use ArtisanPackUI\PageSpeedInsights\Notifications\StaleUrlNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * A stale URL, defaulted to a page whose runs are not happening at all.
 *
 * @param  array<string, mixed>  $overrides  Constructor overrides.
 *
 * @return StaleUrl The stale URL.
 */
function psiNotifiableStaleUrl( array $overrides = [] ): StaleUrl
{
    return new StaleUrl(
        url: $overrides[ 'url' ] ?? 'https://example.com/pricing',
        frequency: $overrides[ 'frequency' ] ?? 'daily',
        missedCycles: $overrides[ 'missedCycles' ] ?? 2,
        cause: $overrides[ 'cause' ] ?? StaleUrl::CAUSE_NOT_RUNNING,
        lastResultAt: array_key_exists( 'lastResultAt', $overrides )
            ? $overrides[ 'lastResultAt' ]
            : CarbonImmutable::parse( '2026-07-01 09:00:00' ),
        causeDetail: $overrides[ 'causeDetail' ] ?? null,
        urlId: $overrides[ 'urlId' ] ?? 1,
        label: $overrides[ 'label' ] ?? null,
        failures: $overrides[ 'failures' ] ?? 0,
    );
}

it( 'delivers on the channels it was built with', function (): void {
    $notification = new StaleUrlNotification( [ psiNotifiableStaleUrl() ], [ 'mail', 'slack' ] );

    expect( $notification->via( new AnonymousNotifiable() ) )->toBe( [ 'mail', 'slack' ] );
} );

it( 'counts the stale pages in the subject', function (): void {
    $one = new StaleUrlNotification( [ psiNotifiableStaleUrl() ] );

    $two = new StaleUrlNotification( [
        psiNotifiableStaleUrl(),
        psiNotifiableStaleUrl( [ 'url' => 'https://example.com/blog', 'urlId' => 2 ] ),
    ] );

    expect( $one->subject() )->toContain( '1 monitored page has stopped' )
        ->and( $two->subject() )->toContain( '2 monitored pages have stopped' );
} );

it( 'names the last error when runs have been failing', function (): void {
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [
            'cause'       => StaleUrl::CAUSE_FAILING,
            'causeDetail' => 'No PageSpeed Insights API key is configured.',
            'failures'    => 3,
        ] ),
    ] );

    $lines = $notification->toMail( new AnonymousNotifiable() )->introLines;

    expect( implode( ' ', $lines ) )
        ->toContain( '3 failed runs' )
        ->toContain( 'Last error: No PageSpeed Insights API key is configured.' );
} );

it( 'says nothing was recorded at all when no runs happened', function (): void {
    $notification = new StaleUrlNotification( [ psiNotifiableStaleUrl() ] );

    $lines = $notification->toMail( new AnonymousNotifiable() )->introLines;

    expect( implode( ' ', $lines ) )
        ->toContain( 'not even a failure' )
        ->toContain( 'Check that the scheduler is running' );
} );

it( 'says a URL has never completed a test rather than dating it', function (): void {
    $notification = new StaleUrlNotification( [ psiNotifiableStaleUrl( [ 'lastResultAt' => null ] ) ] );

    expect( $notification->toMail( new AnonymousNotifiable() )->introLines )
        ->toContain( '- ' . psiNotifiableStaleUrl( [ 'lastResultAt' => null ] )->describe() );

    expect( implode( ' ', $notification->toMail( new AnonymousNotifiable() )->introLines ) )
        ->toContain( 'has never completed a test' );
} );

it( 'names the URL by its label when it has one', function (): void {
    $notification = new StaleUrlNotification( [ psiNotifiableStaleUrl( [ 'label' => 'Pricing' ] ) ] );

    expect( implode( ' ', $notification->toMail( new AnonymousNotifiable() )->introLines ) )
        ->toContain( 'Pricing (https://example.com/pricing)' );
} );

it( 'leads with the one cause every stale URL shares', function (): void {
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [ 'cause' => StaleUrl::CAUSE_NO_API_KEY ] ),
        psiNotifiableStaleUrl( [
            'url'    => 'https://example.com/blog',
            'urlId'  => 2,
            'cause'  => StaleUrl::CAUSE_NO_API_KEY,
        ] ),
    ] );

    expect( $notification->summary() )->toContain( 'PAGESPEED_API_KEY' );
} );

it( 'still leads with a shared cause when only the unused detail differs', function (): void {
    // A URL that was failing before the key was revoked still carries last
    // week's error message, which no `no_api_key` line renders. Comparing it
    // would suppress exactly the headline this feature exists to print.
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [ 'cause' => StaleUrl::CAUSE_NO_API_KEY ] ),
        psiNotifiableStaleUrl( [
            'url'         => 'https://example.com/blog',
            'urlId'       => 2,
            'cause'       => StaleUrl::CAUSE_NO_API_KEY,
            'causeDetail' => 'The monitored URL returned HTTP 404.',
            'failures'    => 3,
        ] ),
    ] );

    expect( $notification->sharedCause() )->not->toBeNull()
        ->and( $notification->summary() )->toContain( 'PAGESPEED_API_KEY' );
} );

it( 'does not claim a shared cause when two failing URLs failed differently', function (): void {
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [
            'cause'       => StaleUrl::CAUSE_FAILING,
            'causeDetail' => 'The monitored URL returned HTTP 404.',
            'failures'    => 1,
        ] ),
        psiNotifiableStaleUrl( [
            'url'         => 'https://example.com/blog',
            'urlId'       => 2,
            'cause'       => StaleUrl::CAUSE_FAILING,
            'causeDetail' => 'The PageSpeed request timed out.',
            'failures'    => 1,
        ] ),
    ] );

    expect( $notification->sharedCause() )->toBeNull();
} );

it( 'writes the closing line for one page in the singular', function (): void {
    $one = new StaleUrlNotification( [ psiNotifiableStaleUrl() ] );

    $two = new StaleUrlNotification( [
        psiNotifiableStaleUrl(),
        psiNotifiableStaleUrl( [ 'url' => 'https://example.com/blog', 'urlId' => 2 ] ),
    ] );

    expect( implode( ' ', $one->toMail( new AnonymousNotifiable() )->introLines ) )
        ->toContain( "this page's trend chart is showing" )
        ->and( implode( ' ', $two->toMail( new AnonymousNotifiable() )->introLines ) )
        ->toContain( 'the trend charts for these pages are showing' );
} );

it( 'does not claim a shared cause when the causes differ', function (): void {
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [ 'cause' => StaleUrl::CAUSE_NO_API_KEY ] ),
        psiNotifiableStaleUrl( [
            'url'         => 'https://example.com/blog',
            'urlId'       => 2,
            'cause'       => StaleUrl::CAUSE_FAILING,
            'causeDetail' => 'The monitored URL returned HTTP 404.',
            'failures'    => 1,
        ] ),
    ] );

    expect( $notification->sharedCause() )->toBeNull()
        ->and( $notification->summary() )->not->toContain( 'PAGESPEED_API_KEY' );
} );

it( 'says how many URLs it left out rather than truncating quietly', function (): void {
    $stale = [];

    for ( $index = 0; $index < StaleUrlNotification::MAX_LINES + 4; ++$index ) {
        $stale[] = psiNotifiableStaleUrl( [
            'url'   => 'https://example.com/page-' . $index,
            'urlId' => $index + 1,
        ] );
    }

    $lines = ( new StaleUrlNotification( $stale ) )->toMail( new AnonymousNotifiable() )->introLines;

    expect( implode( ' ', $lines ) )->toContain( 'And 4 more URLs not listed here.' );
} );

it( 'carries the diagnosis on the array channels', function (): void {
    $notification = new StaleUrlNotification( [
        psiNotifiableStaleUrl( [
            'cause'       => StaleUrl::CAUSE_FAILING,
            'causeDetail' => 'The monitored URL returned HTTP 404.',
            'failures'    => 2,
        ] ),
    ] );

    $payload = $notification->toArray( new AnonymousNotifiable() );

    expect( $payload[ 'urls' ] )->toBe( 1 )
        ->and( $payload[ 'stale' ][ 0 ][ 'cause' ] )->toBe( StaleUrl::CAUSE_FAILING )
        ->and( $payload[ 'stale' ][ 0 ][ 'cause_detail' ] )->toBe( 'The monitored URL returned HTTP 404.' )
        ->and( $payload[ 'stale' ][ 0 ][ 'failures' ] )->toBe( 2 )
        ->and( $payload[ 'stale' ][ 0 ][ 'last_result_at' ] )->toContain( '2026-07-01' );
} );
