---
title: Configuration
---

# Configuration

Publish the config file to edit it:

```bash
php artisan vendor:publish --tag=pagespeed-insights-config
```

Everything below lives under the `pagespeed-insights` config key.

## Credentials

| Key | Env | Default | Meaning |
|---|---|---|---|
| `api_key` | `PAGESPEED_API_KEY` | null | The PageSpeed Insights API key. Used by the `config` driver only. |
| `driver` | `PAGESPEED_CONFIG_DRIVER` | `config` | Which store backs `ApiKeyRepository`: `config`, `database`, or `cms`. |

The key is **required**. There is no supported keyless mode — see
[Installation → Getting a PageSpeed Insights API key](installation.md#getting-a-pagespeed-insights-api-key).

The three drivers differ only in where the key lives:

- **`config`** reads `api_key` above. Read-only: `save()` refuses, because the
  value is managed through config and env.
- **`database`** stores the key encrypted at rest in `pagespeed_configurations`.
  Requires the package migrations.
- **`cms`** stores the key encrypted through the CMS framework's Settings
  module, and is only available when `artisanpack-ui/cms-framework` is
  installed. See [CMS framework](cms-framework.md).

`ApiKeyRepository` is a plain container bind rather than a singleton, so
changing `pagespeed-insights.driver` at runtime takes effect on the next
resolve.

## The API request

| Key | Env | Default | Meaning |
|---|---|---|---|
| `endpoint` | `PAGESPEED_ENDPOINT` | `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` | The `runPagespeed` endpoint. |
| `categories` | — | `['performance', 'accessibility', 'best-practices', 'seo']` | Which Lighthouse categories to request. |
| `timeout` | `PAGESPEED_TIMEOUT` | 90 | Seconds to wait for one PageSpeed run. |
| `opportunities_limit` | — | 10 | How many opportunities to keep from each run, heaviest first. |
| `store_raw_response` | `PAGESPEED_STORE_RAW_RESPONSE` | false | Whether to keep the full payload on each stored result. |

Google publishes two hosts for the same API: the `www.googleapis.com` form used
here (from Google's own Get Started guide) and the canonical discovery form,
`https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed`. Either
works.

`categories` is written in the response-key spelling (lower-kebab). The client
translates each entry to the API's upper-snake request enum — `best-practices`
becomes `BEST_PRACTICES` — and repeats the `category` query parameter once per
entry. Omitting the parameter entirely would return performance only, which is a
silent way to lose three scores.

`timeout` sits well above a typical HTTP timeout because Google's own runs take
20–60 seconds and occasionally longer. Values that are not positive numbers fall
back to 90.

`store_raw_response` is off by default: a single response runs to hundreds of
kilobytes, so retaining every one grows the history table by two orders of
magnitude for data the package has already parsed into its own columns. Turn it
on temporarily when diagnosing a parsing problem against real responses, and see
[Retention](#retention) for the window that expires the payloads again.

## Queue

| Key | Env | Default | Meaning |
|---|---|---|---|
| `queue.connection` | `PAGESPEED_QUEUE_CONNECTION` | null | Connection to dispatch on. Null means the application default. |
| `queue.queue` | `PAGESPEED_QUEUE` | null | Queue name. Null means the application default. |
| `job.timeout` | `PAGESPEED_JOB_TIMEOUT` | 120 | Seconds one attempt may run for. |
| `job.tries` | `PAGESPEED_JOB_TRIES` | 3 | Attempts before a transient failure is recorded. |
| `job.backoff` | — | `[60, 300]` | Seconds to wait before each retry. |
| `job.quota_delay` | `PAGESPEED_QUOTA_DELAY` | 1800 | Seconds to postpone a quota-rejected run. |

A dedicated queue is worth considering on a busy application: a single PageSpeed
run blocks its worker for 20–60 seconds, so a scheduled cycle over a few dozen
URLs will starve anything sharing the queue with it.

`job.timeout` sits above `timeout` so the HTTP client — which knows which URL it
was testing — is the thing that gives up first.

`job.tries` applies to transient failures only. A missing API key is never
retried, because no amount of waiting produces one. A genuine quota rejection
*releases* the job with `job.quota_delay` rather than consuming a retry, so an
exhausted quota postpones a run instead of failing it.

## Rate limiting

| Key | Env | Default | Meaning |
|---|---|---|---|
| `rate_limit.per_minute` | `PAGESPEED_RATE_LIMIT_PER_MINUTE` | 30 | Requests started per minute, application-wide. `0` disables. |

Counted across every URL and every worker, because Google attributes quota to
the API key rather than to the page being tested. A job over the budget is
released back onto the queue, so a large cycle spreads itself out instead of
failing.

The default of 30 is deliberately conservative. Google does not publish a
PageSpeed Insights rate limit anywhere in its documentation, so the commonly
cited figures are folklore rather than fact. Check the quota page for your own
project in the Google Cloud Console before raising it.

## Scheduling

| Key | Env | Default | Meaning |
|---|---|---|---|
| `test_frequency` | `PAGESPEED_TEST_FREQUENCY` | `weekly` | Default cadence for a monitored URL with no per-URL override. |
| `scheduling.enabled` | `PAGESPEED_SCHEDULING_ENABLED` | true | Whether the package registers its own scheduled tasks. |
| `scheduling.persist_hook_urls` | — | true | Whether hook-contributed URLs are saved before a cycle. |

`test_frequency` supports `hourly`, `daily`, `weekly`, and `monthly`; anything
else falls back to `weekly`. Bear the API quota in mind: each run costs one
request per form factor, so 200 URLs on both mobile and desktop is 400 requests
per cycle.

With `scheduling.enabled` on, the package registers `pagespeed:monitor` and
`pagespeed:check-staleness` **hourly** and `pagespeed:prune` **daily**, all
guarded with `withoutOverlapping()`. Hourly is how often the package *looks*,
not how often it tests — a URL only comes due once its own interval has elapsed,
and `hourly` is itself a supported cadence, so anything less frequent would make
it unreachable. Turn the flag off to call the commands from your own schedule.

`scheduling.persist_hook_urls` is not really optional. A URL contributed through
`ap.pageSpeed.registerUrls` comes back as an unsaved model, which has nowhere to
record `last_tested_at` — so it would read as due on every cycle whatever its
cadence says, and its results would have no row to hang history from. The
scheduler is the one place in the package that calls
`UrlRegistry::persistHookUrls()` for you.

## Sitemap discovery

| Key | Env | Default | Meaning |
|---|---|---|---|
| `sitemap.url` | `PAGESPEED_SITEMAP_URL` | null | The sitemap to read. Null means `sitemap.xml` at `app.url`. |
| `sitemap.limit` | `PAGESPEED_SITEMAP_LIMIT` | 50 | The most URLs one run will discover. |
| `sitemap.timeout` | `PAGESPEED_SITEMAP_TIMEOUT` | 15 | Seconds per sitemap document. An index file means one run fetches several. |

The cap exists because a large site lists thousands of pages and every activated
one costs quota on every cycle. Discovered URLs are stored **inactive** unless
`pagespeed:discover-sitemap` is run with `--activate`. See
[Commands → `pagespeed:discover-sitemap`](commands.md#pagespeeddiscover-sitemap).

## Alerts

Documented in full in [Alerts](alerts.md). The keys:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `alerts.enabled` | `PAGESPEED_ALERTS_ENABLED` | true | The master switch. Off means nothing is compared, no hook fires, nothing is sent. |
| `alerts.drop_points` | `PAGESPEED_ALERT_DROP_POINTS` | 10 | Points a category may lose before it counts. `0` turns drop detection off, leaving the floors. |
| `alerts.thresholds.performance` | `PAGESPEED_ALERT_THRESHOLD_PERFORMANCE` | null | An absolute floor. Null means no floor. |
| `alerts.thresholds.accessibility` | `PAGESPEED_ALERT_THRESHOLD_ACCESSIBILITY` | null | As above. |
| `alerts.thresholds.best-practices` | `PAGESPEED_ALERT_THRESHOLD_BEST_PRACTICES` | null | As above. |
| `alerts.thresholds.seo` | `PAGESPEED_ALERT_THRESHOLD_SEO` | null | As above. |
| `alerts.skip_degraded` | — | true | Whether a run that lost data is passed over when looking for a baseline. |
| `alerts.channels` | — | `['mail']` | Notification channels. |
| `alerts.mail_to` | `PAGESPEED_ALERT_MAIL_TO` | null | Who to email. An array, or a comma-separated string for the env var. |
| `alerts.notifiable` | — | null | A class the container can resolve to something notifiable, notified alongside `mail_to`. |
| `alerts.digest.enabled` | — | true | Whether regressions are batched into one notification. |
| `alerts.digest.wait` | `PAGESPEED_ALERT_DIGEST_WAIT` | 300 | Seconds to gather regressions for. |
| `alerts.digest.store` | `PAGESPEED_ALERT_DIGEST_STORE` | null | Cache store holding the buffer. Null means the default. |
| `alerts.staleness.enabled` | `PAGESPEED_ALERT_STALENESS_ENABLED` | true | Whether URLs that stop reporting are alerted about. `alerts.enabled` off turns this off too. |
| `alerts.staleness.missed_cycles` | `PAGESPEED_ALERT_STALENESS_MISSED_CYCLES` | 2 | Expected runs a URL may miss before it is reported. Below 1 falls back to the default. |
| `alerts.staleness.repeat_after` | `PAGESPEED_ALERT_STALENESS_REPEAT_AFTER` | 86400 | Seconds before a still-stale URL alerts again. `0` alerts on every pass. |
| `alerts.staleness.store` | `PAGESPEED_ALERT_STALENESS_STORE` | null | Cache store holding the re-alert markers. Null falls back to the digest store, then the default. |

## HTTP routes

Documented in full in [HTTP endpoints](http-endpoints.md).

| Key | Env | Default | Meaning |
|---|---|---|---|
| `routes.enabled` | — | true | Whether the endpoints are registered at all. |
| `routes.prefix` | — | `pagespeed` | The path they sit under. |
| `routes.middleware` | — | `['web', 'auth']` | The stack they run through. |
| `routes.ability` | `PAGESPEED_ROUTES_ABILITY` | null | An optional Gate ability, appended to the stack as `can:` middleware. |
| `routes.allow_external_urls` | `PAGESPEED_ALLOW_EXTERNAL_URLS` | false | Whether `POST /test` and `POST /urls` accept URLs off this site. |

All five are read when the service provider boots. Replacing
`routes.middleware` replaces it **wholesale**, including the `auth` entry: the
package applies the stack you configure rather than adding a guard of its own on
top of it.

`routes.ability` is the exception, and exists because of that wholesale
replacement: it is **appended** to whatever `middleware` holds rather than
substituted for it, so adding an authorization check does not mean restating
`web` and `auth` and risking dropping one. Left unset — the default — the
middleware stack is the whole authorization decision and an authenticated user
is an authorized one. See [HTTP endpoints](http-endpoints.md) for when to set
it.

## Retention

| Key | Env | Default | Meaning |
|---|---|---|---|
| `retention.days` | `PAGESPEED_RETENTION_DAYS` | 365 | Delete results older than this. |
| `retention.keep_raw_days` | `PAGESPEED_RETENTION_KEEP_RAW_DAYS` | 30 | Null `raw_response` on results older than this, leaving the scores in place. |

Two windows rather than one, because the two things a result row holds cost
wildly different amounts to keep. A score row is a few dozen bytes and is the
entire reason history exists — a year keeps a full seasonal cycle, so this
year's Black Friday has last year's to be compared against. A retained
`raw_response` is hundreds of kilobytes of data the package has already parsed
into its own columns, kept only so a parsing problem can be diagnosed against a
real payload; a month-old payload has either answered that question or never
will. So the payload expires first and the row outlives it.

Both windows measure from `created_at` rather than `fetched_at`, because
`fetched_at` is Google's own analysis timestamp and is null on a failed run —
and retention has to be able to expire a failed row too.

Either window can be set to `0` to turn that half off. `0` for `retention.days`
means history is never deleted, which is a reasonable choice on a small
monitored set, but it is a choice rather than the default: an hourly cadence on
200 URLs across both form factors writes about 3.5 million rows a year. Anything
that is not a positive whole number of days no larger than a century — a blank
env var, a typo, a value so large that subtracting it from today overflows — is
also read as off, because the alternative reading is a cutoff in the future, and
a cutoff in the future matches the whole table.

See [Commands → `pagespeed:prune`](commands.md#pagespeedprune).

## Degraded runs and the `warnings` column

A run either fails outright or completes. But "completed" is not the same as
"complete": Lighthouse can return a payload that scored three categories out of
four, or scored them all while losing a lab metric, or returned no CrUX field
data at all. The parser tolerates each of those rather than failing the run —
and records what it tolerated.

That record is the `warnings` JSON column on `pagespeed_results`, with these
keys:

| Key | Holds |
|---|---|
| `run_warnings` | Lighthouse's own `runWarnings` strings, verbatim |
| `unrecognized_categories` | Categories in the response the package does not store — e.g. `agentic-browsing` after a Lighthouse release |
| `missing_categories` | Categories that were requested and did not come back |
| `missing_metrics` | Lab metric audit ids that were requested and did not come back |
| `missing_field_data` | Set when CrUX returned no field data for the page |

A run carrying any of those is **degraded**. It is still a completed run, still
stored, and still charted — but it is a weaker measurement than a clean one, and
the package treats it as such:

```php
$result->hasWarnings();
$result->wasDegraded();
$result->warningList();          // a flat, human-readable list
$result->scores()->unrecognized();
```

Three consequences worth knowing about:

- **The trend chart can look thin for a reason.** A category that stopped being
  measured plots as a gap rather than as a zero, and the gap is exactly what the
  `warnings` column explains.
- **A degraded run is skipped as an alert baseline** by default
  (`alerts.skip_degraded`), so a clean run following a thin one is not reported
  as a recovery it never made. A regression detected *on* a degraded run is
  still reported, and the notification says the run was degraded.
- **`pagespeed:test` prints every warning** and carries them in `--json`, so a
  degraded run and a clean one never look the same in a build log.

See [Troubleshooting → A run completed but the scores are partial](troubleshooting.md#a-run-completed-but-the-scores-are-partial).
