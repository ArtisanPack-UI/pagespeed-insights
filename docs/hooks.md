---
title: Hooks
---

# Hooks

Six extension points, built on
[`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks): two filters
and four actions, all named `ap.pageSpeed.*`.

| Hook | Kind | Fired |
|---|---|---|
| [`ap.pageSpeed.registerUrls`](#appagespeedregisterurls) | Filter | Whenever the monitored set is read |
| [`ap.pageSpeed.opportunities`](#appagespeedopportunities) | Filter | While parsing a run, before opportunities are returned |
| [`ap.pageSpeed.beforeTest`](#appagespeedbeforetest) | Action | Before a run is sent to Google |
| [`ap.pageSpeed.resultStored`](#appagespeedresultstored) | Action | After a result row is written |
| [`ap.pageSpeed.scoreRegressed`](#appagespeedscoreregressed) | Action | After a stored run is found to have regressed |
| [`ap.pageSpeed.urlWentStale`](#appagespeedurlwentstale) | Action | After a monitored URL is found to have stopped reporting |

Every one is guarded with a `function_exists()` check, so the package works
without the hooks package installed — the hooks simply never fire.

Each name is also available as a class constant, which is the better thing to
reference:

```php
UrlRegistry::FILTER_REGISTER_URLS;         // ap.pageSpeed.registerUrls
PageSpeedResponse::FILTER_OPPORTUNITIES;   // ap.pageSpeed.opportunities
RunPageSpeedTest::ACTION_BEFORE_TEST;      // ap.pageSpeed.beforeTest
RunPageSpeedTest::ACTION_RESULT_STORED;    // ap.pageSpeed.resultStored
RegressionDetector::ACTION_SCORE_REGRESSED; // ap.pageSpeed.scoreRegressed
StalenessDetector::ACTION_URL_WENT_STALE;  // ap.pageSpeed.urlWentStale
```

---

## `ap.pageSpeed.registerUrls`

**Filter.** Lets another package declare, at boot, that its own pages should be
monitored without writing to this package's table.

```php
applyFilters( 'ap.pageSpeed.registerUrls', array $urls ): array
```

| Argument | Type | Is |
|---|---|---|
| `$urls` | `array` | The contributions gathered so far. Always `[]` on the first callback. |

**Returns** the array with your entries added. Each entry may be:

- a URL string,
- an attribute array carrying at least a `url` key, or
- a `PageSpeedUrl` instance.

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

Settable attributes are `label`, `strategies`, `test_frequency`, and
`is_active`. `source` is not among them: how a URL entered monitoring is
recorded by the registry, not chosen by the caller, and a hook contribution is
always `hook`.

Hook URLs are returned as **unsaved** models. A package that registers
`/checkout` and is later uninstalled should stop contributing that URL, not
leave behind a row nobody remembers adding — so they gain no history and stay
read-only in the management UI until something calls
`UrlRegistry::persistHookUrls()`. The scheduler is the one place in the package
that calls it for you, under `scheduling.persist_hook_urls`.

Where a hook and a stored row name the same page, **the stored row wins**: it is
the one with history, a label somebody wrote, and an `is_active` flag somebody
chose.

An entry that cannot be used — a `mailto:` URL, a blank string, an array with no
`url` key — is dropped and logged rather than throwing, so one broken callback
in an unrelated package cannot take down the monitored set.

---

## `ap.pageSpeed.opportunities`

**Filter.** Reshape the pruned opportunity list before it is stored and
returned.

```php
applyFilters( 'ap.pageSpeed.opportunities', array $opportunities, PageSpeedRequest $request ): array
```

| Argument | Type | Is |
|---|---|---|
| `$opportunities` | `array<int, Opportunity>` | The pruned, sorted list — at most `opportunities_limit` entries |
| `$request` | `PageSpeedRequest` | The run this list came from: its URL, strategy, categories, and locale |

**Returns** an array of `Opportunity` instances.

```php
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedRequest;
use ArtisanPackUI\PageSpeedInsights\Api\PageSpeedResponse;

addFilter( PageSpeedResponse::FILTER_OPPORTUNITIES, function ( array $opportunities, PageSpeedRequest $request ): array {
    return array_values( array_filter(
        $opportunities,
        static fn ( $opportunity ): bool => 'redirects' !== $opportunity->id,
    ) );
} );
```

The filter is defended rather than trusted. A callback that returns a non-array
is ignored with a logged warning and the unfiltered list is used; entries that
are not `Opportunity` instances are dropped, and the count of dropped entries is
logged. Nothing here can turn a good run into a failed one.

Because a listener may reorder the list, every reader re-sorts by
`Opportunity::weight()` before rendering — see
[Livewire components → Opportunities table](livewire-components.md#opportunities-table).

---

## `ap.pageSpeed.beforeTest`

**Action.** Fired by `RunPageSpeedTest` immediately before a run is sent to
Google.

```php
doAction( 'ap.pageSpeed.beforeTest', string $url, string $strategy, ?PageSpeedUrl $monitored ): void
```

| Argument | Type | Is |
|---|---|---|
| `$url` | `string` | The canonical URL about to be tested |
| `$strategy` | `string` | `mobile` or `desktop` |
| `$monitored` | `?PageSpeedUrl` | The monitored row, or null for an ad hoc run |

```php
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;

addAction( RunPageSpeedTest::ACTION_BEFORE_TEST, function ( string $url, string $strategy, ?PageSpeedUrl $monitored ): void {
    // Warm a cache, put the site into a known state, record that quota is about to be spent.
} );
```

This runs inside the queued job, on a worker. It does not fire for
`pagespeed:test`, which is a CI path whose audience is the pipeline that invoked
it.

---

## `ap.pageSpeed.resultStored`

**Action.** Fired once a result row has been written — **including for a failed
row**, which is the point of the nullable second argument.

```php
doAction( 'ap.pageSpeed.resultStored', PageSpeedResult $row, ?TestResult $result ): void
```

| Argument | Type | Is |
|---|---|---|
| `$row` | `PageSpeedResult` | The saved row. `$row->status` is `completed` or `failed`. |
| `$result` | `?TestResult` | The parsed run, or **null** when the row records a failure |

```php
use ArtisanPackUI\PageSpeedInsights\Data\TestResult;
use ArtisanPackUI\PageSpeedInsights\Jobs\RunPageSpeedTest;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;

addAction( RunPageSpeedTest::ACTION_RESULT_STORED, function ( PageSpeedResult $row, ?TestResult $result ): void {
    if ( null === $result ) {
        // $row->error_message says why.
        return;
    }

    if ( $row->wasDegraded() ) {
        // The run completed but lost data — see $row->warningList().
    }
} );
```

Like `beforeTest`, this does not fire for `pagespeed:test --store`.

---

## `ap.pageSpeed.scoreRegressed`

**Action.** Fired once per regressed run, after detection and *before* the
digest window collapses anything.

```php
doAction( 'ap.pageSpeed.scoreRegressed', PageSpeedResult $row, array $regressions ): void
```

| Argument | Type | Is |
|---|---|---|
| `$row` | `PageSpeedResult` | The run that regressed |
| `$regressions` | `array<int, array>` | One entry per regressed category |

Each entry is a `Regression` rendered to an array:

| Key | Holds |
|---|---|
| `type` | `drop`, `threshold`, or `stopped` |
| `url`, `strategy`, `category` | What regressed |
| `current_score`, `previous_score` | Nullable ints. `current_score` is null on a `stopped` regression. |
| `points_lost` | The drop, or null when there is nothing to subtract |
| `threshold` | The configured floor, on a `threshold` regression |
| `result_id`, `previous_result_id` | The two runs compared |
| `degraded` | Whether the current run lost data |
| `label` | The monitored URL's label, when it has one |

```php
use ArtisanPackUI\PageSpeedInsights\Alerts\RegressionDetector;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;

addAction( RegressionDetector::ACTION_SCORE_REGRESSED, function ( PageSpeedResult $row, array $regressions ): void {
    foreach ( $regressions as $regression ) {
        if ( 'stopped' === $regression[ 'type' ] ) {
            // The category was scored last run and is not measured now.
        }
    }
} );
```

This fires whether or not anybody is configured to receive an email, which makes
it the supported way to route alerts somewhere of your own — see
[Alerts → Delivery](alerts.md#delivery).

---

## `ap.pageSpeed.urlWentStale`

**Action.** Fired once per stale URL by `pagespeed:check-staleness`, for each
URL reported in that pass. `--dry-run` does not fire it.

```php
doAction( 'ap.pageSpeed.urlWentStale', ?PageSpeedUrl $url, array $diagnosis ): void
```

| Argument | Type | Is |
|---|---|---|
| `$url` | `?PageSpeedUrl` | The monitored row, or null for an unsaved hook contribution |
| `$diagnosis` | `array` | What the detector worked out |

`$diagnosis` carries:

| Key | Holds |
|---|---|
| `url` | The canonical URL |
| `url_id` | The row id, or null |
| `label` | The label, when it has one |
| `frequency` | The URL's own cadence |
| `missed_cycles` | How many expected runs have been missed |
| `last_result_at` | ISO-8601 timestamp of the last completed run, or null if there has never been one |
| `cause` | `no_api_key`, `scheduling_disabled`, `failing`, or `not_running` |
| `cause_detail` | The `error_message` from a failed run, when the cause is `failing` |
| `failures` | How many failed rows have been written since the last good run |

```php
use ArtisanPackUI\PageSpeedInsights\Alerts\StalenessDetector;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;

addAction( StalenessDetector::ACTION_URL_WENT_STALE, function ( ?PageSpeedUrl $url, array $diagnosis ): void {
    if ( 'no_api_key' === $diagnosis[ 'cause' ] ) {
        // Configuration, not an outage.
    }
} );
```

The `alerts.staleness.repeat_after` window applies to this hook too: a URL that
stays broken re-fires it once a day rather than on every hourly pass. See
[Alerts → Repetition](alerts.md#repetition).

---

## Hooks this package consumes

None of its own. It registers the API key as a CMS-framework **setting** when
that package is installed — see [CMS framework](cms-framework.md) — but it does
not listen on any `ap.*` hook belonging to another package.
