<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Console\Commands\TestCommand;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'pagespeed-insights.driver'  => 'config',
        'pagespeed-insights.api_key' => 'test-key',
    ] );
} );

/**
 * Answer every PageSpeed request with one payload.
 *
 * @param  array<string, mixed>  $payload  The response body.
 * @param  int  $status  The HTTP status to answer with.
 *
 * @return void
 */
function psiFakeRun( array $payload, int $status = 200 ): void
{
    Http::fake( [ '*' => Http::response( $payload, $status ) ] );
}

/**
 * Run the command and return its exit code and decoded JSON document.
 *
 * @param  array<string, mixed>  $options  Command options, without --json.
 *
 * @return array{0: int, 1: array<string, mixed>} The exit code and the document.
 */
function psiRunJson( array $options = [] ): array
{
    $exitCode = Artisan::call( 'pagespeed:test', [
        'url'    => 'https://example.com/',
        '--json' => true,
    ] + $options );

    return [ $exitCode, json_decode( trim( Artisan::output() ), true, 512, JSON_THROW_ON_ERROR ) ];
}

/**
 * Run the command and return its exit code and terminal output.
 *
 * Captured through the Artisan facade rather than asserted on a pending
 * command, because table rows and wrapped component lines do not reach
 * `expectsOutputToContain` in one piece.
 *
 * @param  array<string, mixed>  $options  Command options.
 *
 * @return array{0: int, 1: string} The exit code and the output.
 */
function psiRunText( array $options = [] ): array
{
    $exitCode = Artisan::call( 'pagespeed:test', [ 'url' => 'https://example.com/' ] + $options );

    return [ $exitCode, Artisan::output() ];
}

describe( 'table output', function (): void {
    it( 'prints the scores and metrics and succeeds when no budget was set', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode, $output ] = psiRunText();

        expect( $exitCode )->toBe( 0 )
            ->and( $output )->toContain( 'Performance' )
            ->and( $output )->toContain( '97' )
            ->and( $output )->toContain( 'Largest Contentful Paint' )
            ->and( $output )->toContain( '1.8 s' )
            ->and( $output )->toContain( 'No budgets were set' );
    } );

    it( 'shows the budget alongside the measurement it was checked against', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode, $output ] = psiRunText( [ '--min-performance' => '90', '--max-lcp-ms' => '2500' ] );

        expect( $exitCode )->toBe( 0 )
            ->and( $output )->toContain( '>= 90' )
            ->and( $output )->toContain( '<= 2500' )
            ->and( $output )->toContain( 'PASS' )
            ->and( $output )->toContain( 'All 2 budget(s) passed.' );
    } );

    it( 'names the failing budget rather than only failing', function (): void {
        psiFakeRun( psiFixture( 'poor' ) );

        [ $exitCode, $output ] = psiRunText( [ '--min-performance' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_BUDGET_VIOLATED )
            ->and( $output )->toContain( 'FAIL' )
            ->and( preg_replace( '/\s+/', ' ', $output ) )
            ->toContain( 'Performance is 21, which is below the --min-performance budget of 90.' );
    } );

    it( 'shows a degraded run as degraded rather than as a quiet one', function (): void {
        psiFakeRun( psiFixture( 'poor' ) );

        [ $exitCode, $output ] = psiRunText();

        expect( $exitCode )->toBe( 0 )
            ->and( preg_replace( '/\s+/', ' ', $output ) )
            ->toContain( 'A resource load ended in an error' );
    } );
} );

describe( 'json output', function (): void {
    it( 'emits one parseable document carrying the scores, metrics, and warnings', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode, $document ] = psiRunJson();

        expect( $exitCode )->toBe( 0 )
            ->and( $document[ 'status' ] )->toBe( 'passed' )
            ->and( $document[ 'failure_class' ] )->toBeNull()
            ->and( $document[ 'exit_code' ] )->toBe( 0 )
            ->and( $document[ 'url' ] )->toBe( 'https://example.com/' )
            ->and( $document[ 'strategy' ] )->toBe( 'mobile' )
            ->and( $document[ 'scores' ][ 'performance' ] )->toBe( 97 )
            ->and( $document[ 'metrics' ][ 'largest-contentful-paint' ][ 'value' ] )->toBe( 1834.21 )
            ->and( $document[ 'metrics' ][ 'largest-contentful-paint' ][ 'display' ] )->toBe( '1.8 s' )
            ->and( $document[ 'warnings' ] )->toBeArray()
            ->and( $document[ 'budgets' ] )->toBe( [] );
    } );

    it( 'carries the per-budget verdicts a CI annotation is built from', function (): void {
        psiFakeRun( psiFixture( 'poor' ) );

        [ $exitCode, $document ] = psiRunJson( [
            '--min-performance' => '90',
            '--min-seo'         => '50',
        ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_BUDGET_VIOLATED )
            ->and( $document[ 'status' ] )->toBe( 'failed' )
            ->and( $document[ 'failure_class' ] )->toBe( 'budget' )
            ->and( $document[ 'budgets' ] )->toHaveCount( 2 )
            ->and( $document[ 'budgets' ][ 0 ] )->toMatchArray( [
                'flag'   => 'min-performance',
                'type'   => 'score',
                'key'    => 'performance',
                'target' => 90,
                'actual' => 21,
                'status' => 'fail',
            ] )
            ->and( $document[ 'budgets' ][ 0 ][ 'message' ] )->toContain( 'below' )
            ->and( $document[ 'budgets' ][ 1 ][ 'status' ] )->toBe( 'pass' )
            ->and( $document[ 'budgets' ][ 1 ][ 'message' ] )->toBeNull();
    } );

    it( 'reports a degraded run in the payload, not only on the terminal', function (): void {
        psiFakeRun( psiFixture( 'poor' ) );

        [ , $document ] = psiRunJson();

        expect( $document[ 'warnings' ] )->toContain(
            'A resource load ended in an error, which may have affected the measured performance.',
        );
    } );
} );

describe( 'budget flags', function (): void {
    it( 'passes when the run is inside the budget', function ( string $flag, string $value ): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode, $document ] = psiRunJson( [ $flag => $value ] );

        expect( $exitCode )->toBe( 0 )
            ->and( $document[ 'budgets' ][ 0 ][ 'status' ] )->toBe( 'pass' );
    } )->with( [
        'min-performance'    => [ '--min-performance', '90' ],
        'min-accessibility'  => [ '--min-accessibility', '100' ],
        'min-best-practices' => [ '--min-best-practices', '96' ],
        'min-seo'            => [ '--min-seo', '100' ],
        'max-lcp-ms'         => [ '--max-lcp-ms', '2500' ],
        'max-cls'            => [ '--max-cls', '0.1' ],
        'max-tbt-ms'         => [ '--max-tbt-ms', '200' ],
    ] );

    it( 'exits non-zero when the run breaches the budget', function ( string $flag, string $value ): void {
        psiFakeRun( psiFixture( 'poor' ) );

        [ $exitCode, $document ] = psiRunJson( [ $flag => $value ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_BUDGET_VIOLATED )
            ->and( $document[ 'failure_class' ] )->toBe( 'budget' )
            ->and( $document[ 'budgets' ][ 0 ][ 'status' ] )->toBe( 'fail' );
    } )->with( [
        'min-performance'    => [ '--min-performance', '90' ],
        'min-accessibility'  => [ '--min-accessibility', '90' ],
        'min-best-practices' => [ '--min-best-practices', '90' ],
        'min-seo'            => [ '--min-seo', '90' ],
        'max-lcp-ms'         => [ '--max-lcp-ms', '2500' ],
        'max-cls'            => [ '--max-cls', '0.1' ],
        'max-tbt-ms'         => [ '--max-tbt-ms', '200' ],
    ] );

    it( 'treats the budget boundary as met rather than breached', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode ] = psiRunJson( [ '--min-performance' => '97', '--max-tbt-ms' => '45' ] );

        expect( $exitCode )->toBe( 0 );
    } );
} );

describe( 'a budget with nothing to check', function (): void {
    it( 'fails rather than passing when the budgeted score did not come back', function (): void {
        $payload = psiFixture( 'healthy' );
        unset( $payload[ 'lighthouseResult' ][ 'categories' ][ 'accessibility' ] );

        psiFakeRun( $payload );

        [ $exitCode, $document ] = psiRunJson( [ '--min-accessibility' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'failure_class' ] )->toBe( 'run' )
            ->and( $document[ 'budgets' ][ 0 ][ 'status' ] )->toBe( 'unavailable' )
            ->and( $document[ 'budgets' ][ 0 ][ 'actual' ] )->toBeNull();
    } );

    it( 'says the measurement was missing rather than that it was below budget', function (): void {
        $payload = psiFixture( 'healthy' );
        unset( $payload[ 'lighthouseResult' ][ 'categories' ][ 'accessibility' ] );

        psiFakeRun( $payload );

        [ $exitCode, $output ] = psiRunText( [ '--min-accessibility' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $output )->toContain( 'UNAVAILABLE' )
            ->and( preg_replace( '/\s+/', ' ', $output ) )
            ->toContain( 'The run carried no Accessibility value, so the --min-accessibility budget could not be checked. This is a missing measurement, not a score below budget.' );
    } );

    it( 'reports a category the response omitted rather than ignoring it', function (): void {
        $payload = psiFixture( 'healthy' );
        unset( $payload[ 'lighthouseResult' ][ 'categories' ][ 'seo' ] );

        psiFakeRun( $payload );

        [ , $document ] = psiRunJson();

        expect( $document[ 'warnings' ] )->toContain(
            'PageSpeed did not return the "seo" category, so its score is null for this run.',
        );
    } );

    it( 'fails on the missing measurement even when another budget also broke', function (): void {
        $payload = psiFixture( 'poor' );
        unset( $payload[ 'lighthouseResult' ][ 'categories' ][ 'accessibility' ] );

        psiFakeRun( $payload );

        [ $exitCode, $document ] = psiRunJson( [
            '--min-performance'   => '90',
            '--min-accessibility' => '90',
        ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'failure_class' ] )->toBe( 'run' )
            ->and( collect( $document[ 'budgets' ] )->pluck( 'status' )->all() )->toBe( [ 'fail', 'unavailable' ] );
    } );

    it( 'reports an unavailable lab metric the same way', function (): void {
        $payload = psiFixture( 'healthy' );
        unset( $payload[ 'lighthouseResult' ][ 'audits' ][ 'largest-contentful-paint' ] );

        psiFakeRun( $payload );

        [ $exitCode, $document ] = psiRunJson( [ '--max-lcp-ms' => '2500' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'budgets' ][ 0 ][ 'status' ] )->toBe( 'unavailable' );
    } );
} );

describe( 'the requested categories', function (): void {
    it( 'asks for all four by default and scores all four', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ , $document ] = psiRunJson( [ '--categories' => 'all' ] );

        expect( $document[ 'scores' ] )->toHaveCount( 4 )
            ->and( array_filter( $document[ 'scores' ], 'is_null' ) )->toBe( [] );

        Http::assertSent( function ( Request $request ): bool {
            foreach ( [ 'PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO' ] as $category ) {
                if ( ! str_contains( $request->url(), 'category=' . $category ) ) {
                    return false;
                }
            }

            return true;
        } );
    } );

    it( 'requests only what it was asked for', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        psiRunJson( [ '--categories' => 'performance,seo' ] );

        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'category=PERFORMANCE' )
            && str_contains( $request->url(), 'category=SEO' )
            && ! str_contains( $request->url(), 'category=ACCESSIBILITY' ) );
    } );

    it( 'sends the requested form factor', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        psiRunJson( [ '--strategy' => 'desktop' ] );

        Http::assertSent( fn ( Request $request ): bool => str_contains( $request->url(), 'strategy=DESKTOP' ) );
    } );
} );

describe( 'the --store option', function (): void {
    it( 'stores nothing unless it is passed', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        $this->artisan( 'pagespeed:test', [ 'url' => 'https://example.com/' ] )->assertExitCode( 0 );

        expect( PageSpeedResult::query()->count() )->toBe( 0 );
    } );

    it( 'persists an ad hoc run with no monitored URL behind it', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ $exitCode, $output ] = psiRunText( [ '--store' => true ] );

        expect( $exitCode )->toBe( 0 )
            ->and( $output )->toContain( 'Stored this run as result #' );

        $row = PageSpeedResult::query()->sole();

        expect( $row->pagespeed_url_id )->toBeNull()
            ->and( $row->url )->toBe( 'https://example.com/' )
            ->and( $row->performance_score )->toBe( 97 )
            ->and( $row->status )->toBe( PageSpeedResult::STATUS_COMPLETED );
    } );

    it( 'joins the history of a URL that is already monitored', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        $monitored = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/' ] );

        $this->artisan( 'pagespeed:test', [ 'url' => 'https://example.com/', '--store' => true ] )
            ->assertExitCode( 0 );

        expect( PageSpeedResult::query()->sole()->pagespeed_url_id )->toBe( $monitored->id );
    } );

    it( 'stores a run that violated a budget, since the measurement is real either way', function (): void {
        psiFakeRun( psiFixture( 'poor' ) );

        $exitCode = Artisan::call( 'pagespeed:test', [
            'url'               => 'https://example.com/',
            '--store'           => true,
            '--min-performance' => '90',
        ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_BUDGET_VIOLATED )
            ->and( PageSpeedResult::query()->count() )->toBe( 1 );
    } );

    it( 'reports a write it could not make rather than exiting like a violated budget', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        Schema::drop( 'pagespeed_results' );

        [ $exitCode, $document ] = psiRunJson( [ '--store' => true, '--min-performance' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'failure_class' ] )->toBe( 'run' )
            ->and( $document[ 'stored_result_id' ] )->toBeNull()
            ->and( $document[ 'error' ] )->toContain( '--store could not save it' )
            ->and( mb_strlen( (string) $document[ 'error' ] ) )->toBeLessThan( 400 )
            ->and( $document[ 'budgets' ][ 0 ][ 'status' ] )->toBe( 'pass' );
    } );

    it( 'names the stored row in the json payload', function (): void {
        psiFakeRun( psiFixture( 'healthy' ) );

        [ , $document ] = psiRunJson( [ '--store' => true ] );

        expect( $document[ 'stored_result_id' ] )->toBe( PageSpeedResult::query()->sole()->id );
    } );
} );

describe( 'a run that never happened', function (): void {
    it( 'fails in under a request when no API key is configured', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        Http::fake();

        [ $exitCode, $output ] = psiRunText();

        expect( $exitCode )->toBe( TestCommand::EXIT_NOT_CONFIGURED )
            ->and( $output )->toContain( 'No PageSpeed Insights API key is configured' );

        Http::assertNothingSent();
    } );

    it( 'reports the configuration failure in its own class, apart from a regression', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        Http::fake();

        [ $exitCode, $document ] = psiRunJson( [ '--min-performance' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_NOT_CONFIGURED )
            ->and( $document[ 'failure_class' ] )->toBe( 'configuration' )
            ->and( $document[ 'error' ] )->toContain( 'PAGESPEED_API_KEY' );
    } );

    it( 'exits in the run class when the API returns an error', function (): void {
        psiFakeRun( [ 'error' => [ 'message' => 'Backend error' ] ], 500 );

        [ $exitCode, $document ] = psiRunJson();

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'failure_class' ] )->toBe( 'run' )
            ->and( $document[ 'error' ] )->toContain( 'Backend error' );
    } );

    it( 'exits in the run class when the key is out of quota', function (): void {
        psiFakeRun( psiFixture( 'quota-error' ), 429 );

        [ $exitCode, $document ] = psiRunJson();

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'failure_class' ] )->toBe( 'run' );
    } );

    it( 'prints a Lighthouse runtime error with its own code and message', function (): void {
        psiFakeRun( psiFixture( 'runtime-error' ) );

        [ $exitCode, $document ] = psiRunJson();

        expect( $exitCode )->toBe( TestCommand::EXIT_RUN_FAILED )
            ->and( $document[ 'lighthouse_error_code' ] )->toBe( 'ERRORED_DOCUMENT_REQUEST' )
            ->and( $document[ 'error' ] )->toContain( 'ERRORED_DOCUMENT_REQUEST' );
    } );

    it( 'stores nothing when the run failed', function (): void {
        psiFakeRun( [ 'error' => [ 'message' => 'Backend error' ] ], 500 );

        Artisan::call( 'pagespeed:test', [ 'url' => 'https://example.com/', '--store' => true ] );

        expect( PageSpeedResult::query()->count() )->toBe( 0 );
    } );
} );

describe( 'input the command cannot act on', function (): void {
    it( 'refuses an unusable URL before sending anything', function (): void {
        Http::fake();

        $this->artisan( 'pagespeed:test', [ 'url' => 'not-a-url' ] )
            ->assertExitCode( TestCommand::EXIT_INVALID_INPUT );

        Http::assertNothingSent();
    } );

    it( 'refuses a form factor that is not mobile or desktop', function (): void {
        Http::fake();

        $this->artisan( 'pagespeed:test', [ 'url' => 'https://example.com/', '--strategy' => 'tablet' ] )
            ->assertExitCode( TestCommand::EXIT_INVALID_INPUT );
    } );

    it( 'refuses a budget that is not a number', function (): void {
        Http::fake();

        [ $exitCode, $output ] = psiRunText( [ '--min-performance' => 'ninety' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( $output )->toContain( 'needs a number' );
    } );

    it( 'refuses a score budget outside 0-100', function (): void {
        Http::fake();

        [ $exitCode, $output ] = psiRunText( [ '--min-seo' => '120' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( preg_replace( '/\s+/', ' ', $output ) )->toContain( 'must be between 0 and 100' );
    } );

    it( 'refuses a negative metric budget', function (): void {
        Http::fake();

        [ $exitCode, $output ] = psiRunText( [ '--max-cls' => '-1' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( $output )->toContain( 'cannot be negative' );
    } );

    it( 'refuses a budget on a category it was told not to request', function (): void {
        Http::fake();

        [ $exitCode, $output ] = psiRunText( [ '--categories' => 'performance', '--min-seo' => '90' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( preg_replace( '/\s+/', ' ', $output ) )->toContain( 'was not requested' );

        Http::assertNothingSent();
    } );

    it( 'refuses a metric budget when performance was not requested', function (): void {
        Http::fake();

        [ $exitCode, $output ] = psiRunText( [ '--categories' => 'seo', '--max-lcp-ms' => '2500' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( preg_replace( '/\s+/', ' ', $output ) )->toContain( 'Lab metrics only come back with it' );
    } );

    it( 'reports bad input as such in the json payload', function (): void {
        Http::fake();

        [ $exitCode, $document ] = psiRunJson( [ '--min-performance' => 'ninety' ] );

        expect( $exitCode )->toBe( TestCommand::EXIT_INVALID_INPUT )
            ->and( $document[ 'failure_class' ] )->toBe( 'input' )
            ->and( $document[ 'status' ] )->toBe( 'failed' );
    } );
} );
