<?php

declare( strict_types=1 );

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordingQueueJob;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [ 'pagespeed-insights.api_key' => 'test-key' ] );
} );

/**
 * Run a job against a queue double so releases and failures are observable.
 *
 * @param  RunPageSpeedTest  $job  The job to run.
 * @param  int  $attempts  Which attempt this is.
 *
 * @return RecordingQueueJob The double, carrying what the job asked for.
 */
function psiRunJob( RunPageSpeedTest $job, int $attempts = 1 ): RecordingQueueJob
{
    $queueJob = new RecordingQueueJob( $job, $attempts );

    $job->setJob( $queueJob );
    $job->handle( app( PageSpeedClient::class ) );

    return $queueJob;
}

describe( 'a completed run', function (): void {
    it( 'stores the result and stamps the monitored URL', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $url = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_MOBILE, $url->getKey() ) );

        $result = PageSpeedResult::query()->sole();

        expect( $result->status )->toBe( PageSpeedResult::STATUS_COMPLETED )
            ->and( $result->url )->toBe( 'https://example.com/about' )
            ->and( $result->strategy )->toBe( PageSpeedRequest::STRATEGY_MOBILE )
            ->and( $result->pagespeed_url_id )->toBe( $url->getKey() )
            ->and( $result->performance_score )->not->toBeNull()
            ->and( $url->fresh()->last_tested_at )->not->toBeNull();
    } );

    it( 'keeps the result when the monitored URL was deleted mid-flight', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_MOBILE, 4242 ) );

        $result = PageSpeedResult::query()->sole();

        expect( $result->status )->toBe( PageSpeedResult::STATUS_COMPLETED )
            ->and( $result->pagespeed_url_id )->toBeNull()
            ->and( $result->url )->toBe( 'https://example.com/about' );
    } );

    it( 'persists the warnings from a degraded run', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'emerging-categories' ) ) ] );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        $result = PageSpeedResult::query()->sole();

        expect( $result->wasDegraded() )->toBeTrue()
            ->and( $result->warnings )->not->toBeEmpty();
    } );
} );

describe( 'hooks', function (): void {
    it( 'fires ap.pageSpeed.beforeTest before the run', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $url  = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/about' ] );
        $seen = [];

        addAction(
            RunPageSpeedTest::ACTION_BEFORE_TEST,
            function ( string $tested, string $strategy, ?PageSpeedUrl $monitored ) use ( &$seen ): void {
                $seen = [
                    'url'      => $tested,
                    'strategy' => $strategy,
                    'id'       => $monitored?->getKey(),
                    'results'  => PageSpeedResult::query()->count(),
                ];
            },
        );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_DESKTOP, $url->getKey() ) );

        expect( $seen )->toBe( [
            'url'      => 'https://example.com/about',
            'strategy' => PageSpeedRequest::STRATEGY_DESKTOP,
            'id'       => $url->getKey(),
            'results'  => 0,
        ] );
    } );

    it( 'fires ap.pageSpeed.resultStored with the saved row and the parsed run', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'healthy' ) ) ] );

        $seen = [];

        addAction(
            RunPageSpeedTest::ACTION_RESULT_STORED,
            function ( PageSpeedResult $row, ?TestResult $result ) use ( &$seen ): void {
                $seen = [ 'exists' => $row->exists, 'status' => $row->status, 'parsed' => $result?->url ];
            },
        );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        expect( $seen )->toBe( [
            'exists' => true,
            'status' => PageSpeedResult::STATUS_COMPLETED,
            'parsed' => 'https://example.com/about',
        ] );
    } );

    it( 'fires ap.pageSpeed.resultStored for a failed run, with no parsed result', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        $seen = [];

        addAction(
            RunPageSpeedTest::ACTION_RESULT_STORED,
            function ( PageSpeedResult $row, ?TestResult $result ) use ( &$seen ): void {
                $seen = [ 'status' => $row->status, 'parsed' => $result ];
            },
        );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        expect( $seen )->toBe( [ 'status' => PageSpeedResult::STATUS_FAILED, 'parsed' => null ] );
    } );
} );

describe( 'a missing API key', function (): void {
    it( 'writes exactly one failed row and never retries', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        $url      = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );
        $queueJob = psiRunJob( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_MOBILE, $url->getKey() ) );

        $result = PageSpeedResult::query()->sole();

        expect( $queueJob->failed )->toBeTrue()
            ->and( $queueJob->isReleased() )->toBeFalse()
            ->and( $result->status )->toBe( PageSpeedResult::STATUS_FAILED )
            ->and( $result->error_message )->toContain( 'PAGESPEED_API_KEY' )
            ->and( $url->fresh()->last_tested_at )->toBeNull();
    } );

    it( 'does not send a request at all', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        Http::fake();

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        Http::assertNothingSent();
    } );

    it( 'still writes exactly one row when the queue runs it for real', function (): void {
        // Worth the round trip through a real connection: the queue calls
        // failed() on a freshly unserialized instance rather than on the
        // object that was running, so a job recording its own failure and
        // then failing itself would write two rows for one run — and nothing
        // driving handle() directly would ever show it.
        config( [
            'pagespeed-insights.api_key' => null,
            'queue.default'              => 'sync',
        ] );

        RunPageSpeedTest::dispatch( 'https://example.com/about' );

        expect( PageSpeedResult::query()->count() )->toBe( 1 )
            ->and( PageSpeedResult::query()->sole()->status )->toBe( PageSpeedResult::STATUS_FAILED );
    } );
} );

describe( 'quota exhaustion on a configured key', function (): void {
    it( 'releases the job with the configured delay instead of failing it', function (): void {
        config( [ 'pagespeed-insights.job.quota_delay' => 900 ] );

        Http::fake( [ '*' => Http::response( psiFixture( 'quota-error' ), 429 ) ] );

        $queueJob = psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        expect( $queueJob->releasedFor )->toBe( 900 )
            ->and( $queueJob->failed )->toBeFalse()
            ->and( PageSpeedResult::query()->count() )->toBe( 0 );
    } );

    it( 'leaves last_tested_at alone so the run is still owed', function (): void {
        Http::fake( [ '*' => Http::response( psiFixture( 'quota-error' ), 429 ) ] );

        $url = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_MOBILE, $url->getKey() ) );

        expect( $url->fresh()->last_tested_at )->toBeNull();
    } );
} );

describe( 'transient failures', function (): void {
    it( 'rethrows so the queue retries, without recording a result yet', function (): void {
        Http::fake( [ '*' => Http::response( [ 'error' => [ 'message' => 'Backend error' ] ], 503 ) ] );

        $job = new RunPageSpeedTest( 'https://example.com/about' );

        expect( fn (): mixed => psiRunJob( $job, 2 ) )->toThrow( PageSpeedApiException::class );

        expect( PageSpeedResult::query()->count() )->toBe( 0 );
    } );

    it( 'records the failure once the queue gives up', function (): void {
        $url = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );
        $job = new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_DESKTOP, $url->getKey() );

        $job->failed( PageSpeedApiException::apiError( 503, 'https://example.com/about', 'Backend error' ) );

        $result = PageSpeedResult::query()->sole();

        expect( $result->status )->toBe( PageSpeedResult::STATUS_FAILED )
            ->and( $result->strategy )->toBe( PageSpeedRequest::STRATEGY_DESKTOP )
            ->and( $result->pagespeed_url_id )->toBe( $url->getKey() )
            ->and( $result->error_message )->toContain( 'Backend error' );
    } );

    it( 'does not count a failure as having tested the URL', function (): void {
        $url = PageSpeedUrl::factory()->neverTested()->create( [ 'url' => 'https://example.com/about' ] );

        ( new RunPageSpeedTest( 'https://example.com/about', PageSpeedRequest::STRATEGY_MOBILE, $url->getKey() ) )
            ->failed( PageSpeedApiException::apiError( 503, 'https://example.com/about', 'Backend error' ) );

        expect( $url->fresh()->last_tested_at )->toBeNull()
            ->and( $url->fresh()->isDue() )->toBeTrue();
    } );

    it( 'records a failure even when the queue names no exception', function (): void {
        ( new RunPageSpeedTest( 'https://example.com/about' ) )->failed( null );

        expect( PageSpeedResult::query()->sole()->error_message )->toContain( 'gave up' );
    } );

    it( 'records the run once, not once per failure path', function (): void {
        config( [ 'pagespeed-insights.api_key' => null ] );

        psiRunJob( new RunPageSpeedTest( 'https://example.com/about' ) );

        expect( PageSpeedResult::query()->count() )->toBe( 1 );
    } );
} );

describe( 'queue configuration', function (): void {
    it( 'reads its timeout, tries, backoff, and queue from config', function (): void {
        config( [
            'pagespeed-insights.job.timeout'      => 240,
            'pagespeed-insights.job.tries'        => 5,
            'pagespeed-insights.job.backoff'      => [ 30, 90 ],
            'pagespeed-insights.queue.connection' => 'redis',
            'pagespeed-insights.queue.queue'      => 'pagespeed',
        ] );

        $job = new RunPageSpeedTest( 'https://example.com/about' );

        expect( $job->timeout )->toBe( 240 )
            ->and( $job->tries )->toBe( 5 )
            ->and( $job->backoff() )->toBe( [ 30, 90 ] )
            ->and( $job->connection )->toBe( 'redis' )
            ->and( $job->queue )->toBe( 'pagespeed' );
    } );

    it( 'falls back to its own defaults when config is unusable', function (): void {
        config( [
            'pagespeed-insights.job.timeout' => 0,
            'pagespeed-insights.job.tries'   => 'many',
            'pagespeed-insights.job.backoff' => [],
            'pagespeed-insights.queue.queue' => '   ',
        ] );

        $job = new RunPageSpeedTest( 'https://example.com/about' );

        expect( $job->timeout )->toBe( RunPageSpeedTest::DEFAULT_TIMEOUT )
            ->and( $job->tries )->toBe( RunPageSpeedTest::DEFAULT_TRIES )
            ->and( $job->backoff() )->toBe( RunPageSpeedTest::DEFAULT_BACKOFF )
            ->and( $job->queue )->toBeNull();
    } );
} );
