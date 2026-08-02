# ArtisanPack UI PageSpeed Insights

Google PageSpeed Insights testing, score history, and drop-in UI components for the ArtisanPack UI ecosystem.

> **Status: in development.** The package is scaffolded and boots, but the API client, data model, and UI surfaces are still being built. This README is a placeholder and will be replaced with full documentation before the 1.0.0 release.

## What it will do

Google's PageSpeed Insights UI tells you how a page performs *right now*. This package adds the part it leaves out: **history**. Scheduled, queued re-tests build a per-URL score timeline, so performance regressions show up as a visible trend — and as an alert — instead of as a hunch.

Planned for 1.0.0:

- Scheduled PSI runs against a managed list of URLs, with full result history
- Lighthouse category scores, lab metrics, and CrUX field data stored per URL and strategy
- Livewire, React, and Vue components, plus CMS-framework admin widgets
- Regression alerts through standard Laravel notifications
- A CI-friendly `pagespeed:test` command with score budgets and non-zero exit codes
- Hooks so other ArtisanPack UI packages can register URLs and consume results

## Requirements

- PHP **8.2+**
- Laravel **10, 11, 12, or 13**
- A **PageSpeed Insights API key** — see [API key](#api-key)
- [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google) **^1.0** — installed automatically; a connected Google account is optional and used only as an auth fallback
- **Livewire ^3.6** *(optional)* — required only for the Blade / Livewire components and the CMS-framework admin widget bridge

## Installation

```bash
composer require artisanpack-ui/pagespeed-insights
```

The service provider and the `PageSpeedInsights` facade are auto-discovered by Laravel.

## API key

The PageSpeed Insights API is quota-limited per project. Keyless requests are **not** a workable fallback — the shared anonymous project currently has a daily quota of zero — so an API key is effectively required.

### Creating one

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and sign in.
2. Pick an existing project from the project selector, or create a new one (**New project** → name it → **Create**).
3. Enable the API for that project: go to [PageSpeed Insights API](https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com) in the API Library and click **Enable**. The key will not work until this is done, even if the key itself exists.
4. Go to **APIs & Services → Credentials** ([direct link](https://console.cloud.google.com/apis/credentials)) and click **Create credentials → API key**. Copy the key it shows you.
5. Click **Edit API key** and, under **API restrictions**, choose **Restrict key** and select **PageSpeed Insights API**. Leave **Application restrictions** set to **None** — the requests come from your server, not a browser, and an HTTP-referrer restriction would reject them.
6. Add it to your environment:

```dotenv
PAGESPEED_API_KEY=your-key-here
```

Billing is not required. Google does not publish the quota numbers for keyed projects anywhere; check **APIs & Services → PageSpeed Insights API → Quotas** in the Console for the limits that actually apply to yours.

The key is sent on the `X-goog-api-key` header rather than as a `key=` query parameter, so it never appears in a URL — a URL-borne key ends up in connection-error messages, exception reports, and proxy logs.

### Where the key is stored

`PAGESPEED_CONFIG_DRIVER` selects the storage driver, mirroring `GOOGLE_CONFIG_DRIVER` in [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google):

| Driver | Storage | Writable |
|---|---|---|
| `config` *(default)* | `PAGESPEED_API_KEY` / `config( 'pagespeed-insights.api_key' )` | No — managed through config/env |
| `database` | `pagespeed_configurations` table, encrypted at rest | Yes |
| `cms` | CMS framework Settings module, encrypted at rest | Yes — requires `artisanpack-ui/cms-framework` |

Publish the config file and, for the `database` driver, run the migrations:

```bash
php artisan vendor:publish --tag=pagespeed-insights-config
php artisan migrate
```

The key is reachable through `PageSpeedInsights::config()`, which returns the `ApiKeyRepository` for the active driver:

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

PageSpeedInsights::config()->isConfigured();    // false means PSI cannot run at all
PageSpeedInsights::config()->getApiKey();
PageSpeedInsights::config()->save( 'new-key' ); // database and cms drivers only
```

See [`docs/psi-api-reference.md`](docs/psi-api-reference.md) for the verified API behaviour the implementation is built against.

## Running a test

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

$result = PageSpeedInsights::test( 'https://example.com/', 'mobile' );

$result->scores->performance();                    // 0-100, or null when unscored
$result->scores->bestPractices();
$result->labMetrics->largestContentfulPaint();     // milliseconds, or null
$result->effectiveFieldData()?->overallCategory(); // FAST / AVERAGE / SLOW from CrUX
$result->opportunities;                            // pruned, sorted by estimated saving
```

A run takes 20–60 seconds and sometimes longer, so call it from a queued job or a console command, never from a web request.

### Failure modes

Three exception types, because at the HTTP layer these look alike and each needs a different response:

| Exception | Cause | What to do |
|---|---|---|
| `MissingApiKeyException` | No API key configured | Configure one. `isRetryable()` is false — retrying never helps. |
| `QuotaExceededException` | A keyed project is out of quota | Back off and try later. |
| `PageSpeedApiException` | Transport failure, API error, or Lighthouse's own `runtimeError` | Usually retryable. |

Note that an unkeyed request and an exhausted project both return HTTP 429, so the client checks whether a key was configured before classifying one — the first is a configuration problem, not quota exhaustion.

### Thin results

The parser tolerates unknown Lighthouse categories, absent categories, missing lab metrics, and missing CrUX data rather than failing the run — but it never does so silently. Every skip is logged with the key it skipped, and carried on the result:

```php
$result->hasWarnings();
$result->warnings();               // run_warnings, unrecognized_categories, missing_categories, missing_metrics, missing_field_data
$result->scores->unrecognized();   // e.g. ['agentic-browsing'] after a Lighthouse release
```

Consumers can reshape the pruned opportunity list before it is returned:

```php
addFilter( 'ap.pageSpeed.opportunities', function ( array $opportunities, PageSpeedRequest $request ): array {
    return array_filter( $opportunities, fn ( $o ) => 'redirects' !== $o->id );
} );
```

## Monitored URLs

`UrlRegistry` is the single answer to which URLs this installation monitors. It merges the rows in `pagespeed_urls` with whatever other packages contribute through a hook, and deduplicates the two by canonical URL.

```php
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;

$registry = app( UrlRegistry::class );

$registry->all();       // stored rows + hook contributions, deduped
$registry->active();    // the same, filtered to URLs still being tested
$registry->stored();    // rows only
$registry->find( 'https://example.com/about/' );  // matches however it is spelled
```

### CRUD

```php
$url = $registry->add( 'https://example.com/pricing', [ 'label' => 'Pricing' ] );

$registry->update( $url, [ 'test_frequency' => 'daily', 'strategies' => [ 'mobile' ] ] );
$registry->deactivate( $url );   // pause testing, keep the history
$registry->activate( $url );
$registry->delete( $url );
```

`add()` is idempotent — adding a URL that is already stored updates the attributes you passed and leaves the rest, and its score history, alone.

Every URL is stored in a canonical form, so `https://example.com/about`, `https://example.com/about/`, and `HTTPS://Example.com/about#team` are one monitored page rather than three histories of the same page. Scheme and host case, the default port, the fragment, and a trailing slash on a non-root path are erased; query strings and `www.` are not, because either can change which document is served. Anything that is not an http(s) URL, or is longer than the 500-character column, is refused: `add()` returns `null` rather than storing something untestable.

Embedded credentials (`https://user:pass@example.com/`) are stripped rather than stored. PageSpeed fetches the page from Google's own infrastructure, where userinfo in a URL is not honoured, so keeping them would write a password in plaintext to the database and to every screen that lists monitored URLs without making a protected staging site testable.

### Registering URLs from another package

```php
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;

addFilter( UrlRegistry::FILTER_REGISTER_URLS, function ( array $urls ): array {
    return [
        ...$urls,
        'https://example.com/checkout',
        [ 'url' => 'https://example.com/cart', 'label' => 'Cart', 'strategies' => [ 'mobile' ] ],
    ];
} );
```

The hook name is `ap.pageSpeed.registerUrls`. Entries may be a URL string, an attribute array carrying at least a `url` key, or a `PageSpeedUrl` instance; `source` is always recorded as `hook` regardless of what the entry says.

Hook URLs are returned as **unsaved** models. A package that registers `/checkout` and is later uninstalled should stop contributing that URL, not leave behind a row nobody remembers adding — so they gain no history and do not appear in the management UI until something calls `$registry->persistHookUrls()`. Where a hook and a stored row name the same page, the stored row wins: it is the one with history, a label somebody wrote, and an `is_active` flag somebody chose.

An entry that cannot be used — a `mailto:` URL, a blank string, an array with no `url` key — is dropped and logged rather than throwing, so one broken callback in an unrelated package cannot take down the monitored set.

### Sitemap discovery

```bash
php artisan pagespeed:discover-sitemap
php artisan pagespeed:discover-sitemap --sitemap=https://example.com/sitemap_index.xml --limit=200
php artisan pagespeed:discover-sitemap --activate
```

The command reads the site's sitemap, follows sitemap index files, and stores what it finds as `source=sitemap`. **Discovered URLs are inactive unless you pass `--activate`.** A 500-page sitemap activated in one command is 1,000 API requests per cycle against a quota you have not looked at yet; reviewing the list and turning on the pages that matter costs far less than discovering a burned quota.

Configure the defaults under `pagespeed-insights.sitemap`:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `url` | `PAGESPEED_SITEMAP_URL` | null | The sitemap to read. Null means `sitemap.xml` at `app.url`. |
| `limit` | `PAGESPEED_SITEMAP_LIMIT` | 50 | The most URLs one run will discover. |
| `timeout` | `PAGESPEED_SITEMAP_TIMEOUT` | 15 | Seconds per sitemap document. An index file means one run fetches several. |

Parsing is tolerant of what real sitemaps look like: a truncated document still yields the entries above the damage, a child sitemap that 404s is skipped rather than aborting its siblings, and namespace prefixes parse the same as the default namespace. The sitemap you actually named is the exception — if that one cannot be fetched or parsed, the command fails with the reason rather than reporting zero URLs found.

A response that parses but is not rooted at `<urlset>` or `<sitemapindex>` is rejected by name. This matters because recovery-mode parsing happily reads an HTML error page, and a custom 404 served with a 200 status is common — without the check, a wrong address would report "0 URLs found" rather than saying what it actually got.

A sitemap index may only point at sitemaps on its own host; a child on a different host is skipped and logged. Redirects are followed for the sitemap you name — so `example.com/sitemap.xml` redirecting to `www.example.com/sitemap.xml` works — but never for a sitemap that a document pointed at, since the host check runs before the request and a redirect would step around it. This is what the sitemaps.org protocol requires, and it keeps a sitemap from becoming a list of addresses your application will fetch on the author's behalf — loopback services and cloud metadata endpoints included. Page URLs on other hosts are still discovered, because those are fetched by Google rather than by your server.

`SitemapDiscoverer` is available directly when you need the list without storing it:

```php
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;

$found = app( SitemapDiscoverer::class )->discover( 'https://example.com/sitemap.xml', 100 );
```

## Scheduled testing

Monitored URLs are tested by a queued job, dispatched by a scheduler the package registers for you:

```bash
php artisan pagespeed:monitor                                   # queue everything that is due
php artisan pagespeed:monitor --url=https://example.com/about   # queue one URL now, due or not
```

Make sure a queue worker is running, and that Laravel's own scheduler is wired up (`php artisan schedule:work`, or the usual cron entry). The package registers `pagespeed:monitor` **hourly** — that is how often it *looks*, not how often it tests. A URL only comes due once its own `test_frequency` has elapsed, and `hourly` is itself a supported cadence, so anything less frequent would make it unreachable.

The hourly task is guarded with `withoutOverlapping()`, so two cycles never run at once. It does not, however, know what is already sitting in the queue: a URL stays due until a run actually completes, so if your worker is far enough behind that a cycle's jobs have not drained within the hour, the next cycle will queue that URL again. At the default budget of 30 requests a minute this needs a very large monitored set to reach — but if you are near it, give PageSpeed its own queue and worker.

You can queue a run yourself, for a URL that is not monitored at all if you like:

```php
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;

RunPageSpeedTest::dispatch( 'https://example.com/about', 'mobile' );
```

### What the job does with a failure

| Cause | Behaviour |
|---|---|
| No API key | **Never retried.** One `failed` result row, written immediately with the fix in `error_message`, logged at error level. |
| Quota exhausted on a configured key | **Released** with a long delay rather than consuming a retry. No result row; the URL stays owed a run. |
| 5xx, transport failure, Lighthouse runtime error | **Retried** with backoff, then recorded as a `failed` row once the attempts are exhausted. |

The first row of that table is the one that matters most. Keyless PageSpeed answers HTTP 429 — the same status as genuine quota exhaustion — because Google's shared anonymous project has a daily quota of zero. If the job treated the two alike, an application that simply never set `PAGESPEED_API_KEY` would release and retry forever, silently, and present as a broken queue rather than as a one-line configuration fix. For the same reason `TestScheduler` and `pagespeed:monitor` refuse a whole cycle when no key is configured, with one loud error, instead of queueing N URLs × 2 form factors of jobs that each fail identically.

A run that exhausts its retries always writes its `failed` row. Without that, a monitored URL would quietly stop producing history, and anything comparing runs over time would have nothing to compare and report nothing wrong.

Only a run that produced a measurement updates `last_tested_at`. A failed run does not, because that column drives due-ness and letting a failure satisfy the cadence would make a URL that has stopped being testable read as freshly tested. The trade-off is deliberate: a permanently broken URL is re-queued on every cycle, writing a failed row each time, until you fix it or pause it.

### Rate limiting

Every job passes through a middleware that holds the whole application to `pagespeed-insights.rate_limit.per_minute` requests, counted across all URLs and all workers — Google attributes quota to the API key, not to the page being tested. A job over the budget is released back onto the queue, so a large cycle spreads itself out instead of failing.

The default of 30 is conservative on purpose. Google does not publish a PageSpeed Insights rate limit anywhere in its documentation, so the commonly cited figures are folklore rather than fact. Check the quota page for your own project in the Google Cloud Console before raising it. Set it to `0` to turn throttling off.

### Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `queue.connection` | `PAGESPEED_QUEUE_CONNECTION` | null | Connection to dispatch on. Null means the application default. |
| `queue.queue` | `PAGESPEED_QUEUE` | null | Queue name. A dedicated queue is worth considering — one run blocks a worker for 20–60 seconds. |
| `rate_limit.per_minute` | `PAGESPEED_RATE_LIMIT_PER_MINUTE` | 30 | Requests started per minute, application-wide. 0 disables. |
| `job.timeout` | `PAGESPEED_JOB_TIMEOUT` | 120 | Seconds one attempt may run for. |
| `job.tries` | `PAGESPEED_JOB_TRIES` | 3 | Attempts before a transient failure is recorded. |
| `job.backoff` | — | `[60, 300]` | Seconds to wait before each retry. |
| `job.quota_delay` | `PAGESPEED_QUOTA_DELAY` | 1800 | Seconds to postpone a quota-rejected run. |
| `scheduling.enabled` | `PAGESPEED_SCHEDULING_ENABLED` | true | Whether the package registers its own hourly task. |
| `scheduling.persist_hook_urls` | — | true | Whether hook-contributed URLs are saved before a cycle. |

That last one is not really optional. A URL contributed through `ap.pageSpeed.registerUrls` comes back as an unsaved model, which has nowhere to record `last_tested_at` — so it would read as due on every cycle whatever its cadence says, and its results would have no row to hang history from. The scheduler is the one place in the package that calls `UrlRegistry::persistHookUrls()` for you.

### Hooks

```php
// Before a run is sent to Google.
addAction( 'ap.pageSpeed.beforeTest', function ( string $url, string $strategy, ?PageSpeedUrl $monitored ): void {
    // ...
} );

// After a result row is written. Fires for failed rows too, with a null TestResult.
addAction( 'ap.pageSpeed.resultStored', function ( PageSpeedResult $row, ?TestResult $result ): void {
    // ...
} );
```

## Development

```bash
composer install
composer test    # Pest
composer lint    # PHP-CS-Fixer (dry run) + PHPCS
composer fix     # PHP-CS-Fixer, applied
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).
