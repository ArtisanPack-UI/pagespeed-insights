# ArtisanPack UI PageSpeed Insights

Google PageSpeed Insights testing, score history, and drop-in UI components for
the ArtisanPack UI ecosystem.

Google's PageSpeed Insights UI tells you how a page performs *right now*. This
package adds the part it leaves out: **history**. Scheduled, queued re-tests
build a per-URL score timeline, so a performance regression shows up as a
visible trend — and as an alert — rather than as a hunch.

- Scheduled PSI runs against a managed list of URLs, with full result history
- Lighthouse category scores, lab metrics, and CrUX field data stored per URL and strategy
- Livewire, React, and Vue components, plus CMS-framework admin widgets
- Regression alerts — and alerts for the URLs that stop reporting at all — through standard Laravel notifications
- A CI-friendly `pagespeed:test` command with score budgets and one exit code per failure class
- Hooks so other packages can register URLs and consume results

📖 **[Full documentation](docs/home.md)**

## Requirements

- PHP **8.2+**
- Laravel **12 or 13**
- A **PageSpeed Insights API key** — see [API key](#api-key); the package cannot run without one
- [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google) **^1.0** and [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks) **^1.2** — installed automatically
- **Livewire ^3.6** *(optional)* — required only for the Blade / Livewire components and the CMS-framework admin widget bridge

## Installation

```bash
composer require artisanpack-ui/pagespeed-insights
php artisan migrate
```

The service provider and the `PageSpeedInsights` facade are auto-discovered.

Full details, publish tags, and the queue/scheduler wiring:
**[docs/installation.md](docs/installation.md)**.

## API key

The PageSpeed Insights API is quota-limited per project. **Keyless requests are
not a workable fallback** — the shared anonymous project has a daily quota of
zero, so a request without a key answers HTTP 429 every time. A key is
required.

### Creating one

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and sign in.
2. Pick an existing project from the project selector, or create one (**New project** → name it → **Create**).
3. Enable the API for that project: open [PageSpeed Insights API](https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com) in the API Library and click **Enable**. A key will not work until this is done, even if the key itself exists.
4. Go to **APIs & Services → Credentials** ([direct link](https://console.cloud.google.com/apis/credentials)) and click **Create credentials → API key**. Copy the key.
5. Click **Edit API key** and, under **API restrictions**, choose **Restrict key** and select **PageSpeed Insights API**. Leave **Application restrictions** set to **None** — the requests come from your server, not a browser, and an HTTP-referrer restriction would reject them.
6. Add it to your environment:

```dotenv
PAGESPEED_API_KEY=your-key-here
```

Billing is not required. **Google does not publish the quota numbers for keyed
projects anywhere**; check **APIs & Services → PageSpeed Insights API → Quotas**
in the Console for the limits that apply to yours. That is also why
`rate_limit.per_minute` defaults to a conservative 30.

The key is sent on the `X-goog-api-key` header rather than as a `key=` query
parameter, so it never appears in a URL — a URL-borne key ends up in
connection-error messages, exception reports, and proxy logs.

### Where the key is stored

`PAGESPEED_CONFIG_DRIVER` selects the storage driver, mirroring
`GOOGLE_CONFIG_DRIVER` in [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google):

| Driver | Storage | Writable |
|---|---|---|
| `config` *(default)* | `PAGESPEED_API_KEY` / `config( 'pagespeed-insights.api_key' )` | No — managed through config/env |
| `database` | `pagespeed_configurations` table, encrypted at rest | Yes |
| `cms` | CMS framework Settings module, encrypted at rest | Yes — requires `artisanpack-ui/cms-framework` |

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

PageSpeedInsights::config()->isConfigured();    // false means PSI cannot run at all
PageSpeedInsights::config()->getApiKey();
PageSpeedInsights::config()->save( 'new-key' ); // database and cms drivers only
```

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

A run takes 20–60 seconds and sometimes longer, so call it from a queued job or
a console command, never from a web request:

```php
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;

RunPageSpeedTest::dispatch( 'https://example.com/about', 'mobile' );
```

### Failure modes

Three exception types, because at the HTTP layer these look alike and each needs
a different response:

| Exception | Cause | What to do |
|---|---|---|
| `MissingApiKeyException` | No API key configured | Configure one. `isRetryable()` is false — retrying never helps. |
| `QuotaExceededException` | A keyed project is out of quota | Back off and try later. |
| `PageSpeedApiException` | Transport failure, API error, or Lighthouse's own `runtimeError` | Usually retryable. |

An unkeyed request and an exhausted project both return HTTP 429, so the client
checks whether a key was configured before classifying one — the first is a
configuration problem, not quota exhaustion. See
[docs/troubleshooting.md](docs/troubleshooting.md).

### Thin results

The parser tolerates unknown Lighthouse categories, absent categories, missing
lab metrics, and missing CrUX data rather than failing the run — but it never
does so silently. Every skip is logged with the key it skipped, and carried on
the result:

```php
$result->hasWarnings();
$result->warnings();               // run_warnings, unrecognized_categories, missing_categories, missing_metrics, missing_field_data
$result->scores->unrecognized();   // e.g. ['agentic-browsing'] after a Lighthouse release
```

Lighthouse changes its category and metric lineup roughly every two releases —
`PWA` is deprecated as of Lighthouse 12, `AGENTIC_BROWSING` has been added, and
Time to Interactive left the performance score in Lighthouse 10. So the package
stores four category scores and a fixed set of lab metrics, tolerates unknown
and absent categories without failing, and **does not guarantee that a newly
added Lighthouse category will be surfaced until a release adds it**.

## Monitored URLs

`UrlRegistry` is the single answer to which URLs this installation monitors. It
merges the rows in `pagespeed_urls` with whatever other packages contribute
through a hook, and deduplicates the two by canonical URL.

```php
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;

$registry = app( UrlRegistry::class );

$registry->all();       // stored rows + hook contributions, deduped
$registry->active();    // the same, filtered to URLs still being tested
$registry->find( 'https://example.com/about/' );  // matches however it is spelled

$url = $registry->add( 'https://example.com/pricing', [ 'label' => 'Pricing' ] );
$registry->update( $url, [ 'test_frequency' => 'daily', 'strategies' => [ 'mobile' ] ] );
$registry->deactivate( $url );   // pause testing, keep the history
$registry->delete( $url );
```

Every URL is stored in a canonical form, so `https://example.com/about`,
`https://example.com/about/`, and `HTTPS://Example.com/about#team` are one
monitored page rather than three histories of the same page. Query strings and
`www.` are preserved, because either can change which document is served.

Or populate the set from your sitemap:

```bash
php artisan pagespeed:discover-sitemap --activate
```

## Commands

| Command | Does | Scheduled |
|---|---|---|
| `pagespeed:test {url}` | Test one URL now and hold it to CI score budgets | No |
| `pagespeed:monitor` | Queue tests for the monitored URLs that are due | Hourly |
| `pagespeed:discover-sitemap` | Populate the monitored set from a sitemap | No |
| `pagespeed:check-staleness` | Report monitored URLs that stopped producing results | Hourly |
| `pagespeed:prune` | Apply the retention windows | Daily |

Full flags and behaviour: **[docs/commands.md](docs/commands.md)**.

### Testing in CI

`pagespeed:test` runs one test synchronously and exits non-zero when the result
misses a budget. It is the one command in the package that waits out a run,
because a pipeline has nowhere to put a queued job.

```bash
php artisan pagespeed:test https://example.com/ --min-performance=90 --max-lcp-ms=2500
php artisan pagespeed:test https://example.com/ --strategy=desktop --categories=performance,seo
php artisan pagespeed:test https://example.com/ --min-performance=90 --json
```

| Code | Class | Meaning |
|---|---|---|
| `0` | — | Every budget passed |
| `1` | `budget` | A budget was violated — the signal the command exists for |
| `2` | `input` | The command was called with unusable arguments |
| `3` | `configuration` | No API key is configured |
| `4` | `run` | The run failed, a budgeted measurement was unavailable, or `--store` could not save it |

A budget checked against a score that is null or absent is reported as
**unavailable** and exits `4` — never as a pass. A green pipeline that is green
because three categories quietly stopped coming back is worse than having no
budgets at all.

## Scheduled testing and alerts

Monitored URLs are tested by a queued job. Make sure a queue worker is running
and Laravel's scheduler is wired up; the package registers `pagespeed:monitor`
and `pagespeed:check-staleness` hourly and `pagespeed:prune` daily.

After each completed run, the package compares it with the previous completed
run for the same URL and form factor. Three things count as a regression: a drop
of at least `alerts.drop_points`, a score below a configured floor, and **a
category that was scored last run and is not measured now**.

A second detector runs on the schedule and reports the URLs that stopped
producing results at all — a revoked key, a page now returning 404, a dead
worker — because a chart that flatlines at the last good score looks perfectly
healthy while monitoring has silently stopped.

Full behaviour: **[docs/alerts.md](docs/alerts.md)**.

## Components

Five panels in each of three stacks, all rendering the same states from the same
stored rows: a score card, a Core Web Vitals card, an opportunities table, a
trend chart, and a URL manager.

```blade
<livewire:pagespeed-score-card url="https://example.com/pricing" />
<livewire:pagespeed-trend-chart url="https://example.com/pricing" />
```

```bash
php artisan vendor:publish --tag=pagespeed-insights-js
npm install @artisanpack-ui/react   # or @artisanpack-ui/vue
```

```tsx
import { ScoreCard, TrendChart } from '@/../js/vendor/pagespeed-insights/react'

<ScoreCard url="https://example.com/pricing" />
```

They ship as TypeScript sources rather than as a build, because they take their
styling from the host application's Tailwind and daisyUI theme.

- **[docs/livewire-components.md](docs/livewire-components.md)**
- **[docs/react-components.md](docs/react-components.md)**
- **[docs/vue-components.md](docs/vue-components.md)**

With `artisanpack-ui/cms-framework` installed alongside Livewire, three of them
also register as admin dashboard widgets —
**[docs/cms-framework.md](docs/cms-framework.md)**.

## HTTP API

Authenticated JSON endpoints behind the `pagespeed` prefix and the `web` and
`auth` middleware by default. They back the React and Vue components and are the
supported way to build a front end of your own.

| Method | Path | Answers |
|---|---|---|
| `GET` | `/pagespeed/scores` | The newest run's category scores and lab metrics |
| `GET` | `/pagespeed/core-web-vitals` | The newest run's CrUX field data |
| `GET` | `/pagespeed/opportunities` | What Lighthouse says is worth fixing |
| `GET` | `/pagespeed/trends` | One measurement over time |
| `GET` `POST` `DELETE` | `/pagespeed/urls` | The monitored set |
| `POST` | `/pagespeed/test` | Queue an ad hoc run; returns a ticket to poll |
| `GET` | `/pagespeed/results/{id}` | Poll that ticket, or fetch any stored run |

**These endpoints carry no authorization of their own beyond the configured
middleware** — by default an authenticated user is an authorized one. If "logged
in" is broader than "allowed to manage performance monitoring" on your
installation — a SaaS with customer accounts, a site with public registration —
set an ability:

```php
// config/pagespeed-insights.php  (or PAGESPEED_ROUTES_ABILITY in .env)
'routes' => [ 'ability' => 'view_pagespeed_insights' ],

// and define the gate in your own AuthServiceProvider
Gate::define( 'view_pagespeed_insights', fn ( User $user ): bool => $user->isAdmin() );
```

It is appended to `routes.middleware` rather than replacing it, so `auth` stays
in front of it. This is the recommended setting for any app whose accounts are
not all staff.

Full reference, states, and error codes:
**[docs/http-endpoints.md](docs/http-endpoints.md)**.

## Hooks

Six extension points, built on [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks):

| Hook | Kind | Fired |
|---|---|---|
| `ap.pageSpeed.registerUrls` | Filter | Whenever the monitored set is read |
| `ap.pageSpeed.opportunities` | Filter | While parsing a run, before opportunities are returned |
| `ap.pageSpeed.beforeTest` | Action | Before a run is sent to Google |
| `ap.pageSpeed.resultStored` | Action | After a result row is written — including a failed one |
| `ap.pageSpeed.scoreRegressed` | Action | After a stored run is found to have regressed |
| `ap.pageSpeed.urlWentStale` | Action | After a monitored URL is found to have stopped reporting |

```php
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;

addFilter( UrlRegistry::FILTER_REGISTER_URLS, function ( array $urls ): array {
    return [ ...$urls, 'https://example.com/checkout' ];
} );
```

Signatures and payload shapes: **[docs/hooks.md](docs/hooks.md)**.

## Configuration

```bash
php artisan vendor:publish --tag=pagespeed-insights-config
```

Every key, env var, and default is documented in
**[docs/configuration.md](docs/configuration.md)** — including the `warnings`
column and what a degraded run means.

The defaults worth knowing about up front:

| Key | Default | Why |
|---|---|---|
| `rate_limit.per_minute` | 30 | Google publishes no rate limit, so the default is deliberately conservative |
| `test_frequency` | `weekly` | Each run costs one request per form factor |
| `retention.days` | 365 | A full seasonal cycle. An hourly cadence on 200 URLs writes ~3.5M rows a year. |
| `retention.keep_raw_days` | 30 | A raw payload is hundreds of KB of already-parsed data |
| `store_raw_response` | false | Same reason |
| `routes.allow_external_urls` | false | Testing a URL spends quota; an authenticated user must not aim that anywhere |
| `routes.ability` | null | Additive, so no existing install changes behaviour by upgrading into it |

## Documentation

| Guide | Covers |
|---|---|
| [Installation](docs/installation.md) | Requirements, install, publishing, Google Cloud setup |
| [Configuration](docs/configuration.md) | Every config key, the credential drivers, degraded runs |
| [Commands](docs/commands.md) | The five commands, their flags, and exit codes |
| [HTTP endpoints](docs/http-endpoints.md) | The JSON API, its states, and its error codes |
| [Livewire components](docs/livewire-components.md) | The five Blade / Livewire panels |
| [React components](docs/react-components.md) | The React set and the shared fetch layer |
| [Vue components](docs/vue-components.md) | The Vue 3 set |
| [Alerts](docs/alerts.md) | Regressions, the digest, and URLs that stop reporting |
| [Hooks](docs/hooks.md) | The six `ap.pageSpeed.*` hooks, with signatures |
| [CMS framework](docs/cms-framework.md) | The admin-widget and settings bridge |
| [Testing](docs/testing.md) | The package's suite, and testing an app that uses it |
| [Troubleshooting](docs/troubleshooting.md) | Symptom → cause → fix for every failure class |
| [FAQ](docs/faq.md) | Quotas, cadence, cost, and the questions that come up first |
| [PSI API reference](docs/psi-api-reference.md) | The verified API behaviour this package is built against |

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

Nothing in the suite calls the live PageSpeed API — runs are served from
checked-in response fixtures.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE).
