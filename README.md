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
