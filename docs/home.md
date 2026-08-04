---
title: PageSpeed Insights Documentation
---

# PageSpeed Insights Documentation

`artisanpack-ui/pagespeed-insights` adds Google PageSpeed Insights testing, score
history, and drop-in UI components to a Laravel application.

Google's PageSpeed Insights UI tells you how a page performs *right now*. This
package adds the part it leaves out: **history**. Scheduled, queued re-tests
build a per-URL score timeline, so a performance regression shows up as a
visible trend — and as an alert — rather than as a hunch.

## Guides

- [Installation](installation.md) — Requirements, install, publishing, and the Google Cloud setup for an API key
- [Configuration](configuration.md) — Every config key, the three credential drivers, and what a degraded run means
- [Commands](commands.md) — The five Artisan commands, their flags, and `pagespeed:test`'s exit codes
- [HTTP endpoints](http-endpoints.md) — The authenticated JSON API behind the React and Vue components
- [Livewire components](livewire-components.md) — The five Blade / Livewire panels
- [React components](react-components.md) — The same five panels for React, plus the shared fetch layer
- [Vue components](vue-components.md) — The same five panels for Vue 3
- [Alerts](alerts.md) — Regression detection, the digest, and the URLs that stop reporting
- [Hooks](hooks.md) — The six `ap.pageSpeed.*` actions and filters, with signatures
- [CMS framework](cms-framework.md) — The optional admin-widget and settings bridge
- [Testing](testing.md) — Running the package's own suite, and testing an application that uses it
- [Troubleshooting](troubleshooting.md) — Symptom → cause → fix for every failure class
- [FAQ](faq.md) — Quotas, cadence, cost, and the questions that come up first
- [PSI API reference](psi-api-reference.md) — The verified PageSpeed Insights API behaviour this package is built against

## An API key is required

There is no usable keyless mode. Google's shared anonymous project has a daily
quota of **zero**, so a request without a key answers HTTP 429 every time. The
anonymous tier exists in this package only as the diagnostic path that produces
the "no API key configured" error.

See [Installation → Getting a PageSpeed Insights API key](installation.md#getting-a-pagespeed-insights-api-key).

## The shape of the package

| Layer | What it does |
|---|---|
| `PageSpeedClient` | Talks to `runPagespeed`, parses the payload into typed data objects |
| `UrlRegistry` | The single answer to "which URLs does this installation monitor?" |
| `RunPageSpeedTest` | The queued job that runs a test and writes a result row |
| `TestScheduler` | Works out which monitored URLs are due and queues them |
| `RegressionDetector` / `StalenessDetector` | Turn a silent regression, or a URL that stopped reporting, into a notification |
| Livewire / React / Vue components | Five panels each, rendering the same states from the same stored rows |
| Console commands | Ad hoc CI testing, monitoring, sitemap discovery, staleness checks, retention |

## The PHP API surface

### Facade and helper

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

PageSpeedInsights::version();   // the package version, e.g. '1.0.0'
PageSpeedInsights::config();    // the ApiKeyRepository for the configured driver
PageSpeedInsights::client();    // the PageSpeedClient
PageSpeedInsights::test( $url, $strategy = 'mobile', $locale = null );  // a TestResult
```

`pageSpeedInsights()` is a global helper returning the same instance, for code
that would rather not import the facade:

```php
pageSpeedInsights()->config()->isConfigured();
```

`config()` and `client()` resolve from the container on **every** call rather
than being injected, so a driver, endpoint, or timeout changed at runtime takes
effect immediately — the facade's backing instance is a long-lived singleton and
would otherwise hold a stale one.

`test()` is **synchronous**: it blocks for as long as Google takes, which is
20–60 seconds and sometimes longer, so it belongs in a queued job or a console
command rather than a web request. It throws one of the three exception types
listed in [Troubleshooting](troubleshooting.md#the-three-exception-types).

### Data objects

`TestResult` is what a parsed run looks like. Everything on it is readonly.

| Object | Reached by | Carries |
|---|---|---|
| `TestResult` | `PageSpeedInsights::test()`, `PageSpeedResult::toTestResult()` | The whole run: `url`, `strategy`, `scores`, `labMetrics`, `fieldData`, `originFieldData`, `opportunities`, `finalUrl`, `lighthouseVersion`, `analyzedAt`, and the warning arrays |
| `ScoreSet` | `$result->scores` | `performance()`, `accessibility()`, `bestPractices()`, `seo()`, `get()`, `has()`, `all()`, `unrecognized()` |
| `LabMetrics` | `$result->labMetrics` | `firstContentfulPaint()`, `largestContentfulPaint()`, `totalBlockingTime()`, `cumulativeLayoutShift()`, `speedIndex()`, `value()`, `display()`, `all()`, `missing()` |
| `FieldData` | `$result->effectiveFieldData()` | The CrUX metrics at the 75th percentile, plus `overallCategory()`, `isOriginFallback()`, and `isOriginLevel()` |
| `Opportunity` | `$result->opportunities` | `id`, `title`, `score`, `savingsMs`, `displayValue`, `metricSavings`, `description`, and `weight()` |

Every score and metric accessor returns **null rather than zero** when the
measurement is absent. A zero would be a measurement; null is the absence of
one, and the two must not be conflated — see
[Configuration → Degraded runs](configuration.md#degraded-runs-and-the-warnings-column).

`effectiveFieldData()` returns page-level data when CrUX has it and origin-level
data otherwise; ask `isOriginLevel()` before presenting it as a verdict on the
page.

### Models

`PageSpeedUrl` is a monitored URL; `PageSpeedResult` is one run.

```php
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;

PageSpeedUrl::query()->active()->due()->get();
PageSpeedUrl::query()->forUrl( 'https://example.com/about/' )->first();

$url->results();               // HasMany
$url->frequency();             // its own cadence, or the configured default
$url->isDue();
$url->effectiveStrategies();   // its own strategies, or both

PageSpeedResult::query()->forUrl( $url, 'mobile' )->completed()->get();
PageSpeedResult::query()->latestFor( 'https://example.com/about/', 'mobile' )->first();

$result->isCompleted();
$result->isFailed();
$result->wasDegraded();
$result->warningList();
$result->scores();             // a ScoreSet
$result->toTestResult();       // a TestResult
```

Prefer `UrlRegistry` over `PageSpeedUrl` directly for anything that writes: it
canonicalizes URLs, records `source` itself, and merges in hook contributions.

## Compatibility policy

Lighthouse changes its category and metric lineup roughly every two releases.
`PWA` is marked deprecated in the live schema ("deprecated in Lighthouse's 12.0
release"), `AGENTIC_BROWSING` has been *added* to the category enum, and Time to
Interactive was removed from the performance score in Lighthouse 10.

So the package commits to a fixed shape and tolerates drift around it:

- It stores **four** category scores — performance, accessibility, best
  practices, SEO — and a fixed set of lab metrics (FCP, LCP, TBT, CLS, Speed
  Index).
- It tolerates **unknown** categories in a response and **absent** categories
  that were requested, without failing the run.
- Every tolerated gap is recorded rather than swallowed: see the `warnings`
  column, `TestResult::warnings()`, and `ScoreSet::unrecognized()`.
- It does **not** guarantee that a newly added Lighthouse category will be
  surfaced until a release of this package adds it.

## No silent failures

Everything this package tolerates, it also reports. A run that completed while
losing a category is *degraded*, not merely successful; a budget checked against
a score that never arrived is *unavailable*, not a pass; a monitored URL that
stops producing results is alerted about rather than left to flatline a chart at
its last good score.

[Troubleshooting](troubleshooting.md) maps each of those signals back to a
cause and a fix.
