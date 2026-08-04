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

## Testing in CI

`pagespeed:test` runs one test synchronously and exits non-zero when the result misses a budget. It is the one command in the package that waits out a run, because a pipeline has nowhere to put a queued job and nothing to do while it waits.

```bash
php artisan pagespeed:test https://example.com/
php artisan pagespeed:test https://example.com/ --strategy=desktop --categories=performance,seo
php artisan pagespeed:test https://example.com/ --min-performance=90 --max-lcp-ms=2500
php artisan pagespeed:test https://example.com/ --min-performance=90 --json
php artisan pagespeed:test https://example.com/ --store
```

| Flag | Fails when |
|---|---|
| `--min-performance=` | The performance score is below it |
| `--min-accessibility=` | The accessibility score is below it |
| `--min-best-practices=` | The best practices score is below it |
| `--min-seo=` | The SEO score is below it |
| `--max-lcp-ms=` | Largest Contentful Paint is above it |
| `--max-cls=` | Cumulative Layout Shift is above it |
| `--max-tbt-ms=` | Total Blocking Time is above it |

`--categories` defaults to `all`, and a budget on a category that was not requested is refused before the request goes out rather than reported as unavailable a minute later — it could never have been checked, so it is a typo rather than a regression. Metric budgets need the `performance` category, since lab metrics only come back with it.

`--store` saves the run into result history. An ad hoc result has a null `pagespeed_url_id` unless the URL is already monitored, in which case it joins that URL's history. Nothing else the scheduled path does happens: no hooks fire and no regression alert is raised, because a CI run's audience is the pipeline that invoked it and a branch build must not page the team.

### Exit codes

| Code | Class | Meaning |
|---|---|---|
| `0` | — | Every budget passed |
| `1` | `budget` | A budget was violated — the signal the command exists for |
| `2` | `input` | The command was called with unusable arguments |
| `3` | `configuration` | No API key is configured |
| `4` | `run` | The run failed, a budgeted measurement was unavailable, or `--store` could not save it |

The API key is checked **before** the request. Without that preflight the CI experience is a 20–60 second wait ending in a raw 429, which in a build log reads as a flaky API rather than as a missing secret.

### A budget never passes on a missing score

A budget checked against a score or metric that is null or absent is reported as **unavailable** and exits `4` — never as a pass, and in different words from a score that fell short, because "accessibility is 40" and "accessibility did not come back" need different fixes. A green pipeline that is green because three categories quietly stopped coming back is worse than having no budgets at all. For the same reason an unavailable measurement outranks a violated budget when both happen: the run itself cannot be trusted, and a developer sent to investigate a regression that may not exist is a developer sent to the wrong place. Both are still listed.

Everything the run tolerated — Lighthouse's own `runWarnings`, absent categories, missing lab metrics, no CrUX data — is printed as warnings and carried in `--json`, so a degraded run and a clean one never look the same on stdout.

### Machine-readable output

`--json` prints one document and nothing else: `status`, `failure_class`, `exit_code`, `scores`, `metrics`, `warnings`, `stored_result_id`, and a `budgets` array carrying each budget's flag, target, actual value, and `pass` / `fail` / `unavailable` verdict. A CI annotation is only as good as what is in the payload, so the failure paths emit the same envelope with an `error` message rather than falling back to plain text.

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
| `scheduling.enabled` | `PAGESPEED_SCHEDULING_ENABLED` | true | Whether the package registers its own scheduled tasks. |
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

// After a stored run is found to have regressed. $regressions is a list of arrays.
addAction( 'ap.pageSpeed.scoreRegressed', function ( PageSpeedResult $row, array $regressions ): void {
    // ...
} );
```

## Alerts

A trend chart nobody opens is a trend chart nobody reads. After each completed run the package compares it with the previous completed run for the same URL and form factor, and turns what got worse into a notification.

Three things count as a regression:

- **A drop.** The score fell by at least `alerts.drop_points` (10) against the previous run.
- **A floor breach.** The score is below `alerts.thresholds.<category>`, whatever the previous run said. Floors are absolute rather than comparative, so they apply to a URL's first run too — a page that has been bad since the day it was added is not less bad for having no history.
- **A score that stopped existing.** The category was scored last run and is null now. This one is worded apart from a drop on purpose: nothing got slower, the page simply stopped being measured for it, and the naive implementation — "no number to subtract, so nothing to say" — is exactly the silence this feature exists to break.

Failed runs are never used as a baseline, because they carry no scores and comparing against one would read every recovery as a hundred-point gain. Runs that completed while losing data are skipped as a baseline too, up to ten runs back, so a clean run following a thin one is not reported as a recovery it never made — set `alerts.skip_degraded` to `false` if you would rather compare against them. A regression detected *on* a degraded run is still reported; the notification says the run was degraded rather than staying quiet about it.

When there is nothing to compare against, that fact is logged at debug level rather than passed over silently, so "why did I not get an alert" is a question the log can answer.

### Digest

One monitoring cycle produces one result per URL per form factor, each inspected in its own queued job. Alerting on each one directly turns a single bad deploy into thirty near-identical emails, which is how an alert becomes something people filter into a folder — so regressions are buffered for `alerts.digest.wait` seconds and sent as one notification. Exactly one flush job is queued per window.

Note that the digest is a *delayed* job, and the `sync` queue driver ignores delays. On a sync queue every regression sends immediately; set `alerts.digest.enabled` to `false` there and mean it, or run a real queue.

### Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `alerts.enabled` | `PAGESPEED_ALERTS_ENABLED` | true | The master switch. Off means nothing is compared, no hook fires, nothing is sent. |
| `alerts.drop_points` | `PAGESPEED_ALERT_DROP_POINTS` | 10 | Points a category may lose before it counts. 0 turns drop detection off, leaving the floors. |
| `alerts.thresholds.*` | `PAGESPEED_ALERT_THRESHOLD_*` | null | An absolute floor per category. Null means no floor. |
| `alerts.skip_degraded` | — | true | Whether a run that lost data is passed over when looking for a baseline. |
| `alerts.channels` | — | `['mail']` | Notification channels. |
| `alerts.mail_to` | `PAGESPEED_ALERT_MAIL_TO` | null | Who to email. An array, or a comma-separated string. |
| `alerts.notifiable` | — | null | A class the container can resolve to something notifiable, notified alongside `mail_to`. |
| `alerts.digest.enabled` | — | true | Whether regressions are batched. |
| `alerts.digest.wait` | `PAGESPEED_ALERT_DIGEST_WAIT` | 300 | Seconds to gather regressions for. |
| `alerts.digest.store` | `PAGESPEED_ALERT_DIGEST_STORE` | null | Cache store holding the buffer. Null means the default. |

Thresholds ship unset because a floor is a per-site judgement: guessed too high it alerts on everything, too low on nothing. With nobody in `alerts.mail_to` and no `alerts.notifiable`, detection still runs, still fires `ap.pageSpeed.scoreRegressed`, and still logs — it just has nowhere to send an email, which it says at debug level rather than silently.

## HTTP API

Authenticated JSON endpoints, behind `pagespeed` and the `web` and `auth` middleware by default. They back the React and Vue components and are the supported way to build a front end of your own.

| Method | Path | Answers |
|---|---|---|
| `GET` | `/pagespeed/scores?url=&strategy=` | The newest run's four category scores and lab metrics. |
| `GET` | `/pagespeed/core-web-vitals?url=&strategy=` | The newest run's CrUX field data, page-level and origin-level. |
| `GET` | `/pagespeed/opportunities?url=&strategy=` | What Lighthouse says is worth fixing, heaviest first. |
| `GET` | `/pagespeed/trends?url=&metric=&range=&strategy=` | One measurement over time, mobile against desktop. |
| `GET` | `/pagespeed/urls` | The monitored set. |
| `POST` | `/pagespeed/urls` | Start monitoring a URL. |
| `DELETE` | `/pagespeed/urls/{id}` | Stop monitoring one. Its result history is kept. |
| `POST` | `/pagespeed/test` | Queue an ad hoc run. Returns an id to poll with. |
| `GET` | `/pagespeed/results/{id}` | Poll that id, or fetch any stored run by its own id. |

Every response carries a `state` naming which of several honest answers it is, rather than leaving a caller to infer one from an empty list. An empty opportunities list means "nothing found" or "nothing looked for"; an empty vitals panel means "CrUX has no data" or "the run failed"; a blank trend means "no history" or "no history *in this range*". Those pairs have different remedies, so they are different states — `empty`, `failed`, `degraded`, `not-measured`, `none`, `origin-level`, `out-of-range`, `insufficient`, `loaded`, depending on the endpoint.

Bands (`good`, `needs-improvement`, `poor`) are computed server-side against Google's published thresholds. Labels and colours are not sent: those are the client's to choose, and a JSON endpoint that baked them in would hand every caller a string in the server's locale.

### Where the endpoints get their authority

**These endpoints carry no authorization of their own beyond the configured middleware.** An authenticated user is an authorized one: whoever can reach `/pagespeed/urls` can add and remove monitored URLs, and whoever can reach `/pagespeed/test` can spend a slice of the API quota. That is the same stance the Livewire components take — mounting one is the authorization decision — and it is deliberate, because a package cannot know what an application's admin role is called.

Put your own policy in `routes.middleware` (`['web', 'auth', 'can:manage-pagespeed']`, say) if "logged in" is broader than "allowed to manage performance monitoring" on your installation.

### Which URLs an endpoint will answer for

Within that grant, authentication is still not the whole of the question, because every endpoint takes a URL from the caller.

- **The read endpoints only ever serve URLs in this installation's own monitored set** — stored rows and hook contributions both. Anything else is `403 url_not_monitored`, whatever the flag below says. The results table is keyed on a plain URL column, so without this an authenticated user could read whatever this installation happens to have stored about a third party's site.
- **`POST /test` and `POST /urls` accept a monitored URL, or one on this application's own origin.** Both spend API quota — the first once, the second on every cycle for as long as the row lives — and an authenticated user must not be able to point that at arbitrary sites. Anything else is `403 url_not_allowed`.

Set `routes.allow_external_urls` (or `PAGESPEED_ALLOW_EXTERNAL_URLS`) to `true` on an installation that legitimately monitors other people's sites — an agency dashboard, most obviously. It widens the two write endpoints and never the read ones.

### Queueing a run

```http
POST /pagespeed/test
{ "url": "https://example.com/pricing", "strategy": "mobile" }

202 Accepted
{ "id": "9f1c…", "status": "queued", "url": "https://example.com/pricing", "strategy": "mobile" }
```

Then poll:

```http
GET /pagespeed/results/9f1c…

{ "id": 412, "status": "completed", "url": "…", "strategy": "mobile", "result": { … } }
```

The id is a ticket rather than a row id, because a queued run has no row until it finishes. Writing a pending row would have given you a real id immediately and put a third status into a table every other reader of which assumes two — the score card, the vitals card, and the opportunities table would all have started rendering an in-flight run as a completed one that measured nothing. So the ticket records what was queued and which result id was newest at the time, and resolves to the row the run produced.

`status` is `queued`, `completed`, `failed`, or `timed-out`. The last is a real answer rather than an endless spinner: past ten minutes the ticket stops implying that waiting will help. It does not claim the run failed — it may still be queued — but a stalled queue worker must not present as a slow one.

A numeric `{id}` is read as a stored result id instead, so any run you have the id of can be fetched in full later.

### Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `routes.enabled` | — | true | Whether the endpoints are registered at all. |
| `routes.prefix` | — | `pagespeed` | The path they sit under. |
| `routes.middleware` | — | `['web', 'auth']` | The stack they run through. |
| `routes.allow_external_urls` | `PAGESPEED_ALLOW_EXTERNAL_URLS` | false | Whether `POST /test` and `POST /urls` accept URLs off this site. |

All four are read when the service provider boots. Turning `routes.enabled` off registers nothing, which is the right choice for an installation that only uses the console commands, the queue, and the Livewire components — an endpoint nobody calls is still an endpoint somebody can call. Replacing `routes.middleware` replaces it wholesale, including the `auth` entry: the package applies the stack you configure rather than adding a guard of its own on top of it.

Two things worth knowing about that stack:

- The default includes `web`, so **`POST` and `DELETE` requests need a CSRF token** like any other session-authenticated form post. Send `X-CSRF-TOKEN`, or move the endpoints onto a stateless stack (`['api', 'auth:sanctum']`, say) if you are calling them from something that has no session.
- There is no `throttle` in the default stack. `POST /test` queues a job per call, and while the job middleware holds the whole application to `rate_limit.per_minute` API requests — so the quota itself is safe — nothing stops an authenticated user filling the queue. Add `throttle:30,1` to `routes.middleware` on an installation where "authenticated" is a low bar.

## React components

Five React components mirroring the five Livewire ones, built on [`@artisanpack-ui/react`](https://www.npmjs.com/package/@artisanpack-ui/react) and reading the JSON endpoints above. They ship as TypeScript sources rather than as a build, because they take their styling from the host application's Tailwind and daisyUI theme and so have to reach its pipeline before it compiles:

```bash
php artisan vendor:publish --tag=pagespeed-insights-js
npm install @artisanpack-ui/react
```

That puts `resources/js/{react,shared}` under `resources/js/vendor/pagespeed-insights`. Import from the barrel:

```tsx
import {
    ScoreCard,
    CoreWebVitalsCard,
    OpportunitiesTable,
    TrendChart,
    UrlManager,
} from '@/../js/vendor/pagespeed-insights/react'

export function Dashboard() {
    return (
        <>
            <ScoreCard url="https://example.com/pricing" />
            <CoreWebVitalsCard url="https://example.com/pricing" />
            <OpportunitiesTable url="https://example.com/pricing" />
            <TrendChart url="https://example.com/pricing" />
            <UrlManager />
        </>
    )
}
```

The barrel re-exports the components, their prop types, and the whole shared fetch layer, so an application writing its own UI can use the typed clients without the components.

## Vue components

The same five components for Vue 3, built on [`@artisanpack-ui/vue`](https://www.npmjs.com/package/@artisanpack-ui/vue), reading the same endpoints through the same shared layer, and rendering the same states. They ship as single-file components under `resources/js/vue` and publish under the same tag:

```bash
php artisan vendor:publish --tag=pagespeed-insights-js
npm install @artisanpack-ui/vue
```

```vue
<script setup lang="ts">
import {
    ScoreCard,
    CoreWebVitalsCard,
    OpportunitiesTable,
    TrendChart,
    UrlManager,
} from '@/../js/vendor/pagespeed-insights/vue'
</script>

<template>
    <ScoreCard url="https://example.com/pricing" />
    <CoreWebVitalsCard url="https://example.com/pricing" />
    <OpportunitiesTable url="https://example.com/pricing" />
    <TrendChart url="https://example.com/pricing" />
    <UrlManager />
</template>
```

The props are the ones below, spelled as Vue props — `endpoint-base`, `initial-metric`, `allow-running-tests` — with one difference. React's `onResultStored` callback is a component event here, so a finished run is heard with `@result-stored="( id ) => …"` rather than by passing a function down.

The composables the components are built from are exported alongside them: `usePsiResource( deps, load )` for the load lifecycle, and `usePsiResultStored( handler )` for the finished-run announcement, which unsubscribes with the surrounding effect scope. `usePsiResource` takes its dependencies as an explicit getter rather than inferring them, so a value read after an `await` inside `load` cannot silently stop being tracked.

Two things differ from the React half beneath the surface, and neither changes what renders:

- The components import the library from `@artisanpack-ui/vue` rather than from its `/layout`, `/display`, and `/form` subpaths, which is where the React half imports its own. The published Vue package declares those subpaths but ships no declaration file for any of them, and losing the component props' types is a worse trade than importing a barrel a bundler will tree-shake anyway.
- The library's Vue `Stat` takes no colour prop, so a banded number is coloured with a written-out `[&_.stat-value]:text-success` class rather than with `color`.

## Props

| Prop | Components | Type | Default | Notes |
|---|---|---|---|---|
| `url` | all but `UrlManager` | `string` | — | The URL to read. Must be one this installation monitors. |
| `strategy` | score card, vitals, opportunities | `'mobile' \| 'desktop'` | `'mobile'` | The form factor. |
| `endpointBase` | all | `string` | `/pagespeed` | Set this when `routes.prefix` is not the default. |
| `fetchImpl` | all | `typeof fetch` | `window.fetch` | Injectable fetch, for SSR and for tests. |
| `csrfToken` | score card, URL manager | `string \| null` | `<meta name="csrf-token">` | Needed by the writing endpoints on the default `web` stack. |
| `initialMetric`, `initialRange` | trend chart | `string`, `number` | `'performance'`, `90` | Which measurement is plotted first, and over how many days. |
| `allowRunningTests` | score card | `boolean` | `true` | Whether to offer the "Run test" button. |
| `onResultStored` | score card | `(id) => void` | — | Called with the stored result id once a queued run finishes. |

## States

Each component renders the same states its Livewire counterpart does, and for the same reason: a React card, a Vue card, and a Livewire card describing the same stored row must not disagree about what it says. So "the Chrome UX Report has no data for this page" and "these numbers describe the whole site" stay two different messages, an unscored category renders as an em dash rather than a zero, and an empty opportunities list is spelled three ways — never measured, measured and clean, or the run failed.

Two things are drawn by hand rather than by the component library, in both sets:

- **The trend line** is inline SVG. A trend carries one series per form factor, each on its own timestamps, with nulls where a completed run lost the measurement; the library's `Chart` takes a series as a plain `number[]` against shared labels, which expresses neither — and it pulls in ApexCharts, an optional peer a host application need not have installed. A gap in the history stays a gap in the line.
- **The alerts** pair a title with a description, which the library's `Alert` takes as children.

## Keeping the panels in step

The score card is the only component that queues a run, so it is the only one that knows when a new row lands — and it announces it, exactly as the Livewire card dispatches `pagespeed-insights:result-stored`. The vitals card and the opportunities table refresh when the announcement names their URL *and* their form factor; the trend chart refreshes on either form factor, because it plots both. Without it a page shows fresh scores beside three panels describing the previous run, which reads as a page that has finished updating when it has not. A timed-out ticket announces nothing: no row appeared, so there is nothing for the others to re-read.

The announcement is part of the shared layer rather than of either component set, so a page can join in from anywhere — including one built with neither component set, since it is also dispatched on `window`:

```ts
import { onPsiResultStored, PSI_EVENT_RESULT_STORED } from '@/../js/vendor/pagespeed-insights/shared'

// In a React or Vue surface — returns an unsubscribe function.
const stop = onPsiResultStored(({ url, strategy, id }) => reloadMyPanel(url, strategy))

// Or, from a plain script on a Blade page.
window.addEventListener(PSI_EVENT_RESULT_STORED, (event) => reloadMyPanel(event.detail))
```

React and Vue callers can use the `usePsiResultStored(handler)` hook or composable instead, which subscribes for the life of the component: the React one holds the handler in a ref so it need not be memoised, and the Vue one unsubscribes with the surrounding effect scope.

## Shared fetch layer

`resources/js/shared` is framework-free TypeScript: one typed client per endpoint (`scores.ts`, `core-web-vitals.ts`, `opportunities.ts`, `trends.ts`, `urls.ts`, `runs.ts`), the request plumbing they share (`client.ts`), and the names and colours the endpoints deliberately do not send (`labels.ts`). Both component sets consume the same modules, so both frameworks talk to one server payload rather than to two hand-written approximations of it.

Every refusal arrives as a `PageSpeedInsightsError` carrying the endpoint's stable `code` alongside its prose `message`, so a client branches on the code rather than on the wording:

```ts
import { fetchPsiScores, PageSpeedInsightsError } from '@/../js/vendor/pagespeed-insights/shared'

try {
    const scores = await fetchPsiScores({ url: 'https://example.com/pricing' })
} catch (error) {
    if (error instanceof PageSpeedInsightsError && 'url_not_monitored' === error.code) {
        // Offer to add it to the monitored set.
    }
}
```

## CMS-framework admin widgets

With [`artisanpack-ui/cms-framework`](https://gitlab.com/jacob-martella-web-design/artisanpack-ui/artisanpack-ui-cms-framework) installed alongside Livewire, three of the components register themselves as admin dashboard widgets. Nothing to publish and nothing to wire up: they appear in the dashboard's "Add Widget" panel.

| Widget type | Class | Renders |
|---|---|---|
| `pagespeed-insights.score-card` | `Bridges\CmsFramework\AdminWidgets\ScoreCardWidget` | The four Lighthouse category scores |
| `pagespeed-insights.core-web-vitals` | `Bridges\CmsFramework\AdminWidgets\CoreWebVitalsWidget` | LCP, INP, and CLS from CrUX field data |
| `pagespeed-insights.trend-chart` | `Bridges\CmsFramework\AdminWidgets\TrendChartWidget` | One measurement plotted over time |

Each wrapper extends the Livewire component it exposes, so the dashboard renders it the same way it renders any other Livewire-backed widget — by class name, or by the `pagespeed-score-card-widget`, `pagespeed-core-web-vitals-widget`, and `pagespeed-trend-chart-widget` aliases. The base aliases still point at the base components.

All three ask for the `view_pagespeed_insights` capability, which the CMS framework enforces — the widgets carry no gate of their own, the same stance the Livewire components take. Placing a widget is therefore the authorization decision: whoever can add or configure one chooses the address it tests, and a run spends a slice of the PageSpeed API quota and stores history for that address. The URL is `#[Locked]`, so a *viewer* of the dashboard cannot retarget a placed widget from the request payload.

A widget dropped on a dashboard from the picker has no URL — there is nowhere in that panel to type one — so it describes **the application's own home page**, taken from `app.url` and canonicalized the same way every stored URL is, which is what makes the widget read the same history rows the monitored home page writes. The URL is resolved on every render rather than baked into the widget's saved options, so a site that changes domain does not leave three widgets pointing at the old one. An `app.url` that is not a testable http(s) address leaves the widget with no URL and the component's own honest empty state, rather than an invented address nobody asked about.

The bridge is entirely optional in both directions. Without the CMS framework — or without Livewire — the provider registers no widgets and the package boots exactly as it does today. `PageSpeedInsightsServiceProvider::cmsFrameworkWidgetTypeMap()` returns the map above without booting anything, for an application that would rather register the widgets somewhere of its own.

## Retention

Score history is the point of this package, and it is also the thing that grows without limit if nothing stops it: an hourly cadence on 200 URLs across both form factors writes about 3.5 million rows a year. So the package prunes itself.

```bash
php artisan pagespeed:prune             # apply both windows
php artisan pagespeed:prune --dry-run   # report what would go, touch nothing
```

Two windows, not one, because the two things a result row holds cost wildly different amounts to keep:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `retention.days` | `PAGESPEED_RETENTION_DAYS` | 365 | Delete results older than this. |
| `retention.keep_raw_days` | `PAGESPEED_RETENTION_KEEP_RAW_DAYS` | 30 | Null `raw_response` on results older than this, leaving the scores in place. |

A score row is a few dozen bytes and is the entire reason history exists — a year keeps a full seasonal cycle, so this year's Black Friday has last year's to be compared against. A retained `raw_response` is hundreds of kilobytes of data the package has already parsed into its own columns, kept only so a parsing problem can be diagnosed against a real payload; a month-old payload has either answered that question or never will. So the payload expires first and the row outlives it.

Both windows measure from `created_at` rather than `fetched_at`, because `fetched_at` is Google's own analysis timestamp and is null on a failed run — and retention has to be able to expire a failed row too.

Either window can be set to `0` to turn that half off. `0` for `retention.days` means history is never deleted, which is a reasonable choice on a small monitored set, but it is a choice rather than the default. Anything that is not a positive whole number of days no larger than a century — a blank env var, a typo, a value so large that subtracting it from today overflows — is also read as off, because the alternative reading is a cutoff in the future, and a cutoff in the future matches the whole table.

`pagespeed:prune` is registered **daily** under the same `scheduling.enabled` flag as the monitoring task. A day's worth of results is a rounding error against a year-long window, so there is nothing to gain from sweeping more often.

`PageSpeedResult` is also `Prunable`, so `php artisan model:prune --model="ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult"` sweeps it alongside your own models if that is how you already run retention. It deletes on `retention.days` only — `Prunable` has no notion of expiring one column — and it hydrates a model per row, so `pagespeed:prune` is the better path for a table whose first prune may cover a million rows.

## Development

```bash
composer install
composer test    # Pest
composer lint    # PHP-CS-Fixer (dry run) + PHPCS
composer fix     # PHP-CS-Fixer, applied

npm install
npm test           # Vitest, against the React and Vue components
npm run type-check # vue-tsc --noEmit
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).
