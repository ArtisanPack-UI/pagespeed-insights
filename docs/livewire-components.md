---
title: Livewire Components
---

# Livewire components

Five Blade / Livewire panels, registered automatically when
`livewire/livewire` is installed. Nothing to publish and nothing to wire up.

| Tag | Class | Renders |
|---|---|---|
| `<livewire:pagespeed-score-card />` | `Livewire\ScoreCard` | The four Lighthouse category scores, and a "Run test" button |
| `<livewire:pagespeed-core-web-vitals />` | `Livewire\CoreWebVitalsCard` | LCP, INP, and CLS from CrUX field data |
| `<livewire:pagespeed-opportunities />` | `Livewire\OpportunitiesTable` | What Lighthouse says is worth fixing, heaviest first |
| `<livewire:pagespeed-trend-chart />` | `Livewire\TrendChart` | One measurement plotted over time, mobile against desktop |
| `<livewire:pagespeed-url-manager />` | `Livewire\UrlManager` | The monitored set, with add, pause, and remove |

Livewire is a **suggested** dependency rather than a required one — the
commands, the queued job, and the alerting are all useful on an application with
no front end — so an install without it boots exactly as it did before these
components existed rather than failing on a missing base class.

## Usage

```blade
<livewire:pagespeed-score-card url="https://example.com/pricing" />
<livewire:pagespeed-core-web-vitals url="https://example.com/pricing" strategy="desktop" />
<livewire:pagespeed-opportunities url="https://example.com/pricing" />
<livewire:pagespeed-trend-chart url="https://example.com/pricing" metric="performance" range="90" />
<livewire:pagespeed-url-manager />
```

## Props

| Prop | Components | Type | Default | Notes |
|---|---|---|---|---|
| `url` | all but the URL manager | `string` | `''` | The URL to read. Canonicalized on mount, so it matches however it is spelled. |
| `strategy` | score card, vitals, opportunities | `'mobile' \| 'desktop'` | `'mobile'` | The form factor. |
| `metric` | trend chart | `string` | `'performance'` | `performance`, `accessibility`, `best-practices`, `seo`, or a lab metric audit id. |
| `range` | trend chart | `int` | `90` | Days of history: 7, 30, 90, or 365. Anything else falls back to 90. |

The URL manager takes no props: it describes the whole monitored set.

`$url` and `$strategy` are `#[Locked]` on every component that has them, so a
mounted card cannot be retargeted from the browser. `$metric` and `$range` on
the trend chart are not locked — they are the controls the user is meant to
change — but both are normalized server-side on every update.

## Authorization

**These components carry no gate of their own. Mounting one is the
authorization decision.** Whoever can see a page with
`<livewire:pagespeed-url-manager />` on it can add and remove monitored URLs,
and whoever can see a score card can spend a slice of the API quota with its
"Run test" button. That is deliberate: a package cannot know what an
application's admin role is called. Put the components behind whatever
middleware or `@can` check your application already uses.

Where "can view" is broader than "can spend quota" on your installation, split
the surfaces: put the score card on a page your read-only users reach and the
URL manager on one they do not. (The React and Vue score cards take an
`allowRunningTests` prop for this; the Livewire card always offers the button,
so the placement decision is the whole of the control.)

## States

Every panel renders a **named state** rather than leaving a viewer to infer one
from an empty box. An empty opportunities list means "nothing found" or "nothing
looked for"; an empty vitals panel means "CrUX has no data" or "the run failed";
a blank trend means "no history" or "no history *in this range*". Those pairs
have different remedies, so they are different states.

### Score card

| State | Means |
|---|---|
| `no-api-key` | No key is configured, so no run can succeed |
| `empty` | No result has been stored for this URL and form factor |
| `failed` | The newest run failed; `errorMessage` says why |
| `degraded` | The run completed but lost a category, a metric, or field data |
| `loaded` | A clean, complete run |

The card polls every 5 seconds while a queued run is in flight, and gives up
after 10 minutes (`RUN_TIMEOUT_SECONDS`) — a stalled queue worker must not
present as a slow one.

### Core Web Vitals card

| State | Means |
|---|---|
| `empty` | No result stored |
| `failed` | The newest run failed |
| `no-field-data` | The run succeeded, but CrUX has no data for this page |
| `origin-level` | CrUX fell back to origin-level data; these numbers describe the whole site |
| `loaded` | Page-level field data |

`no-field-data` and `origin-level` are kept apart because they mean different
things to a reader: the first is "not enough traffic to this page yet", the
second is "these numbers are real, but they are not about this page". Field data
is reported at the 75th percentile, which is what CrUX publishes.

### Opportunities table

| State | Means |
|---|---|
| `empty` | No result stored |
| `failed` | The newest run failed |
| `not-measured` | The performance category was not requested, so nothing was looked for |
| `none` | Measured, and Lighthouse found nothing worth listing |
| `loaded` | Rows, heaviest first |

Rows are sorted by `Opportunity::weight()` descending, which takes the larger of
Lighthouse's overall estimate and its largest per-metric estimate. The sort is
repeated here rather than trusted, because rows written by an older version of
this package — or reshaped by a listener on
[`ap.pageSpeed.opportunities`](hooks.md#appagespeedopportunities) — can be in
any order at all, and a table headed "estimated saving" whose largest number is
halfway down reads as broken.

### Trend chart

| State | Means |
|---|---|
| `empty` | No history at all |
| `out-of-range` | History exists, but none of it falls inside the selected range |
| `insufficient` | Fewer than 2 usable points — a dot, not a trend |
| `loaded` | A plotted series |

The chart also reports `hasGaps` (a completed run that lost this measurement),
`hasOlderHistory`, and `truncated` (the 500-point cap bit). A silently shortened
history is exactly the kind of quiet misreading a trend exists to prevent.

### URL manager

Rows carry a per-strategy measurement state: `none`, `failed`, `unavailable`, or
`scored`, so a blank desktop column on a mobile-only URL does not read as a
desktop run that failed. The list is capped at 250 rows and reports
`truncated` and `totalCount` when it bites.

**Hook-contributed rows are listed but read-only.** URLs registered through
[`ap.pageSpeed.registerUrls`](hooks.md#appagespeedregisterurls) appear because an
operator asking "which URLs are monitored?" has to see them — they cost quota on
every cycle exactly like a stored row does. But they are unsaved models owned by
whichever package registered them, so there is nothing to pause, relabel, or
delete: the next request would rebuild them from the filter regardless. They
carry a `hook` badge and no controls.

## Keeping the panels in step

The score card is the only component that queues a run, so it is the only one
that knows when a new row lands. It dispatches a Livewire event when one does:

```php
ScoreCard::EVENT_RESULT_STORED; // 'pagespeed-insights:result-stored'
```

The vitals card and the opportunities table refresh when the announcement names
their URL *and* their form factor; the trend chart refreshes on either form
factor, because it plots both. Without this a page shows fresh scores beside
three panels describing the previous run, which reads as a page that has
finished updating when it has not.

A timed-out ticket announces nothing: no row appeared, so there is nothing for
the others to re-read.

Your own components can listen for the same event:

```php
use ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard;
use Livewire\Attributes\On;

#[On( ScoreCard::EVENT_RESULT_STORED )]
public function onPageSpeedResult( string $url = '', string $strategy = '' ): void
{
    // ...
}
```

## Styling

The panels render with `x-artisanpack-*` components from
[`artisanpack-ui/livewire-ui-components`](https://gitlab.com/jacob-martella-web-design/artisanpack-ui/livewire-ui-components)
when it is installed, and fall back to plain markup when it is not — each
component exposes `$uiComponentsInstalled` so a published view can branch on it
too.

Two things are drawn by hand rather than by the component library:

- **The trend line** is inline SVG. A trend carries one series per form factor,
  each on its own timestamps, with nulls where a completed run lost the
  measurement; the library's `Chart` takes a series as a plain `number[]`
  against shared labels, which expresses neither — and it pulls in ApexCharts,
  an optional peer a host application need not have installed. A gap in the
  history stays a gap in the line.
- **The alerts** pair a title with a description, which the library's `Alert`
  takes as children.

To change the markup, publish the views:

```bash
php artisan vendor:publish --tag=pagespeed-insights-views
```

They land in `resources/views/vendor/pagespeed-insights/livewire/`.

## Rendering the same panels elsewhere

- In React or Vue, see [React components](react-components.md) and
  [Vue components](vue-components.md). All three sets render the same states
  from the same stored rows, deliberately: a React card, a Vue card, and a
  Livewire card describing the same row must not disagree about what it says.
- On a CMS-framework admin dashboard, three of them register as widgets — see
  [CMS framework](cms-framework.md).
