---
title: CMS Framework
---

# CMS framework

With [`artisanpack-ui/cms-framework`](https://gitlab.com/jacob-martella-web-design/artisanpack-ui/artisanpack-ui-cms-framework)
installed, this package gains two optional bridges: an API key **setting**, and
three admin dashboard **widgets**.

Both are entirely optional in both directions. Without the CMS framework — or,
for the widgets, without Livewire — the provider registers nothing and the
package boots exactly as it does otherwise. It stays CMS-agnostic.

## The `cms` credential driver

Set the driver to store the PageSpeed API key through the CMS framework's
Settings module rather than in env or in this package's own table:

```dotenv
PAGESPEED_CONFIG_DRIVER=cms
```

The key is **encrypted at rest**. Encryption is owned by the setting's sanitize
callback rather than by the driver, because the value reaches that row by two
paths — `CmsSettingsDriver::save()` and an operator typing it into the CMS
Settings UI — and only a sanitizer sees both. That way the read side always has
something to decrypt.

The setting is registered inside `$this->app->booted()`, because the CMS-framework
helpers (`apRegisterSetting`, `apGetSetting`, `apUpdateSetting`) are declared
from that package's own `boot()` and Laravel's provider boot order is not
deterministic. Registering directly from this package's `boot()` would silently
skip the key whenever this provider happened to boot first.

The driver reads and writes through the same repository interface as the others:

```php
use ArtisanPackUI\PageSpeedInsights\Facades\PageSpeedInsights;

PageSpeedInsights::config()->isConfigured();
PageSpeedInsights::config()->save( 'new-key' );
```

See [Configuration → Credentials](configuration.md#credentials) for the other
two drivers.

## Admin dashboard widgets

Three of the [Livewire components](livewire-components.md) register themselves
as admin dashboard widgets. Nothing to publish and nothing to wire up: they
appear in the dashboard's "Add Widget" panel.

| Widget type | Class | Renders |
|---|---|---|
| `pagespeed-insights.score-card` | `Bridges\CmsFramework\AdminWidgets\ScoreCardWidget` | The four Lighthouse category scores |
| `pagespeed-insights.core-web-vitals` | `Bridges\CmsFramework\AdminWidgets\CoreWebVitalsWidget` | LCP, INP, and CLS from CrUX field data |
| `pagespeed-insights.trend-chart` | `Bridges\CmsFramework\AdminWidgets\TrendChartWidget` | One measurement plotted over time |

Each wrapper extends the Livewire component it exposes, so the dashboard renders
it the same way it renders any other Livewire-backed widget — by class name, or
by the `pagespeed-score-card-widget`, `pagespeed-core-web-vitals-widget`, and
`pagespeed-trend-chart-widget` aliases. The base aliases
(`pagespeed-score-card`, and so on) still point at the base components.

This is an optional bridge on top of an optional bridge: the wrapper classes
name CMS-framework types in their `implements` clause and extend Livewire
components, so neither may be referenced until **both** packages are present.

### Authorization

All three ask for the `view_pagespeed_insights` capability, which the CMS
framework enforces. The widgets carry no gate of their own — the same stance the
[Livewire components](livewire-components.md#authorization) take.

**Placing a widget is therefore the authorization decision.** Whoever can add or
configure one chooses the address it tests, and a run spends a slice of the
PageSpeed API quota and stores history for that address. The URL is `#[Locked]`,
so a *viewer* of the dashboard cannot retarget a placed widget from the request
payload.

### Which URL a widget describes

A widget dropped on a dashboard from the picker has no URL — there is nowhere in
that panel to type one — so it describes **the application's own home page**,
taken from `app.url` and canonicalized the same way every stored URL is. That is
what makes the widget read the same history rows the monitored home page writes.

The URL is resolved on every render rather than baked into the widget's saved
options, so a site that changes domain does not leave three widgets pointing at
the old one.

An `app.url` that is not a testable http(s) address leaves the widget with no
URL and the component's own honest empty state, rather than an invented address
nobody asked about.

## Registering the widgets yourself

`PageSpeedInsightsServiceProvider::cmsFrameworkWidgetTypeMap()` returns the
type → class map above **without booting anything** and without the CMS
framework needing to be installed to ask its manager:

```php
use ArtisanPackUI\PageSpeedInsights\PageSpeedInsightsServiceProvider;

foreach ( PageSpeedInsightsServiceProvider::cmsFrameworkWidgetTypeMap() as $type => $class ) {
    // Register them somewhere of your own.
}
```

That is also what the package's own tests assert against, so the map cannot
drift from what the provider registers.
