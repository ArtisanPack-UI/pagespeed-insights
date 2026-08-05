---
title: Vue Components
---

# Vue components

The same five components for Vue 3, built on
[`@artisanpack-ui/vue`](https://www.npmjs.com/package/@artisanpack-ui/vue),
reading the same [JSON endpoints](http-endpoints.md) through the same shared
layer, and rendering the same states as the
[React](react-components.md) and [Livewire](livewire-components.md) sets.

They ship as single-file components under `resources/js/vue`, as TypeScript
sources rather than as a build, for the same reason the React set does: they
take their styling from the host application's Tailwind and daisyUI theme.

## Install

```bash
php artisan vendor:publish --tag=pagespeed-insights-js
npm install @artisanpack-ui/vue
```

```vue
<script setup lang="ts">
import {
    ScoreCard,
    CoreWebVitalsCard,
    OpportunitiesTable,
    TrendChart,
    UrlManager,
} from '@/../js/vendor/pagespeed-insights/vue'
</script>

<template>
    <ScoreCard url="https://example.com/pricing" />
    <CoreWebVitalsCard url="https://example.com/pricing" />
    <OpportunitiesTable url="https://example.com/pricing" />
    <TrendChart url="https://example.com/pricing" />
    <UrlManager />
</template>
```

## Props

The same props as the [React set](react-components.md#props), spelled as Vue
props:

| Prop | Components | Type | Default |
|---|---|---|---|
| `url` | all but `UrlManager` | `string` | — |
| `strategy` | score card, vitals, opportunities | `'mobile' \| 'desktop'` | `'mobile'` |
| `endpoint-base` | all | `string` | `/pagespeed` |
| `fetch-impl` | all | `typeof fetch` | `window.fetch` |
| `csrf-token` | score card, URL manager | `string \| null` | `<meta name="csrf-token">` |
| `initial-metric` | trend chart | `string` | `'performance'` |
| `initial-range` | trend chart | `number` | `90` |
| `allow-running-tests` | score card | `boolean` | `true` |

One difference from React: `onResultStored` is a **component event** here, so a
finished run is heard with `@result-stored` rather than by passing a function
down.

```vue
<ScoreCard url="https://example.com/pricing" @result-stored="( id ) => refreshMyPanel( id )" />
```

## Composables

The composables the components are built from are exported alongside them:

```ts
import { usePsiResource, usePsiResultStored } from '@/../js/vendor/pagespeed-insights/vue'
```

- `usePsiResource(deps, load)` — the load lifecycle. It takes its dependencies
  as an **explicit getter** rather than inferring them, so a value read after an
  `await` inside `load` cannot silently stop being tracked.
- `usePsiResultStored(handler)` — the finished-run announcement. It unsubscribes
  with the surrounding effect scope.

## Two things that differ beneath the surface

Neither changes what renders:

- The components import the library from `@artisanpack-ui/vue` rather than from
  its `/layout`, `/display`, and `/form` subpaths, which is where the React half
  imports its own. The published Vue package declares those subpaths but ships
  no declaration file for any of them, and losing the component props' types is
  a worse trade than importing a barrel a bundler will tree-shake anyway.
- The library's Vue `Stat` takes no colour prop, so a banded number is coloured
  with a written-out `[&_.stat-value]:text-success` class rather than with
  `color`.

## Shared fetch layer, events, and CSRF

Identical to the React set — the shared layer is framework-free and consumed by
both. See:

- [React components → Shared fetch layer](react-components.md#shared-fetch-layer)
- [React components → Keeping the panels in step](react-components.md#keeping-the-panels-in-step)
- [React components → CSRF](react-components.md#csrf)

## Type checking

```bash
npm run type-check   # vue-tsc --noEmit
```
