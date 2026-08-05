---
title: Commands
---

# Commands

Five Artisan commands. Three of them (`pagespeed:monitor`,
`pagespeed:check-staleness`, `pagespeed:prune`) are registered on Laravel's
scheduler for you unless `scheduling.enabled` is off.

| Command | Does | Scheduled |
|---|---|---|
| [`pagespeed:test`](#pagespeedtest) | Test one URL now and hold it to CI score budgets | No |
| [`pagespeed:monitor`](#pagespeedmonitor) | Queue tests for the monitored URLs that are due | Hourly |
| [`pagespeed:discover-sitemap`](#pagespeeddiscover-sitemap) | Populate the monitored set from a sitemap | No |
| [`pagespeed:check-staleness`](#pagespeedcheck-staleness) | Report monitored URLs that stopped producing results | Hourly |
| [`pagespeed:prune`](#pagespeedprune) | Apply the retention windows | Daily |

---

## `pagespeed:test`

> Test one URL now and hold the result to CI score budgets.

```bash
php artisan pagespeed:test {url}
    [--strategy=mobile]
    [--categories=all]
    [--store]
    [--json]
    [--min-performance=] [--min-accessibility=] [--min-best-practices=] [--min-seo=]
    [--max-lcp-ms=] [--max-cls=] [--max-tbt-ms=]
```

This is the one command in the package that waits out a run, because a pipeline
has nowhere to put a queued job and nothing to do while it waits. Expect it to
take 20–60 seconds, sometimes longer.

```bash
php artisan pagespeed:test https://example.com/
php artisan pagespeed:test https://example.com/ --strategy=desktop --categories=performance,seo
php artisan pagespeed:test https://example.com/ --min-performance=90 --max-lcp-ms=2500
php artisan pagespeed:test https://example.com/ --min-performance=90 --json
php artisan pagespeed:test https://example.com/ --store
```

### Arguments and options

| Option | Meaning |
|---|---|
| `url` | The absolute http(s) URL to test. Required. |
| `--strategy=` | The form factor: `mobile` (default) or `desktop`. |
| `--categories=` | Categories to request, comma-separated, or `all` (default). |
| `--store` | Save the run into result history. |
| `--json` | Print machine-readable JSON instead of a table. |

### Budgets

| Flag | Fails when |
|---|---|
| `--min-performance=` | The performance score is below it |
| `--min-accessibility=` | The accessibility score is below it |
| `--min-best-practices=` | The best practices score is below it |
| `--min-seo=` | The SEO score is below it |
| `--max-lcp-ms=` | Largest Contentful Paint is above it |
| `--max-cls=` | Cumulative Layout Shift is above it |
| `--max-tbt-ms=` | Total Blocking Time is above it |

A budget on a category that was not requested is refused **before** the request
goes out, rather than reported as unavailable a minute later — it could never
have been checked, so it is a typo rather than a regression. Metric budgets need
the `performance` category, since lab metrics only come back with it.

### Exit codes

Undocumented exit codes are unusable in CI, which is this command's entire
purpose. There is one per failure class:

| Code | Class | Meaning |
|---|---|---|
| `0` | — | Every budget passed |
| `1` | `budget` | A budget was violated — the signal the command exists for |
| `2` | `input` | The command was called with unusable arguments |
| `3` | `configuration` | No API key is configured |
| `4` | `run` | The run failed, a budgeted measurement was unavailable, or `--store` could not save it |

The API key is checked **before** the request. Without that preflight the CI
experience is a 20–60 second wait ending in a raw 429, which in a build log
reads as a flaky API rather than as a missing secret.

The class name is printed alongside the code and carried in `--json` as
`failure_class`, so a pipeline can branch without parsing prose.

### A budget never passes on a missing score

A budget checked against a score or metric that is null or absent is reported as
**unavailable** and exits `4` — never as a pass, and in different words from a
score that fell short, because "accessibility is 40" and "accessibility did not
come back" need different fixes. A green pipeline that is green because three
categories quietly stopped coming back is worse than having no budgets at all.

For the same reason an unavailable measurement outranks a violated budget when
both happen: the run itself cannot be trusted, and a developer sent to
investigate a regression that may not exist is a developer sent to the wrong
place. Both are still listed.

Everything the run tolerated — Lighthouse's own `runWarnings`, absent
categories, missing lab metrics, no CrUX data — is printed as warnings and
carried in `--json`, so a degraded run and a clean one never look the same on
stdout. See [Configuration → Degraded runs](configuration.md#degraded-runs-and-the-warnings-column).

### Machine-readable output

`--json` prints one document and nothing else:

| Key | Holds |
|---|---|
| `status` | `pass` or `fail` |
| `failure_class` | `budget`, `input`, `configuration`, `run`, or null |
| `exit_code` | The code the command will exit with |
| `scores` | The four category scores, null where unscored |
| `metrics` | The lab metrics, null where absent |
| `warnings` | Everything the run tolerated |
| `stored_result_id` | The id `--store` wrote, or null |
| `budgets` | One entry per budget: its flag, target, actual value, and `pass` / `fail` / `unavailable` verdict |

A CI annotation is only as good as what is in the payload, so the failure paths
emit the same envelope with an `error` message rather than falling back to plain
text.

### What `--store` does and does not do

`--store` saves the run into result history. An ad hoc result has a null
`pagespeed_url_id` unless the URL is already monitored, in which case it joins
that URL's history.

Nothing else the scheduled path does happens: **no hooks fire and no regression
alert is raised**, because a CI run's audience is the pipeline that invoked it
and a branch build must not page the team.

---

## `pagespeed:monitor`

> Queue PageSpeed tests for the monitored URLs that are due.

```bash
php artisan pagespeed:monitor
php artisan pagespeed:monitor --url=https://example.com/about
```

| Option | Meaning |
|---|---|
| `--url=` | Test one monitored URL now, whether or not it is due. |

Registered **hourly** with `withoutOverlapping()` when `scheduling.enabled` is
on. Hourly is how often the package *looks*, not how often it tests: a URL only
comes due once its own `test_frequency` has elapsed.

The command refuses a whole cycle when no API key is configured, with one loud
error, rather than queueing N URLs × 2 form factors of jobs that each fail
identically. It exits `1` in that case, and also when `--url=` names something
it cannot queue. A cycle that finds nothing due exits `0` and says so.

It does not know what is already sitting in the queue: a URL stays due until a
run actually completes, so if your worker is far enough behind that a cycle's
jobs have not drained within the hour, the next cycle will queue that URL again.
At the default budget of 30 requests a minute this needs a very large monitored
set to reach — but if you are near it, give PageSpeed its own queue and worker.

You can queue a run yourself, for a URL that is not monitored at all if you
like:

```php
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;

RunPageSpeedTest::dispatch( 'https://example.com/about', 'mobile' );
```

---

## `pagespeed:discover-sitemap`

> Discover URLs from a sitemap and add them to PageSpeed monitoring.

```bash
php artisan pagespeed:discover-sitemap
php artisan pagespeed:discover-sitemap --sitemap=https://example.com/sitemap_index.xml --limit=200
php artisan pagespeed:discover-sitemap --activate
```

| Option | Meaning |
|---|---|
| `--sitemap=` | The sitemap URL to read. Defaults to `sitemap.xml` at the app URL. |
| `--limit=` | The most URLs to discover. Defaults to the configured cap (50). |
| `--activate` | Start testing the discovered URLs immediately. |
| `--allow-external` | Keep entries hosted somewhere other than the sitemap itself. |

**Discovered URLs are inactive unless you pass `--activate`.** A 500-page
sitemap activated in one command is 1,000 API requests per cycle against a quota
you have not looked at yet; reviewing the list and turning on the pages that
matter costs far less than discovering a burned quota.

URLs are stored with `source=sitemap`. Defaults come from
[`sitemap.*`](configuration.md#sitemap-discovery).

Parsing is tolerant of what real sitemaps look like: a truncated document still
yields the entries above the damage, a child sitemap that 404s is skipped rather
than aborting its siblings, and namespace prefixes parse the same as the default
namespace. The sitemap you actually named is the exception — if that one cannot
be fetched or parsed, the command exits `1` with the reason rather than
reporting zero URLs found.

A response that parses but is not rooted at `<urlset>` or `<sitemapindex>` is
rejected by name, because recovery-mode parsing happily reads an HTML error page
and a custom 404 served with a 200 status is common.

A sitemap index may only point at sitemaps on its own **origin** — scheme, host,
and port all compared — and a child anywhere else is skipped and logged. Host
alone would still let an index send the discoverer to another port of the same
machine, or downgrade an `https` walk to `http`. Redirects are followed for the
sitemap you name — so `example.com/sitemap.xml` redirecting to
`www.example.com/sitemap.xml` works — but never for a sitemap that a document
pointed at, since the origin check runs before the request and a redirect would
step around it.

Page entries are held to the host that served the sitemap they were listed in
(the host *after* any redirect, so apex-to-www does not discard everything). A
sitemap listing `https://cdn.example.net/page` under `https://example.com`'s
sitemap has that entry skipped and logged. This is not about who fetches the
page — Google does — but about who chooses what this installation monitors: a
monitored URL is one every authenticated user can read history for, and one that
spends quota on every cycle. Pass `--allow-external` where that is the point, as
on an agency install reading a client's sitemap.

A single document is also capped at 10 MB. The sitemaps.org protocol caps one at
50 MB, and a compressed body that inflates past the cap is a way to exhaust the
memory of whatever process asked for it.

`SitemapDiscoverer` is available directly when you want the list without storing
it:

```php
use ArtisanPackUI\PageSpeedInsights\Urls\SitemapDiscoverer;

$found = app( SitemapDiscoverer::class )->discover( 'https://example.com/sitemap.xml', 100 );
```

---

## `pagespeed:check-staleness`

> Report the monitored URLs that have stopped producing PageSpeed results.

```bash
php artisan pagespeed:check-staleness
php artisan pagespeed:check-staleness --dry-run
```

| Option | Meaning |
|---|---|
| `--dry-run` | Report what is stale without alerting anybody about it. |

Registered **hourly** with `withoutOverlapping()` alongside `pagespeed:monitor`.
Always exits `0` — a stale URL is a finding to report, not a failure of the
command.

`--dry-run` neither sends a notification, nor fires
[`ap.pageSpeed.urlWentStale`](hooks.md#appagespeedurlwentstale), nor consumes the
`alerts.staleness.repeat_after` window, so asking the question by hand does not
silence the next real alert. It also runs even when
`alerts.staleness.enabled` is off — a report you asked for by hand is not an
alert, so switching alerting off should not stop you looking. Without
`--dry-run` and with the setting off, the command says so and checks nothing.

See [Alerts → Pages that stop reporting](alerts.md#pages-that-stop-reporting)
for what counts as stale and how the cause is diagnosed.

---

## `pagespeed:prune`

> Delete expired PageSpeed results and discard raw payloads past their retention window.

```bash
php artisan pagespeed:prune
php artisan pagespeed:prune --dry-run
```

| Option | Meaning |
|---|---|
| `--dry-run` | Report what would go without deleting anything. |

Registered **daily** with `withoutOverlapping()`. Applies both retention
windows: deleting results older than `retention.days`, and nulling
`raw_response` on results older than `retention.keep_raw_days` while leaving
their scores in place. Always exits `0`.

`PageSpeedResult` is also `Prunable`, so
`php artisan model:prune --model="ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult"`
sweeps it alongside your own models if that is how you already run retention. It
deletes on `retention.days` only — `Prunable` has no notion of expiring one
column — and it hydrates a model per row, so `pagespeed:prune` is the better
path for a table whose first prune may cover a million rows.

See [Configuration → Retention](configuration.md#retention).
