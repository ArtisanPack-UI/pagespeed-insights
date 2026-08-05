---
title: Testing
---

# Testing

## Running the package's own suite

```bash
composer install
composer test          # Pest
composer lint          # PHP-CS-Fixer (dry run) + PHPCS
composer fix           # PHP-CS-Fixer, applied
composer cs            # PHPCS only
composer cs:fix        # PHPCBF

npm install
npm test               # Vitest, against the React and Vue components
npm run test:watch
npm run type-check     # vue-tsc --noEmit
```

Feature tests boot a full Testbench application through `Tests\TestCase`; unit
tests stay framework-free so they run without the container.

**Nothing in this package calls the live PageSpeed API from a test.** Runs are
served from checked-in response fixtures via the `psiFixture( $name )` helper.

## Testing an application that uses the package

### Never let a test reach Google

Fake the HTTP client. A run costs quota, takes 20–60 seconds, and gives a
different answer every time — three separate reasons not to do it in a suite.

```php
use Illuminate\Support\Facades\Http;

Http::fake( [
    'www.googleapis.com/pagespeedonline/*' => Http::response( [
        'lighthouseResult' => [
            'categories' => [
                'performance' => [ 'id' => 'performance', 'score' => 0.92 ],
            ],
            'audits' => [],
        ],
    ] ),
] );
```

For anything beyond a smoke test, prefer seeding result rows directly — see
[Factories](#factories). The parser's own behaviour is already covered by this
package's suite; your application's tests are about what it does with a result,
not about how the payload is read.

### Give it a key, or assert that it has none

Most paths short-circuit without an API key, which is easy to mistake for a
broken test:

```php
config()->set( 'pagespeed-insights.driver', 'config' );
config()->set( 'pagespeed-insights.api_key', 'test-key' );
```

The `config` driver is the one to use in tests: it needs no migration and no
encryption, and `ApiKeyRepository` is a plain container bind, so changing
`pagespeed-insights.driver` mid-test takes effect on the next resolve.

To exercise the missing-key path instead, set `api_key` to `null` and assert on
`MissingApiKeyException`, exit code `3` from `pagespeed:test`, or the
`no_api_key` endpoint error.

### Turn off what you are not testing

```php
config()->set( 'pagespeed-insights.scheduling.enabled', false );
config()->set( 'pagespeed-insights.alerts.enabled', false );
config()->set( 'pagespeed-insights.rate_limit.per_minute', 0 );
```

Most configuration is read on resolve rather than at boot, so it can be changed
from inside a test. **Three keys cannot**: `routes.enabled`, `routes.prefix`,
and `routes.middleware` are read when the service provider boots. Set those in
`defineEnvironment()` (Testbench) or in the test's config file.

### The digest is a delayed job

`alerts.digest` buffers regressions and flushes them with a **delayed** job, and
the `sync` queue driver ignores delays. On a sync queue every regression sends
immediately.

Either set `alerts.digest.enabled` to `false` and assert on the notification
directly, or use `Queue::fake()` and assert the flush job was dispatched.

## Factories

Both models ship factories with states covering the cases worth asserting on:

```php
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedResult;
use ArtisanPackUI\PageSpeedInsights\Models\PageSpeedUrl;

$url = PageSpeedUrl::factory()->create( [ 'url' => 'https://example.com/pricing' ] );

PageSpeedResult::factory()->for( $url, 'pageSpeedUrl' )->create();
```

### `PageSpeedUrlFactory`

| State | Gives you |
|---|---|
| `inactive()` | A paused URL — skipped by the scheduler and the staleness detector |
| `neverTested()` | A null `last_tested_at` |
| `due( $frequency )` | A URL whose cadence has elapsed |
| `notDue( $frequency )` | One whose cadence has not |
| `fromSitemap()` | `source = sitemap` |
| `strategy( $strategy )` | A URL tested on one form factor only |

### `PageSpeedResultFactory`

| State | Gives you |
|---|---|
| `failed( $message )` | A `failed` row with an `error_message` and no scores |
| `poor()` | Scores below every band floor — useful for threshold alerts |
| `degraded()` | A completed run carrying `warnings`, so `wasDegraded()` is true |
| `withoutFieldData()` | A completed run with no CrUX data |
| `withRawResponse( $payload )` | A row with `raw_response` set, for retention tests |
| `desktop()` | `strategy = desktop` |

`degraded()` is the one to reach for when testing anything that treats a thin
run differently — alert baselines, the score card's `degraded` state, the
`--json` warnings block. See
[Configuration → Degraded runs](configuration.md#degraded-runs-and-the-warnings-column).

## Testing the components

### Livewire

```php
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use Livewire\Livewire;

Livewire::test( ScoreCard::class, [ 'url' => 'https://example.com/pricing' ] )
    ->assertSet( 'state', ScoreCard::STATE_LOADED )
    ->assertSee( '92' );
```

Assert against the `STATE_*` constants rather than against rendered strings
where you can: the states are the contract, and the wording is translatable.

`$url` and `$strategy` are `#[Locked]`, so a test cannot `set()` them — pass
them to `mount()`.

### React and Vue

Both sets take a `fetchImpl` prop, which is the supported injection point for a
test double. There is no need to intercept `window.fetch`:

```tsx
render(<ScoreCard url="https://example.com/pricing" fetchImpl={fakeFetch} />)
```

`endpointBase` is worth setting explicitly in tests too, so a suite does not
depend on `routes.prefix` being the default.

## Optional-dependency detection

Three support classes decide whether an optional package is installed, and each
caches the answer for the process. Tests that need to pretend otherwise can
override them:

```php
use ArtisanPackUI\PageSpeedInsights\Support\CmsFrameworkInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\LivewireInstalled;
use ArtisanPackUI\PageSpeedInsights\Support\UiComponentsInstalled;

UiComponentsInstalled::setForTesting( false );  // render the fallback markup
UiComponentsInstalled::reset();                 // back to real detection
```

Reset all three in `tearDown()` — the package's own `Tests\TestCase` does — or a
later test inherits the pretence.

## CI

`pagespeed:test` is the command built for a pipeline: it runs synchronously and
exits with one code per failure class. See
[Commands → `pagespeed:test`](commands.md#pagespeedtest) and its
[exit codes](commands.md#exit-codes).

```yaml
- run: php artisan pagespeed:test "$DEPLOY_URL" --min-performance=90 --max-lcp-ms=2500 --json
```

Budget the categories you actually requested — a budget on an unrequested
category is refused before the request goes out — and remember that a budgeted
measurement which did not come back exits `4` rather than passing.
