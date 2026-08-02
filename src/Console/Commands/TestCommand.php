<?php

/**
 * Synchronous PageSpeed test command with CI score budgets.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\PageSpeedInsights\Console\Commands;

use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedClient;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Contracts\ApiKeyRepository;
use ArtisanPackUI\PageSpeedInsights\Data\LabMetrics;
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Exceptions\MissingApiKeyException;
use ArtisanPackUI\PageSpeedInsights\Exceptions\PageSpeedApiException;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Support\CategoryTranslator;
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs one PageSpeed test and holds the result to a set of budgets.
 *
 * The CI entry point, and the one place in the package that waits out a
 * 20-60 second run rather than queueing it — a pipeline has nowhere to put a
 * queued job and nothing to do while it waits.
 *
 * ### A budget must never pass because the score was missing
 *
 * The rule the rest of this class is arranged around. A budget checked
 * against a score that is null or absent is reported as **unavailable** and
 * exits non-zero, in its own words and its own failure class, because
 * "accessibility is 40" and "accessibility did not come back" are different
 * problems with different fixes. The alternative — treating an absent score
 * as satisfied — produces a green pipeline that is green precisely because
 * the thing it was installed to catch stopped being measured, which is worse
 * than having no budgets at all.
 *
 * ### Exit codes
 *
 * Four classes rather than a single failure code, so a pipeline can branch on
 * them and a developer reading a build log knows immediately which bucket
 * they are in:
 *
 * | Code | Class | Meaning |
 * |---|---|---|
 * | 0 | — | Every budget passed. |
 * | 1 | budget | A budget was violated: a real regression. |
 * | 2 | input | The command was called wrong. |
 * | 3 | configuration | No API key is configured. |
 * | 4 | run | The run failed, a budgeted measurement was unavailable, or `--store` could not save it. |
 *
 * The API key is checked *before* the request rather than after, because
 * without the preflight the CI experience is a 20-60 second wait ending in a
 * raw quota error — which in a pipeline reads as a flaky API rather than as a
 * missing secret.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @since 1.0.0
 */
class TestCommand extends Command
{
    /**
     * A budget was violated. The signal the command exists for.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXIT_BUDGET_VIOLATED = 1;

    /**
     * The command was called with arguments it cannot act on. Matches
     * Symfony's own `Command::INVALID`.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXIT_INVALID_INPUT = 2;

    /**
     * No PageSpeed Insights API key is configured.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXIT_NOT_CONFIGURED = 3;

    /**
     * The run did not deliver what was asked for: it failed outright, it
     * produced no value for something a budget covers, or `--store` could not
     * save it.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXIT_RUN_FAILED = 4;

    /**
     * The category budget flags, mapped to the response key each one reads.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const CATEGORY_BUDGETS = [
        'min-performance'    => 'performance',
        'min-accessibility'  => 'accessibility',
        'min-best-practices' => 'best-practices',
        'min-seo'            => 'seo',
    ];

    /**
     * The lab metric budget flags, mapped to the audit id each one reads.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const METRIC_BUDGETS = [
        'max-lcp-ms' => 'largest-contentful-paint',
        'max-cls'    => 'cumulative-layout-shift',
        'max-tbt-ms' => 'total-blocking-time',
    ];

    /**
     * Display names for the categories this command knows about.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        'performance'    => 'Performance',
        'accessibility'  => 'Accessibility',
        'best-practices' => 'Best Practices',
        'seo'            => 'SEO',
    ];

    /**
     * Display names for the lab metrics.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const METRIC_LABELS = [
        'first-contentful-paint'   => 'First Contentful Paint',
        'largest-contentful-paint' => 'Largest Contentful Paint',
        'total-blocking-time'      => 'Total Blocking Time',
        'cumulative-layout-shift'  => 'Cumulative Layout Shift',
        'speed-index'              => 'Speed Index',
    ];

    /**
     * A budget the run satisfied.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_PASS = 'pass';

    /**
     * A budget the run breached.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_FAIL = 'fail';

    /**
     * A budget that could not be checked because the run carried no value
     * for it. Never treated as a pass.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * The console command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'pagespeed:test
        {url : The absolute http(s) URL to test.}
        {--strategy=mobile : The form factor to test: mobile or desktop.}
        {--categories=all : Categories to request, comma-separated, or "all".}
        {--store : Save the run into result history.}
        {--json : Print machine-readable JSON instead of a table.}
        {--min-performance= : Fail when the performance score is below this (0-100).}
        {--min-accessibility= : Fail when the accessibility score is below this (0-100).}
        {--min-best-practices= : Fail when the best practices score is below this (0-100).}
        {--min-seo= : Fail when the SEO score is below this (0-100).}
        {--max-lcp-ms= : Fail when Largest Contentful Paint exceeds this many milliseconds.}
        {--max-cls= : Fail when Cumulative Layout Shift exceeds this value.}
        {--max-tbt-ms= : Fail when Total Blocking Time exceeds this many milliseconds.}';

    /**
     * The console command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Test one URL now and hold the result to CI score budgets.';

    /**
     * The extended help, which is where the exit codes are documented.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $help = <<<'HELP'
        Runs one PageSpeed test synchronously and exits non-zero when a budget is not met.

        Exit codes:
          0  Every budget passed.
          1  A budget was violated.
          2  The command was called with unusable arguments.
          3  No PageSpeed Insights API key is configured.
          4  The run failed, a budgeted score or metric was unavailable, or the
             result could not be stored.

        A budget checked against a missing score or metric never passes: it is reported
        as unavailable and exits 4, because a score that stopped being measured and a
        score that fell below its budget need different fixes.
        HELP;

    /**
     * Whether output is machine-readable, in which case nothing but the JSON
     * document may reach stdout.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $asJson = false;

    /**
     * The URL under test, kept so a failure report can name it.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected string $target = '';

    /**
     * The categories that were requested.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected array $requested = [];

    /**
     * Why `--store` did not store, when it was asked to and could not.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    protected ?string $storeError = null;

    /**
     * Run the command.
     *
     * @since 1.0.0
     *
     * @param  PageSpeedClient  $client  The API client.
     * @param  ApiKeyRepository  $apiKeys  The configured API key storage driver.
     * @param  UrlRegistry  $registry  Links a stored run to its monitored row, when there is one.
     * @param  LoggerInterface  $logger  Where diagnostics that outlive the terminal go.
     *
     * @return int The process exit code.
     */
    public function handle( PageSpeedClient $client, ApiKeyRepository $apiKeys, UrlRegistry $registry, LoggerInterface $logger ): int
    {
        $this->asJson = true === $this->option( 'json' );
        $this->target = trim( (string) $this->argument( 'url' ) );

        try {
            $this->requested = $this->requestedCategories();
            $budgets         = $this->requestedBudgets( $this->requested );
            $request         = new PageSpeedRequest( $this->target, (string) $this->option( 'strategy' ), $this->requested );
        } catch ( InvalidArgumentException $exception ) {
            return $this->reportFailure( 'input', $exception->getMessage(), self::EXIT_INVALID_INPUT );
        }

        // Before the request, not after: a pipeline that waits a minute for a
        // raw 429 reads that as a flaky API rather than as a missing secret.
        if ( ! $this->hasCredentials( $apiKeys, $logger ) ) {
            return $this->reportFailure(
                'configuration',
                __( 'No PageSpeed Insights API key is configured, so :url was not tested. Create a key in the Google Cloud Console with the PageSpeed Insights API enabled, then set PAGESPEED_API_KEY. There is no working keyless mode.', [ 'url' => $this->target ] ),
                self::EXIT_NOT_CONFIGURED,
            );
        }

        try {
            $result = $client->run( $request );
        } catch ( MissingApiKeyException $exception ) {
            return $this->reportFailure( 'configuration', $exception->getMessage(), self::EXIT_NOT_CONFIGURED );
        } catch ( PageSpeedApiException $exception ) {
            return $this->reportFailure( 'run', $exception->getMessage(), self::EXIT_RUN_FAILED, $exception->lighthouseErrorCode );
        }

        $checks = $this->evaluate( $budgets, $result );

        return $this->report( $result, $checks, $this->store( $result, $registry, $logger ) );
    }

    /**
     * The categories to request.
     *
     * @since 1.0.0
     *
     * @throws InvalidArgumentException When the option names nothing usable.
     *
     * @return array<int, string> Response-key category spellings.
     */
    protected function requestedCategories(): array
    {
        $option = trim( (string) $this->option( 'categories' ) );

        if ( '' === $option || 'all' === strtolower( $option ) ) {
            return CategoryTranslator::KNOWN;
        }

        $categories = [];

        foreach ( explode( ',', $option ) as $category ) {
            $key = CategoryTranslator::toResponseKey( $category );

            if ( '' !== $key && ! in_array( $key, $categories, true ) ) {
                $categories[] = $key;
            }
        }

        if ( [] === $categories ) {
            throw new InvalidArgumentException( __(
                '--categories named no categories. Pass a comma-separated list such as "performance,seo", or "all".',
            ) );
        }

        return $categories;
    }

    /**
     * The budgets the invocation asked for.
     *
     * A budget on a category that was not requested is refused here rather
     * than reported as unavailable after a minute of waiting: it could never
     * have been checked, and that is a typo rather than a regression.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $categories  The categories being requested.
     *
     * @throws InvalidArgumentException When a flag is unusable or uncheckable.
     *
     * @return array<int, array{flag: string, type: string, key: string, label: string, target: float}> The budgets.
     */
    protected function requestedBudgets( array $categories ): array
    {
        $budgets = [];

        foreach ( self::CATEGORY_BUDGETS as $flag => $category ) {
            $target = $this->numericOption( $flag, 0.0, 100.0 );

            if ( null === $target ) {
                continue;
            }

            if ( ! in_array( $category, $categories, true ) ) {
                throw new InvalidArgumentException( __(
                    '--:flag cannot be checked because the ":category" category was not requested. Add it to --categories, or drop the budget.',
                    [ 'flag' => $flag, 'category' => $category ],
                ) );
            }

            $budgets[] = [
                'flag'   => $flag,
                'type'   => 'score',
                'key'    => $category,
                'label'  => self::CATEGORY_LABELS[ $category ] ?? $category,
                'target' => $target,
            ];
        }

        foreach ( self::METRIC_BUDGETS as $flag => $auditId ) {
            $target = $this->numericOption( $flag, 0.0, INF );

            if ( null === $target ) {
                continue;
            }

            // Lab metrics only come back with the performance category, so a
            // metric budget without it is uncheckable for the same reason a
            // category budget on an unrequested category is.
            if ( ! in_array( 'performance', $categories, true ) ) {
                throw new InvalidArgumentException( __(
                    '--:flag cannot be checked because the "performance" category was not requested. Lab metrics only come back with it.',
                    [ 'flag' => $flag ],
                ) );
            }

            $budgets[] = [
                'flag'   => $flag,
                'type'   => 'metric',
                'key'    => $auditId,
                'label'  => self::METRIC_LABELS[ $auditId ] ?? $auditId,
                'target' => $target,
            ];
        }

        return $budgets;
    }

    /**
     * Read one numeric option.
     *
     * @since 1.0.0
     *
     * @param  string  $flag  The option name.
     * @param  float  $min  The lowest value the flag accepts.
     * @param  float  $max  The highest value the flag accepts.
     *
     * @throws InvalidArgumentException When the value is not a number in range.
     *
     * @return float|null The value, or null when the flag was not passed.
     */
    protected function numericOption( string $flag, float $min, float $max ): ?float
    {
        $value = $this->option( $flag );

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }

        $value = trim( $value );

        if ( ! is_numeric( $value ) ) {
            throw new InvalidArgumentException( __(
                '--:flag needs a number; got ":value".',
                [ 'flag' => $flag, 'value' => $value ],
            ) );
        }

        $number = (float) $value;

        if ( $number < $min || $number > $max ) {
            throw new InvalidArgumentException( INF === $max
                ? __( '--:flag cannot be negative; got ":value".', [ 'flag' => $flag, 'value' => $value ] )
                : __(
                    '--:flag must be between :min and :max; got ":value".',
                    [ 'flag' => $flag, 'min' => $this->number( $min ), 'max' => $this->number( $max ), 'value' => $value ],
                ) );
        }

        return $number;
    }

    /**
     * Whether a key is configured, without letting a broken driver look like
     * an unrelated crash.
     *
     * @since 1.0.0
     *
     * @param  ApiKeyRepository  $apiKeys  The configured driver.
     * @param  LoggerInterface  $logger  Where the driver failure is recorded.
     *
     * @return bool True when a key is available.
     */
    protected function hasCredentials( ApiKeyRepository $apiKeys, LoggerInterface $logger ): bool
    {
        try {
            return $apiKeys->isConfigured();
        } catch ( Throwable $exception ) {
            $logger->error(
                'The PageSpeed API key driver could not read its storage, so pagespeed:test did not run.',
                [ 'driver' => $apiKeys::class, 'error' => $exception->getMessage() ],
            );

            return false;
        }
    }

    /**
     * Check each budget against the run.
     *
     * @since 1.0.0
     *
     * @param  array<int, array{flag: string, type: string, key: string, label: string, target: float}>  $budgets  The budgets.
     * @param  TestResult  $result  The completed run.
     *
     * @return array<int, array{flag: string, type: string, key: string, label: string, target: float, actual: float|null, status: string, message: string|null}> The checks.
     */
    protected function evaluate( array $budgets, TestResult $result ): array
    {
        $checks = [];

        foreach ( $budgets as $budget ) {
            $actual = 'score' === $budget[ 'type' ]
                ? $result->scores->get( $budget[ 'key' ] )
                : $result->labMetrics->value( $budget[ 'key' ] );

            if ( null === $actual ) {
                $checks[] = $budget + [
                    'actual'  => null,
                    'status'  => self::STATUS_UNAVAILABLE,
                    'message' => __(
                        'The run carried no :label value, so the --:flag budget could not be checked. This is a missing measurement, not a score below budget.',
                        [ 'label' => $budget[ 'label' ], 'flag' => $budget[ 'flag' ] ],
                    ),
                ];

                continue;
            }

            $actual = (float) $actual;
            $passed = 'score' === $budget[ 'type' ]
                ? $actual >= $budget[ 'target' ]
                : $actual <= $budget[ 'target' ];

            $checks[] = $budget + [
                'actual'  => $actual,
                'status'  => $passed ? self::STATUS_PASS : self::STATUS_FAIL,
                'message' => $passed ? null : __(
                    ':label is :actual, which is :comparison the --:flag budget of :target.',
                    [
                        'label'      => $budget[ 'label' ],
                        'actual'     => $this->number( $actual ),
                        'comparison' => 'score' === $budget[ 'type' ] ? __( 'below' ) : __( 'above' ),
                        'flag'       => $budget[ 'flag' ],
                        'target'     => $this->number( $budget[ 'target' ] ),
                    ],
                ),
            ];
        }

        return $checks;
    }

    /**
     * Save the run when the invocation asked for it.
     *
     * An ad hoc run has a null `pagespeed_url_id` unless the URL happens to be
     * monitored, in which case it joins that URL's history. Nothing else the
     * scheduled path does happens here: no hooks fire and no regression alert
     * is raised, because a CI run's audience is the pipeline that invoked it
     * and a branch build must not page the team.
     *
     * A write that fails is caught rather than allowed to bubble out of the
     * command, because an uncaught exception exits 1 — the code that means a
     * violated budget. A build failing for a reason it names wrongly is the
     * one outcome a command built around distinct exit codes cannot afford.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The completed run.
     * @param  UrlRegistry  $registry  Resolves the URL to a monitored row.
     * @param  LoggerInterface  $logger  Where the write failure is recorded.
     *
     * @return int|null The stored row id, or null when nothing was stored.
     */
    protected function store( TestResult $result, UrlRegistry $registry, LoggerInterface $logger ): ?int
    {
        if ( true !== $this->option( 'store' ) ) {
            return null;
        }

        try {
            $row = PageSpeedResult::fromTestResult( $result, $registry->findStored( $result->url ) );
            $row->save();

            return (int) $row->getKey();
        } catch ( Throwable $exception ) {
            $logger->error(
                'A pagespeed:test run completed but could not be stored.',
                [ 'url' => $result->url, 'strategy' => $result->strategy, 'error' => $exception->getMessage() ],
            );

            // Trimmed rather than printed whole: a database exception carries
            // the statement and its bindings, which for this table means the
            // entire result payload landing in a build log that is often
            // public. The log line above kept the original.
            $this->storeError = __(
                'The run completed but --store could not save it (:exception): :error Check that the package migrations have run; the full error was logged.',
                [
                    'exception' => $exception::class,
                    'error'     => $this->truncate( $exception->getMessage(), 200 ),
                ],
            );

            return null;
        }
    }

    /**
     * Print the run and return the exit code it earns.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The completed run.
     * @param  array<int, array<string, mixed>>  $checks  The evaluated budgets.
     * @param  int|null  $storedId  The stored row id, when the run was saved.
     *
     * @return int The process exit code.
     */
    protected function report( TestResult $result, array $checks, ?int $storedId ): int
    {
        $unavailable = $this->withStatus( $checks, self::STATUS_UNAVAILABLE );
        $failed      = $this->withStatus( $checks, self::STATUS_FAIL );

        // An unavailable measurement outranks a violated budget because it
        // says the run itself cannot be trusted, and a developer sent to look
        // at a regression that may not exist is a developer sent to the wrong
        // place. Both are still listed.
        $exitCode = match ( true ) {
            [] !== $unavailable        => self::EXIT_RUN_FAILED,
            null !== $this->storeError => self::EXIT_RUN_FAILED,
            [] !== $failed             => self::EXIT_BUDGET_VIOLATED,
            default                    => self::SUCCESS,
        };

        $failureClass = match ( $exitCode ) {
            self::EXIT_RUN_FAILED      => 'run',
            self::EXIT_BUDGET_VIOLATED => 'budget',
            default                    => null,
        };

        if ( $this->asJson ) {
            $this->writeJson( [
                'url'                => $result->url,
                'final_url'          => $result->finalUrl,
                'strategy'           => $result->strategy,
                'categories'         => $this->requested,
                'lighthouse_version' => $result->lighthouseVersion,
                'analyzed_at'        => $result->analyzedAt?->toIso8601String(),
                'status'             => self::SUCCESS === $exitCode ? 'passed' : 'failed',
                'failure_class'      => $failureClass,
                'exit_code'          => $exitCode,
                'scores'             => $result->scores->all(),
                'metrics'            => $this->metricPayload( $result ),
                'budgets'            => $this->budgetPayload( $checks ),
                'warnings'           => $result->warningList(),
                'stored_result_id'   => $storedId,
                'error'              => $this->storeError,
            ] );

            return $exitCode;
        }

        $this->renderTables( $result, $checks );
        $this->renderWarnings( $result );

        foreach ( array_merge( $unavailable, $failed ) as $check ) {
            $this->components->error( (string) $check[ 'message' ] );
        }

        if ( null !== $this->storeError ) {
            $this->components->error( $this->storeError );
        }

        if ( null !== $storedId ) {
            $this->components->info( __( 'Stored this run as result #:id.', [ 'id' => (string) $storedId ] ) );
        }

        if ( self::SUCCESS === $exitCode ) {
            $this->components->info( [] === $checks
                ? __( 'The run completed. No budgets were set, so nothing was checked.' )
                : __( 'All :count budget(s) passed.', [ 'count' => (string) count( $checks ) ] ) );
        }

        return $exitCode;
    }

    /**
     * Print the scores and metrics.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The completed run.
     * @param  array<int, array<string, mixed>>  $checks  The evaluated budgets.
     *
     * @return void
     */
    protected function renderTables( TestResult $result, array $checks ): void
    {
        $this->components->info( __(
            'PageSpeed results for :url (:strategy).',
            [ 'url' => $result->url, 'strategy' => $result->strategy ],
        ) );

        $categories = array_values( array_unique( array_merge( $this->requested, array_keys( $result->scores->all() ) ) ) );
        $scoreRows  = [];

        foreach ( $categories as $category ) {
            $score = $result->scores->get( $category );
            $check = $this->checkFor( $checks, $category );

            $scoreRows[] = [
                self::CATEGORY_LABELS[ $category ] ?? $category,
                null === $score ? __( 'unavailable' ) : (string) $score,
                null === $check ? '-' : '>= ' . $this->number( (float) $check[ 'target' ] ),
                $this->statusLabel( $check ),
            ];
        }

        $this->table( [ __( 'Category' ), __( 'Score' ), __( 'Budget' ), __( 'Status' ) ], $scoreRows );

        $metricRows = [];

        foreach ( LabMetrics::AUDIT_IDS as $auditId ) {
            $value = $result->labMetrics->value( $auditId );
            $check = $this->checkFor( $checks, $auditId );

            $metricRows[] = [
                self::METRIC_LABELS[ $auditId ] ?? $auditId,
                $result->labMetrics->display( $auditId ) ?? ( null === $value ? __( 'unavailable' ) : $this->number( $value ) ),
                null === $check ? '-' : '<= ' . $this->number( (float) $check[ 'target' ] ),
                $this->statusLabel( $check ),
            ];
        }

        $this->table( [ __( 'Metric' ), __( 'Value' ), __( 'Budget' ), __( 'Status' ) ], $metricRows );
    }

    /**
     * Print what the run tolerated, so a degraded run and a clean one do not
     * look the same on stdout.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The completed run.
     *
     * @return void
     */
    protected function renderWarnings( TestResult $result ): void
    {
        foreach ( $result->warningList() as $warning ) {
            $this->components->warn( $warning );
        }
    }

    /**
     * Print a machine-readable document and nothing else.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload  The document.
     *
     * @return void
     */
    protected function writeJson( array $payload ): void
    {
        $this->output->writeln(
            (string) json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * Report a failure that stopped the run from being evaluated at all.
     *
     * @since 1.0.0
     *
     * @param  string  $class  The failure class: input, configuration, or run.
     * @param  string  $message  What went wrong, written for a human.
     * @param  int  $exitCode  The exit code for that class.
     * @param  string|null  $lighthouseErrorCode  Lighthouse's own error code, when it had one.
     *
     * @return int The process exit code.
     */
    protected function reportFailure( string $class, string $message, int $exitCode, ?string $lighthouseErrorCode = null ): int
    {
        if ( $this->asJson ) {
            $this->writeJson( [
                'url'                    => $this->target,
                'strategy'               => trim( (string) $this->option( 'strategy' ) ),
                'categories'             => $this->requested,
                'status'                 => 'failed',
                'failure_class'          => $class,
                'exit_code'              => $exitCode,
                'scores'                 => null,
                'metrics'                => null,
                'budgets'                => [],
                'warnings'               => [],
                'stored_result_id'       => null,
                'lighthouse_error_code'  => $lighthouseErrorCode,
                'error'                  => $message,
            ] );

            return $exitCode;
        }

        $this->components->error( $message );

        return $exitCode;
    }

    /**
     * The metrics, keyed by audit id, for the JSON document.
     *
     * @since 1.0.0
     *
     * @param  TestResult  $result  The completed run.
     *
     * @return array<string, array{value: float|null, display: string|null}> The metrics.
     */
    protected function metricPayload( TestResult $result ): array
    {
        $metrics = [];

        foreach ( LabMetrics::AUDIT_IDS as $auditId ) {
            $metrics[ $auditId ] = [
                'value'   => $result->labMetrics->value( $auditId ),
                'display' => $result->labMetrics->display( $auditId ),
            ];
        }

        return $metrics;
    }

    /**
     * The budgets, for the JSON document.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $checks  The evaluated budgets.
     *
     * @return array<int, array<string, mixed>> The serializable checks.
     */
    protected function budgetPayload( array $checks ): array
    {
        return array_map(
            static fn ( array $check ): array => [
                'flag'    => $check[ 'flag' ],
                'type'    => $check[ 'type' ],
                'key'     => $check[ 'key' ],
                'target'  => $check[ 'target' ],
                'actual'  => $check[ 'actual' ],
                'status'  => $check[ 'status' ],
                'message' => $check[ 'message' ],
            ],
            $checks,
        );
    }

    /**
     * The checks with one status.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $checks  The evaluated budgets.
     * @param  string  $status  The status to keep.
     *
     * @return array<int, array<string, mixed>> The matching checks.
     */
    protected function withStatus( array $checks, string $status ): array
    {
        return array_values( array_filter(
            $checks,
            static fn ( array $check ): bool => $status === $check[ 'status' ],
        ) );
    }

    /**
     * The check covering one score or metric, if a budget named it.
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>>  $checks  The evaluated budgets.
     * @param  string  $key  A category response key or a lab metric audit id.
     *
     * @return array<string, mixed>|null The check, or null when nothing budgeted it.
     */
    protected function checkFor( array $checks, string $key ): ?array
    {
        foreach ( $checks as $check ) {
            if ( $key === $check[ 'key' ] ) {
                return $check;
            }
        }

        return null;
    }

    /**
     * The table cell for a check's status.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>|null  $check  The check, or null when nothing budgeted the row.
     *
     * @return string The cell.
     */
    protected function statusLabel( ?array $check ): string
    {
        return match ( $check[ 'status' ] ?? null ) {
            self::STATUS_PASS        => __( 'PASS' ),
            self::STATUS_FAIL        => __( 'FAIL' ),
            self::STATUS_UNAVAILABLE => __( 'UNAVAILABLE' ),
            default                  => '-',
        };
    }

    /**
     * Shorten a message that was written for a log rather than for a terminal.
     *
     * @since 1.0.0
     *
     * @param  string  $message  The message.
     * @param  int  $length  The most characters to keep.
     *
     * @return string The shortened message.
     */
    protected function truncate( string $message, int $length ): string
    {
        $message = trim( $message );

        if ( mb_strlen( $message ) <= $length ) {
            return $message;
        }

        return mb_substr( $message, 0, $length ) . '…';
    }

    /**
     * Format a number without the trailing zeroes a float prints with.
     *
     * @since 1.0.0
     *
     * @param  float  $value  The number.
     *
     * @return string The formatted number.
     */
    protected function number( float $value ): string
    {
        if ( $value === floor( $value ) && abs( $value ) < 1.0e+15 ) {
            return (string) (int) $value;
        }

        return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
    }
}
