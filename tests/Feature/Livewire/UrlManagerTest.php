<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Livewire\UrlManager;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlNormalizer;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    removeAllFilters( UrlRegistry::FILTER_REGISTER_URLS );

    config()->set( 'app.url', 'https://example.com' );
    config()->set( 'pagespeed-insights.sitemap.url', null );
} );

afterEach( function (): void {
    removeAllFilters( UrlRegistry::FILTER_REGISTER_URLS );
} );

it( 'says the monitored set is empty rather than rendering an empty table', function (): void {
    Livewire::test( UrlManager::class )
        ->assertSet( 'rows', [] )
        ->assertSet( 'totalCount', 0 )
        ->assertSee( 'No URLs are monitored yet.' );
} );

it( 'lists the stored URLs with their labels and source badges', function (): void {
    PageSpeedUrl::factory()->create( [
        'url'   => 'https://example.com/pricing',
        'label' => 'Pricing page',
    ] );

    PageSpeedUrl::factory()->fromSitemap()->create( [
        'url'   => 'https://example.com/about',
        'label' => null,
    ] );

    $rows = Livewire::test( UrlManager::class )
        ->assertSet( 'totalCount', 2 )
        ->assertSee( 'Pricing page' )
        ->assertSee( 'https://example.com/about' )
        ->assertSee( 'Sitemap' )
        ->get( 'rows' );

    expect( array_column( $rows, 'url' ) )
        ->toBe( [ 'https://example.com/pricing', 'https://example.com/about' ] );
    expect( $rows[ 0 ][ 'editable' ] )->toBeTrue();
} );

it( 'adds a URL and stores it in its canonical form', function (): void {
    Livewire::test( UrlManager::class )
        ->set( 'newUrl', 'HTTPS://Example.com/about/#team' )
        ->set( 'newLabel', 'About us' )
        ->call( 'add' )
        ->assertHasNoErrors()
        ->assertSet( 'newUrl', '' )
        ->assertSet( 'newLabel', '' )
        ->assertSee( 'URL added' );

    $stored = PageSpeedUrl::query()->sole();

    expect( $stored->url )->toBe( 'https://example.com/about' );
    expect( $stored->label )->toBe( 'About us' );
    expect( $stored->source )->toBe( PageSpeedUrl::SOURCE_MANUAL );
} );

it( 'refuses to add a blank URL', function (): void {
    Livewire::test( UrlManager::class )
        ->call( 'add' )
        ->assertHasErrors( 'newUrl' );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'refuses a URL PageSpeed cannot test', function ( string $url ): void {
    Livewire::test( UrlManager::class )
        ->set( 'newUrl', $url )
        ->call( 'add' )
        ->assertHasErrors( 'newUrl' )
        ->assertSee( 'Enter a full http:// or https:// address.' );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} )->with( [
    'a mail address'   => [ 'mailto:someone@example.com' ],
    'a javascript URL' => [ 'javascript:alert(1)' ],
    'a file URL'       => [ 'file:///etc/passwd' ],
] );

it( 'names the length limit rather than calling an over-long URL untestable', function (): void {
    // "Not an address PageSpeed can test" sends somebody looking for a typo in
    // a URL that is perfectly valid and merely longer than the column.
    $url = 'https://example.com/' . str_repeat( 'a', UrlNormalizer::MAX_LENGTH );

    Livewire::test( UrlManager::class )
        ->set( 'newUrl', $url )
        ->call( 'add' )
        ->assertHasErrors( 'newUrl' )
        ->assertSee( 'longer than 500 characters' );
} );

it( 'refuses a label longer than the column', function (): void {
    Livewire::test( UrlManager::class )
        ->set( 'newUrl', 'https://example.com/pricing' )
        ->set( 'newLabel', str_repeat( 'a', UrlManager::MAX_LABEL_LENGTH + 1 ) )
        ->call( 'add' )
        ->assertHasErrors( 'newLabel' );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'accepts a label that fills the column in a multibyte script', function (): void {
    // Counted in characters, because that is what varchar(255) counts.
    // Measuring bytes would refuse a perfectly storable label.
    $label = str_repeat( 'é', UrlManager::MAX_LABEL_LENGTH );

    Livewire::test( UrlManager::class )
        ->set( 'newUrl', 'https://example.com/pricing' )
        ->set( 'newLabel', $label )
        ->call( 'add' )
        ->assertHasNoErrors();

    expect( PageSpeedUrl::query()->sole()->label )->toBe( $label );
} );

it( 'refuses a duplicate however it is spelled', function (): void {
    // Checked against the canonical form, so a trailing slash does not buy a
    // second history of the same page.
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );

    Livewire::test( UrlManager::class )
        ->set( 'newUrl', 'https://example.com/about/' )
        ->call( 'add' )
        ->assertHasErrors( 'newUrl' )
        ->assertSee( 'is already monitored' );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
} );

it( 'lists hook-contributed URLs without any controls for them', function (): void {
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/checkout',
    ] );

    $rows = Livewire::test( UrlManager::class )
        ->assertSee( 'https://example.com/checkout' )
        ->assertSee( 'Registered by another package' )
        ->get( 'rows' );

    expect( $rows )->toHaveCount( 1 );
    expect( $rows[ 0 ][ 'id' ] )->toBeNull();
    expect( $rows[ 0 ][ 'editable' ] )->toBeFalse();
    expect( $rows[ 0 ][ 'source' ] )->toBe( PageSpeedUrl::SOURCE_HOOK );
} );

it( 'lets an operator take over a hook-contributed URL by adding it', function (): void {
    // Otherwise a hook URL is permanently unmanageable from this screen, which
    // is a dead end rather than a safeguard.
    addFilter( UrlRegistry::FILTER_REGISTER_URLS, static fn ( array $urls ): array => [
        ...$urls,
        'https://example.com/checkout',
    ] );

    Livewire::test( UrlManager::class )
        ->set( 'newUrl', 'https://example.com/checkout' )
        ->call( 'add' )
        ->assertHasNoErrors()
        ->assertSee( 'was registered by another package' );

    $rows = Livewire::test( UrlManager::class )->get( 'rows' );

    expect( $rows )->toHaveCount( 1 );
    expect( $rows[ 0 ][ 'editable' ] )->toBeTrue();
} );

it( 'pauses and resumes a URL without losing its history', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/pricing' ] );

    PageSpeedResult::factory()->create( [
        'url'      => 'https://example.com/pricing',
        'strategy' => 'mobile',
    ] );

    $component = Livewire::test( UrlManager::class )
        ->call( 'toggleActive', $url->getKey() )
        ->assertSee( 'Testing paused' );

    expect( $url->fresh()->is_active )->toBeFalse();
    expect( PageSpeedResult::query()->count() )->toBe( 1 );

    $component->call( 'toggleActive', $url->getKey() )
        ->assertSee( 'Testing resumed' );

    expect( $url->fresh()->is_active )->toBeTrue();
} );

it( 'asks before deleting a URL, because there is no undo', function (): void {
    $url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/pricing' ] );

    PageSpeedResult::factory()->create( [
        'url'              => 'https://example.com/pricing',
        'strategy'         => 'mobile',
        'pagespeed_url_id' => $url->getKey(),
    ] );

    $component = Livewire::test( UrlManager::class )
        ->call( 'confirmRemoval', $url->getKey() )
        ->assertSet( 'pendingRemovalId', $url->getKey() )
        ->assertSee( 'Delete this URL and its history?' );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );

    $component->call( 'remove' )
        ->assertSet( 'pendingRemovalId', null )
        ->assertSee( 'URL removed' );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'abandons a pending deletion when it is cancelled', function (): void {
    $url = PageSpeedUrl::factory()->create();

    Livewire::test( UrlManager::class )
        ->call( 'confirmRemoval', $url->getKey() )
        ->call( 'cancelRemoval' )
        ->assertSet( 'pendingRemovalId', null )
        ->call( 'remove' );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
} );

it( 'deletes nothing when no removal was confirmed', function (): void {
    PageSpeedUrl::factory()->create();

    Livewire::test( UrlManager::class )->call( 'remove' );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
} );

it( 'sets a per-URL frequency override', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'url'            => 'https://example.com/pricing',
        'test_frequency' => null,
    ] );

    Livewire::test( UrlManager::class )
        ->set( 'frequencies.' . $url->getKey(), 'daily' )
        ->assertSee( 'Test frequency updated' );

    expect( $url->fresh()->test_frequency )->toBe( 'daily' );
} );

it( 'clears a per-URL frequency override back to the package default', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'url'            => 'https://example.com/pricing',
        'test_frequency' => 'hourly',
    ] );

    Livewire::test( UrlManager::class )
        ->set( 'frequencies.' . $url->getKey(), UrlManager::FREQUENCY_INHERIT );

    expect( $url->fresh()->test_frequency )->toBeNull();
} );

it( 'ignores a frequency the package does not understand', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'url'            => 'https://example.com/pricing',
        'test_frequency' => 'daily',
    ] );

    Livewire::test( UrlManager::class )
        ->set( 'frequencies.' . $url->getKey(), 'every-other-tuesday' );

    expect( $url->fresh()->test_frequency )->toBe( 'daily' );
} );

it( 'refuses to change a row it never listed', function (): void {
    // The id arrives from the browser, so it is checked against the rows this
    // component actually rendered before it reaches the database.
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/listed' ] );

    $component = Livewire::test( UrlManager::class );

    $unlisted = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/unlisted' ] );

    $component->call( 'toggleActive', $unlisted->getKey() )
        ->call( 'confirmRemoval', $unlisted->getKey() )
        ->assertSet( 'pendingRemovalId', null );

    expect( $unlisted->fresh()->is_active )->toBeTrue();
    expect( PageSpeedUrl::query()->count() )->toBe( 2 );
} );

it( 'refuses to let the browser arm the deletion confirmation itself', function (): void {
    // remove() takes no id at all — it deletes whatever confirmRemoval()
    // named, and confirmRemoval() checks the id against the listed rows. So
    // the only way to redirect the button that says "yes, delete this page"
    // would be to write the pending id from the client.
    $url = PageSpeedUrl::factory()->create();

    Livewire::test( UrlManager::class )
        ->set( 'pendingRemovalId', $url->getKey() );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a status message the browser asks it to display', function (): void {
    // The status line is the component's account of what it just did. A client
    // that can author it can make the screen report a deletion that never
    // happened.
    Livewire::test( UrlManager::class )
        ->set( 'statusMessage', 'Everything is fine.' );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a frequency change for a row it never listed', function (): void {
    $url = PageSpeedUrl::factory()->create( [
        'url'            => 'https://example.com/pricing',
        'test_frequency' => 'daily',
    ] );

    $component = Livewire::test( UrlManager::class );

    $url->delete();

    // The row is gone from the database but still on the client's screen.
    // Naming it must be a no-op rather than an error page.
    $component->set( 'frequencies.' . $url->getKey(), 'hourly' )
        ->assertSet( 'rows', [] );
} );

it( 'shows the latest performance score for each form factor the URL tests', function (): void {
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/pricing' ] );

    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/pricing',
        'strategy'          => 'mobile',
        'performance_score' => 42,
    ] );

    PageSpeedResult::factory()->desktop()->create( [
        'url'               => 'https://example.com/pricing',
        'performance_score' => 95,
    ] );

    $rows = Livewire::test( UrlManager::class )->get( 'rows' );

    expect( $rows[ 0 ][ 'measurements' ] )->toHaveCount( 2 );
    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'score' ] )->toBe( 42 );
    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'state' ] )->toBe( UrlManager::MEASUREMENT_SCORED );
    expect( $rows[ 0 ][ 'measurements' ][ 1 ][ 'score' ] )->toBe( 95 );
} );

it( 'reads the newest run per URL and form factor rather than per URL', function (): void {
    // The grouping keys on both columns. Keyed on the URL alone, one page's
    // desktop score would overwrite its mobile one, and both rows would report
    // whichever run happened to be written last.
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/one' ] );
    PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/two' ] );

    foreach ( [ 'https://example.com/one' => 10, 'https://example.com/two' => 30 ] as $url => $base ) {
        PageSpeedResult::factory()->create( [
            'url'               => $url,
            'strategy'          => 'mobile',
            'performance_score' => $base,
        ] );

        PageSpeedResult::factory()->desktop()->create( [
            'url'               => $url,
            'performance_score' => $base + 1,
        ] );
    }

    $rows = Livewire::test( UrlManager::class )->get( 'rows' );

    expect( array_column( $rows[ 0 ][ 'measurements' ], 'score' ) )->toBe( [ 10, 11 ] );
    expect( array_column( $rows[ 1 ][ 'measurements' ], 'score' ) )->toBe( [ 30, 31 ] );
} );

it( 'gives a mobile-only URL a mobile cell and no desktop cell', function (): void {
    // A blank desktop figure reads as a desktop run that failed.
    PageSpeedUrl::factory()->strategy( 'mobile' )->create( [ 'url' => 'https://example.com/pricing' ] );

    $rows = Livewire::test( UrlManager::class )->get( 'rows' );

    expect( $rows[ 0 ][ 'measurements' ] )->toHaveCount( 1 );
    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'strategy' ] )->toBe( 'mobile' );
} );

it( 'reports a URL with no runs as untested rather than as unscored', function (): void {
    PageSpeedUrl::factory()->strategy( 'mobile' )->create( [ 'url' => 'https://example.com/pricing' ] );

    Livewire::test( UrlManager::class )
        ->assertSee( 'Not tested yet' )
        ->assertSet( 'rows.0.measurements.0.state', UrlManager::MEASUREMENT_NONE );
} );

it( 'never falls back past a failure to the last good score', function (): void {
    // A green number on a page that stopped being testable a month ago is the
    // single most misleading thing this table could render.
    PageSpeedUrl::factory()->strategy( 'mobile' )->create( [ 'url' => 'https://example.com/pricing' ] );

    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/pricing',
        'strategy'          => 'mobile',
        'performance_score' => 98,
    ] );

    PageSpeedResult::factory()->failed( 'PageSpeed returned HTTP 500 for this URL.' )->create( [
        'url'      => 'https://example.com/pricing',
        'strategy' => 'mobile',
    ] );

    $rows = Livewire::test( UrlManager::class )
        ->assertSee( 'Failed' )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'state' ] )->toBe( UrlManager::MEASUREMENT_FAILED );
    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'score' ] )->toBeNull();
} );

it( 'renders a run with no performance score as unscored rather than as a zero', function (): void {
    PageSpeedUrl::factory()->strategy( 'mobile' )->create( [ 'url' => 'https://example.com/pricing' ] );

    PageSpeedResult::factory()->create( [
        'url'               => 'https://example.com/pricing',
        'strategy'          => 'mobile',
        'performance_score' => null,
    ] );

    $rows = Livewire::test( UrlManager::class )
        ->assertSee( 'No score' )
        ->get( 'rows' );

    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'state' ] )->toBe( UrlManager::MEASUREMENT_UNAVAILABLE );
    expect( $rows[ 0 ][ 'measurements' ][ 0 ][ 'score' ] )->toBeNull();
} );

it( 'imports the URLs a sitemap lists, paused', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/',
            'https://example.com/about',
        ] ) ),
    ] );

    Livewire::test( UrlManager::class )
        ->call( 'importSitemap' )
        ->assertSee( 'Sitemap imported' )
        ->assertSee( '2 added' );

    $stored = PageSpeedUrl::query()->orderBy( 'id' )->get();

    expect( $stored )->toHaveCount( 2 );
    expect( $stored->pluck( 'source' )->unique()->all() )->toBe( [ PageSpeedUrl::SOURCE_SITEMAP ] );
    // Paused on arrival: a 500-page sitemap activated in one click is a
    // thousand API requests a cycle against a quota nobody has looked at yet.
    expect( $stored->pluck( 'is_active' )->filter()->all() )->toBe( [] );
} );

it( 'leaves a URL an operator already paused alone when re-importing', function (): void {
    $existing = PageSpeedUrl::factory()->inactive()->create( [ 'url' => 'https://example.com/about' ] );

    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [
            'https://example.com/about',
        ] ) ),
    ] );

    Livewire::test( UrlManager::class )
        ->call( 'importSitemap' )
        ->assertSee( 'all of them were already monitored' );

    expect( PageSpeedUrl::query()->count() )->toBe( 1 );
    expect( $existing->fresh()->is_active )->toBeFalse();
} );

it( 'says why a sitemap could not be read rather than reporting nothing found', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( 'Not found', 404 ),
    ] );

    Livewire::test( UrlManager::class )
        ->call( 'importSitemap' )
        ->assertSee( 'The sitemap could not be read' )
        ->assertSee( '404' );

    expect( PageSpeedUrl::query()->count() )->toBe( 0 );
} );

it( 'separates an empty sitemap from one that could not be read', function (): void {
    Http::fake( [
        'https://example.com/sitemap.xml' => Http::response( psiSitemapUrlset( [] ) ),
    ] );

    Livewire::test( UrlManager::class )
        ->call( 'importSitemap' )
        ->assertSee( 'Nothing to import' )
        ->assertDontSee( 'The sitemap could not be read' );
} );

it( 'names the sitemap the import button will read', function (): void {
    config()->set( 'pagespeed-insights.sitemap.url', 'https://example.com/sitemap_index.xml' );

    Livewire::test( UrlManager::class )
        ->assertSet( 'sitemapUrl', 'https://example.com/sitemap_index.xml' )
        ->assertSee( 'https://example.com/sitemap_index.xml' );
} );

it( 'says so when the monitored set is longer than it lists', function (): void {
    // A list that quietly shows a prefix of the monitored set is a list an
    // operator will trust to be all of it.
    PageSpeedUrl::factory()->count( UrlManager::MAX_URLS + 2 )->create();

    Livewire::test( UrlManager::class )
        ->assertSet( 'truncated', true )
        ->assertSet( 'totalCount', UrlManager::MAX_URLS + 2 )
        ->assertSee( 'Showing the first' )
        ->assertCount( 'rows', UrlManager::MAX_URLS );
} );

it( 'renders an install notice instead of exploding when the component library is absent', function (): void {
    UiComponentsInstalled::setForTesting( false );

    Livewire::test( UrlManager::class )
        ->assertSet( 'uiComponentsInstalled', false )
        ->assertSee( 'composer require artisanpack-ui/livewire-ui-components' );
} );

it( 'refuses rows the browser asks it to display', function (): void {
    Livewire::test( UrlManager::class )
        ->set( 'rows', [ [ 'id' => 1, 'url' => 'https://attacker.example/pwn' ] ] );
} )->throws( CannotUpdateLockedPropertyException::class );

it( 'refuses a sitemap address the browser asks it to advertise', function (): void {
    Livewire::test( UrlManager::class )
        ->set( 'sitemapUrl', 'https://attacker.example/sitemap.xml' );
} )->throws( CannotUpdateLockedPropertyException::class );
