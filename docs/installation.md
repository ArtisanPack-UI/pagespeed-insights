---
title: Installation
---

# Installation

## Requirements

- PHP **8.2+**
- Laravel **10, 11, 12, or 13**
- A **PageSpeed Insights API key** — see below; the package cannot run without one
- [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google) **^1.0** — installed automatically. A connected Google account is optional and used only as an auth fallback.
- [`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks) **^1.2** — installed automatically; backs the `ap.pageSpeed.*` actions and filters
- **Livewire ^3.6** *(optional)* — required only for the Blade / Livewire components and the CMS-framework admin widget bridge
- **`artisanpack-ui/livewire-ui-components` ^2.1** *(optional)* — the Livewire panels render with `x-artisanpack-*` components when it is installed, and fall back to plain markup when it is not
- **`artisanpack-ui/cms-framework`** *(optional)* — the `cms` credential driver and the admin dashboard widgets

## Install

```bash
composer require artisanpack-ui/pagespeed-insights
```

The service provider and the `PageSpeedInsights` facade are auto-discovered.

Run the migrations. They create `pagespeed_urls` (the monitored set),
`pagespeed_results` (score history), and `pagespeed_configurations` (used only by
the `database` credential driver):

```bash
php artisan migrate
```

## Getting a PageSpeed Insights API key

A key is **required**. Google's shared anonymous project has a daily quota of
zero, so a keyless request answers HTTP 429 every time — see
[Troubleshooting → A 429 that means "no API key"](troubleshooting.md#a-429-that-actually-means-no-api-key).

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and sign in.
2. Pick an existing project from the project selector, or create one
   (**New project** → name it → **Create**).
3. Enable the API for that project: open
   [PageSpeed Insights API](https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com)
   in the API Library and click **Enable**. A key will not work until this is
   done, even if the key itself exists.
4. Go to **APIs & Services → Credentials**
   ([direct link](https://console.cloud.google.com/apis/credentials)) and click
   **Create credentials → API key**. Copy the key it shows you.
5. Click **Edit API key** and, under **API restrictions**, choose **Restrict key**
   and select **PageSpeed Insights API**. Leave **Application restrictions** set
   to **None** — the requests come from your server, not a browser, and an
   HTTP-referrer restriction would reject them.
6. Add it to your environment:

```dotenv
PAGESPEED_API_KEY=your-key-here
```

Billing is not required.

**Google does not publish the quota numbers for keyed projects anywhere** — not
in the Get Started guide, not in the API reference, and not in the FAQ. Check
**APIs & Services → PageSpeed Insights API → Quotas** in the Console for the
limits that actually apply to your project. This is also why
`rate_limit.per_minute` defaults to a conservative 30; see
[Configuration → Rate limiting](configuration.md#rate-limiting).

The key is sent on the `X-goog-api-key` header rather than as a `key=` query
parameter, so it never appears in a URL — a URL-borne key ends up in
connection-error messages, exception reports, and proxy logs.

## Where the key is stored

`PAGESPEED_CONFIG_DRIVER` selects the storage driver, mirroring
`GOOGLE_CONFIG_DRIVER` in [`artisanpack-ui/google`](https://github.com/ArtisanPack-UI/google):

| Driver | Storage | Writable |
|---|---|---|
| `config` *(default)* | `PAGESPEED_API_KEY` / `config( 'pagespeed-insights.api_key' )` | No — managed through config/env |
| `database` | `pagespeed_configurations` table, encrypted at rest | Yes |
| `cms` | CMS framework Settings module, encrypted at rest | Yes — requires `artisanpack-ui/cms-framework` |

The key is reachable through `PageSpeedInsights::config()`, which returns the
`ApiKeyRepository` for the active driver:

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

PageSpeedInsights::config()->isConfigured();    // false means PSI cannot run at all
PageSpeedInsights::config()->getApiKey();
PageSpeedInsights::config()->save( 'new-key' ); // database and cms drivers only
```

## Publishing

Four publish tags, none of them required to use the package:

```bash
php artisan vendor:publish --tag=pagespeed-insights-config      # config/pagespeed-insights.php
php artisan vendor:publish --tag=pagespeed-insights-migrations  # database/migrations
php artisan vendor:publish --tag=pagespeed-insights-views       # resources/views/vendor/pagespeed-insights
php artisan vendor:publish --tag=pagespeed-insights-js          # resources/js/vendor/pagespeed-insights
```

Migrations load from the package, so publishing them is only needed when you
want to edit the schema. The JS tag is required for the React and Vue
components — they ship as TypeScript sources rather than as a build, because
they take their styling from the host application's Tailwind and daisyUI theme
and so have to reach its pipeline before it compiles. See
[React components](react-components.md) and [Vue components](vue-components.md).

## First run

Check the key resolves, then test a page synchronously:

```bash
php artisan pagespeed:test https://example.com/
```

A run takes 20–60 seconds and sometimes longer. If it exits `3`, no API key is
configured; see [Commands → Exit codes](commands.md#exit-codes).

## Wiring up monitoring

Score history needs three things running:

1. **A queue worker.** Tests run as queued jobs. One run blocks a worker for
   20–60 seconds, so a dedicated queue is worth considering — see
   [Configuration → Queue](configuration.md#queue).
2. **Laravel's scheduler** (`php artisan schedule:work`, or the usual cron
   entry). The package registers `pagespeed:monitor` and
   `pagespeed:check-staleness` hourly and `pagespeed:prune` daily.
3. **At least one monitored URL**, added by hand, discovered from a sitemap, or
   contributed through the `ap.pageSpeed.registerUrls` filter:

```bash
php artisan pagespeed:discover-sitemap --activate
```

```php
use ArtisanPackUI\PageSpeedInsights\Urls\UrlRegistry;

app( UrlRegistry::class )->add( 'https://example.com/pricing', [ 'label' => 'Pricing' ] );
```

## Upgrading

The package follows semantic versioning. Because Lighthouse's own category and
metric lineup changes independently of it, see
[home → Compatibility policy](home.md#compatibility-policy) for what is and is
not guaranteed across a Lighthouse release.
