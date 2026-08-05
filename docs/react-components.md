---
title: React Components
---

# React components

Five React components mirroring the five Livewire ones, built on
[`@artisanpack-ui/react`](https://www.npmjs.com/package/@artisanpack-ui/react)
and reading the [JSON endpoints](http-endpoints.md).

They ship as **TypeScript sources rather than as a build**, because they take
their styling from the host application's Tailwind and daisyUI theme and so have
to reach its pipeline before it compiles.

## Install

```bash
php artisan vendor:publish --tag=pagespeed-insights-js
npm install @artisanpack-ui/react
```

That puts `resources/js/{react,shared}` under
`resources/js/vendor/pagespeed-insights`. Import from the barrel:

```tsx
import {
    ScoreCard,
    CoreWebVitalsCard,
    OpportunitiesTable,
    TrendChart,
    UrlManager,
} from '@/../js/vendor/pagespeed-insights/react'

export function Dashboard() {
    return (
        <>
            <ScoreCard url="https://example.com/pricing" />
            <CoreWebVitalsCard url="https://example.com/pricing" />
            <OpportunitiesTable url="https://example.com/pricing" />
            <TrendChart url="https://example.com/pricing" />
            <UrlManager />
        </>
    )
}
```

The barrel re-exports the components, their prop types, and the whole
[shared fetch layer](#shared-fetch-layer), so an application writing its own UI
can use the typed clients without the components.

## Props

| Prop | Components | Type | Default | Notes |
|---|---|---|---|---|
| `url` | all but `UrlManager` | `string` | — | The URL to read. Must be one this installation monitors. |
| `strategy` | score card, vitals, opportunities | `'mobile' \| 'desktop'` | `'mobile'` | The form factor. |
| `endpointBase` | all | `string` | `/pagespeed` | Set this when `routes.prefix` is not the default. |
| `fetchImpl` | all | `typeof fetch` | `window.fetch` | Injectable fetch, for SSR and for tests. |
| `csrfToken` | score card, URL manager | `string \| null` | `<meta name="csrf-token">` | Needed by the writing endpoints on the default `web` stack. |
| `initialMetric` | trend chart | `string` | `'performance'` | Which measurement is plotted first. |
| `initialRange` | trend chart | `number` | `90` | Over how many days: 7, 30, 90, or 365. |
| `allowRunningTests` | score card | `boolean` | `true` | Whether to offer the "Run test" button. |
| `onResultStored` | score card | `(id) => void` | — | Called with the stored result id once a queued run finishes. |

## States

Each component renders the same states its
[Livewire counterpart](livewire-components.md#states) does, and for the same
reason: a React card, a Vue card, and a Livewire card describing the same stored
row must not disagree about what it says. So "the Chrome UX Report has no data
for this page" and "these numbers describe the whole site" stay two different
messages, an unscored category renders as an em dash rather than a zero, and an
empty opportunities list is spelled three ways — never measured, measured and
clean, or the run failed.

Two things are drawn by hand rather than by the component library:

- **The trend line** is inline SVG. A trend carries one series per form factor,
  each on its own timestamps, with nulls where a completed run lost the
  measurement; the library's `Chart` takes a series as a plain `number[]`
  against shared labels, which expresses neither — and it pulls in ApexCharts,
  an optional peer a host application need not have installed. A gap in the
  history stays a gap in the line.
- **The alerts** pair a title with a description, which the library's `Alert`
  takes as children.

## Keeping the panels in step

The score card is the only component that queues a run, so it is the only one
that knows when a new row lands — and it announces it, exactly as the Livewire
card dispatches `pagespeed-insights:result-stored`. The vitals card and the
opportunities table refresh when the announcement names their URL *and* their
form factor; the trend chart refreshes on either form factor, because it plots
both. A timed-out ticket announces nothing: no row appeared, so there is nothing
for the others to re-read.

The announcement lives in the shared layer rather than in either component set,
so a page can join in from anywhere — including one built with neither component
set, since it is also dispatched on `window`:

```ts
import { onPsiResultStored, PSI_EVENT_RESULT_STORED } from '@/../js/vendor/pagespeed-insights/shared'

// Returns an unsubscribe function.
const stop = onPsiResultStored(({ url, strategy, id }) => reloadMyPanel(url, strategy))

// Or, from a plain script on a Blade page.
window.addEventListener(PSI_EVENT_RESULT_STORED, (event) => reloadMyPanel(event.detail))
```

## Hooks

The components are built from two hooks, both exported:

```ts
import { usePsiResource, usePsiResultStored } from '@/../js/vendor/pagespeed-insights/react'
```

- `usePsiResource(deps, load)` — the load lifecycle: pending, loaded, and error
  states, with the in-flight request cancelled when `deps` change.
- `usePsiResultStored(handler)` — subscribes to the finished-run announcement
  for the life of the component. It holds the handler in a ref, so it need not
  be memoised.

## Shared fetch layer

`resources/js/shared` is framework-free TypeScript: one typed client per
endpoint (`scores.ts`, `core-web-vitals.ts`, `opportunities.ts`, `trends.ts`,
`urls.ts`, `runs.ts`), the request plumbing they share (`client.ts`), the
finished-run announcement (`events.ts`), and the names and colours the endpoints
deliberately do not send (`labels.ts`).

Both component sets consume the same modules, so both frameworks talk to one
server payload rather than to two hand-written approximations of it.

Every refusal arrives as a `PageSpeedInsightsError` carrying the endpoint's
stable `code` alongside its prose `message`, so a client branches on the code
rather than on the wording:

```ts
import { fetchPsiScores, PageSpeedInsightsError } from '@/../js/vendor/pagespeed-insights/shared'

try {
    const scores = await fetchPsiScores({ url: 'https://example.com/pricing' })
} catch (error) {
    if (error instanceof PageSpeedInsightsError && 'url_not_monitored' === error.code) {
        // Offer to add it to the monitored set.
    }
}
```

The codes are listed in [HTTP endpoints → Errors](http-endpoints.md#errors).

## CSRF

The default route stack includes `web`, so `POST` and `DELETE` requests need a
CSRF token like any other session-authenticated form post. The components read
`<meta name="csrf-token">` when `csrfToken` is not passed. If you are calling
from something with no session, move the endpoints onto a stateless stack — see
[HTTP endpoints → Configuration](http-endpoints.md#configuration).
