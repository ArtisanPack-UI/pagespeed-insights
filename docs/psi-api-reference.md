---
title: PSI API Reference
---

# PageSpeed Insights API — verified reference

Implementation notes for the PSI API as it actually behaves, captured while
scaffolding this package. Everything below was checked against live sources on
the date shown, not copied from the planning document.

This is the reference the implementation is built against. For what the package
*does* with it, start at [home](home.md).

**Verified:** 2026-08-01
**Sources:**

- [Get Started with the PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started)
- [Method: pagespeedapi.runpagespeed](https://developers.google.com/speed/docs/insights/rest/v5/pagespeedapi/runpagespeed)
- The live discovery document: `https://www.googleapis.com/discovery/v1/apis/pagespeedonline/v5/rest` — authoritative for parameter enums and schema field names
- A live (keyless) request to the API, which returned the quota error quoted in [Quotas](#quotas)

Re-verify before each release. Lighthouse changes its category and metric
lineup on roughly a two-release cadence and the parser has to tolerate it.

---

## Endpoint

| | |
|---|---|
| Canonical (discovery `rootUrl` + `path`) | `https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed` |
| Documented alias (Get Started guide) | `https://www.googleapis.com/pagespeedonline/v5/runPagespeed` |
| Method | `GET` |

Both hosts work. The plan and the default config use the `www.googleapis.com`
form because that is the one Google's own guide publishes.

## Query parameters

From the discovery document — this is the authoritative list, and it is a
superset of the published reference table.

| Parameter | Type | Repeatable | Required | Enum values |
|---|---|---|---|---|
| `url` | string | no | **yes** | — |
| `strategy` | string | no | no | `STRATEGY_UNSPECIFIED`, `DESKTOP`, `MOBILE` |
| `category` | string | **yes** | no | `CATEGORY_UNSPECIFIED`, `ACCESSIBILITY`, `BEST_PRACTICES`, `PERFORMANCE`, `PWA`, `SEO`, `AGENTIC_BROWSING` |
| `locale` | string | no | no | — |
| `captchaToken` | string | no | no | — |
| `utm_campaign` | string | no | no | — |
| `utm_source` | string | no | no | — |
| `key` | string | no | no | API key; appended to the URL, no encoding needed |

**Prefer the `X-goog-api-key` header over `key=`.** Verified working against
`runPagespeed` on 2026-08-02 (HTTP 200 with the header alone, no `key=`
parameter). It is not in the published parameter table because it is not a
parameter — it is the standard Google APIs header form, and it keeps the
credential out of URLs. That matters because Guzzle appends the full request
URI to its connection-error messages, so a URL-borne key lands in exception
reports and logs verbatim. This package sends the header.

Notes that matter for the client:

- **Request enum casing differs from response key casing.** The request takes
  `BEST_PRACTICES`; the response nests the same category under
  `lighthouseResult.categories["best-practices"]`. The client must translate
  in both directions rather than assuming one spelling.
- **`category` is repeatable.** Ask for all four by repeating the parameter:
  `&category=PERFORMANCE&category=ACCESSIBILITY&…`. Omitting it returns
  **performance only**, which is an easy way to silently lose three scores.
- **`strategy` defaults to desktop.** The published reference states desktop;
  discovery exposes `STRATEGY_UNSPECIFIED`. Always send an explicit strategy.
- **`AGENTIC_BROWSING` is new** and is not in the planning document's category
  list. `PWA` is marked `deprecated: true` in the schema — "deprecated in
  Lighthouse's 12.0 release". Together these are the concrete argument for the
  parser tolerating unknown and absent categories instead of hard-coding four.
- `captchaToken` exists in the API but is not in the published parameter table.

## Quotas

The published documentation does **not** state quota numbers anywhere — not in
the Get Started guide, the API reference, or the FAQ. What is verifiable:

**Keyless requests are effectively dead.** Reproduced twice — 2026-08-01 and
again on 2026-08-02, where the identical request returned 200 with a key on
the `X-goog-api-key` header and 429 with no credential at all. The 2026-08-01
probe returned HTTP `429` with:

```
Quota exceeded for quota metric 'Queries' and limit 'Queries per day'
of service 'pagespeedonline.googleapis.com' for consumer 'project_number:583797351490'.
```

with `metadata.quota_limit_value: "0"` against `defaultPerDayPerProject`. A
limit value of zero on the shared keyless project means anonymous access is not
a usable fallback, only a documented one.

> **Design consequence, as shipped.** The anonymous tier is a diagnostic path
> that surfaces a clear "no API key configured" error, not a
> degraded-but-working mode. The API key is **required**; `MissingApiKeyException`
> says so, the client classifies a keyless 429 as a configuration error rather
> than as quota exhaustion, and `pagespeed:test` checks for a key before
> spending a minute on a request that could not succeed.

**No quota figure for keyed projects is documented by Google.** Numbers are
circulated online — 25,000 queries per day, 100 queries per 100 seconds are the
usual pair — but they appear in none of the sources above, so this package
treats them as folklore and does not repeat them as fact anywhere in its
documentation. Read the real limits off **APIs & Services → PageSpeed Insights
API → Quotas** in the Google Cloud Console for the project in use before tuning
`rate_limit.per_minute`.

The conservative default in config (`rate_limit.per_minute = 30`) exists
precisely because there is no published number to size against; it does not
depend on any unverified figure being right.

Error shape for quota exhaustion, useful for `QuotaExceededException` mapping:
`error.code = 429`, `error.status = "RESOURCE_EXHAUSTED"`,
`error.errors[0].reason = "rateLimitExceeded"`, and a
`type.googleapis.com/google.rpc.ErrorInfo` detail carrying
`reason = "RATE_LIMIT_EXCEEDED"` plus the `quota_limit` / `quota_metric` names.

## Response shape

### Top level (`PagespeedApiPagespeedResponseV5`)

`analysisUTCTimestamp`, `captchaResult`, `id`, `kind`, `lighthouseResult`,
`loadingExperience`, `originLoadingExperience`, `version`

`version` is an object with integer `major` / `minor`.

### `loadingExperience` / `originLoadingExperience` (`PagespeedApiLoadingExperienceV5`)

Fields: `id`, `initial_url`, `metrics`, `origin_fallback`, `overall_category`

- `metrics` is a **map** of metric name to `UserPageLoadMetricV5`, so the
  schema does not enumerate the keys. Parse defensively and key off whatever
  is present.
- `origin_fallback` signals that page-level CrUX data was unavailable and
  origin-level data was substituted — the UI's "not enough field data" state
  depends on this plus an absent `loadingExperience`.

`UserPageLoadMetricV5` fields: `category`, `distributions`, `formFactor`,
`median`, `metricId`, `percentile`.

> **Resolved 2026-08-02: it is p75.** The discovery document's description of
> `percentile` says *"For v4, this field contains pc50. For v5, this field
> contains pc90."* That conflicts with CrUX's own documentation, which states
> Core Web Vitals are reported at **p75**. Live keyed responses settle it in
> CrUX's favour — **the discovery description is stale.**
>
> Method: each metric's reported value can be located within its own
> `distributions` buckets, which bounds the percentile rank it must
> correspond to. Across 20 metrics from real page-level and origin-level
> datasets, every bound was consistent with p75 and 8 were incompatible with
> p90 — the value sat in a bucket whose cumulative proportion had not yet
> reached 0.90, so a 90th percentile would necessarily have been larger.
>
> Example (`www.php.net`, page-level `FIRST_CONTENTFUL_PAINT_MS`): reported
> percentile `1654` falls in the first bucket, whose cumulative proportion
> range is `[0, 0.789)`. p75 fits; p90 cannot.
>
> `Data\FieldData::PERCENTILE` carries the constant `75`.

`distributions` is an array of `Bucket` whose proportions sum to 1.

For reference, the standalone CrUX API uses lower-snake metric keys
(`largest_contentful_paint`, `interaction_to_next_paint`,
`cumulative_layout_shift`, `first_contentful_paint`,
`experimental_time_to_first_byte`, `round_trip_time`, …). PSI's embedded
`loadingExperience.metrics` has historically used upper-snake keys
(`LARGEST_CONTENTFUL_PAINT_MS`, `INTERACTION_TO_NEXT_PAINT`,
`CUMULATIVE_LAYOUT_SHIFT_SCORE`, `FIRST_CONTENTFUL_PAINT_MS`,
`EXPERIMENTAL_TIME_TO_FIRST_BYTE`). **Confirmed live on 2026-08-02** against
a keyed response — PSI returns exactly those five upper-snake keys:

```
CUMULATIVE_LAYOUT_SHIFT_SCORE
EXPERIMENTAL_TIME_TO_FIRST_BYTE
FIRST_CONTENTFUL_PAINT_MS
INTERACTION_TO_NEXT_PAINT
LARGEST_CONTENTFUL_PAINT_MS
```

`metrics` is still an open map in the schema, so `Data\FieldData` matches
**both** spellings for each Core Web Vital — the upper-snake PSI form and the
lower-snake standalone-CrUX form — and keeps every key it finds under its raw
name, so a spelling neither list anticipated stays readable through
`metric()`. The checked-in fixtures use the upper-snake form.

Observed `loadingExperience` top-level keys: `id`, `metrics`,
`overall_category`, `initial_url`. Note that `origin_fallback` is **absent
when false** rather than present-and-false.

### `lighthouseResult` (`LighthouseResultV5`)

Fields: `audits`, `categories`, `categoryGroups`, `configSettings`, `entities`,
`environment`, `fetchTime`, `finalDisplayedUrl`, `finalUrl`,
`fullPageScreenshot`, `i18n`, `lighthouseVersion`, `mainDocumentUrl`,
`requestedUrl`, `runWarnings`, `runtimeError`, `stackPacks`, `timing`,
`userAgent`

- `runtimeError` is `{ code, message }`. PSI returns **HTTP 200** with a
  populated `runtimeError` when Lighthouse itself failed, so a 2xx status is
  not sufficient to call a run successful.
- `fullPageScreenshot` is large. It is one of the reasons `store_raw_response`
  defaults to false.

`LighthouseCategoryV5` fields: `auditRefs`, `categoryScoreDisplayMode`,
`description`, `id`, `manualDescription`, `score`, `title`. `score` is a
**0–1 float or null**, so the 0–100 integer stored in the results table is
`round( score * 100 )` and null-safe.

`LighthouseAuditResultV5` fields: `description`, `details`, `displayValue`,
`errorMessage`, `explanation`, `id`, `metricSavings`, `numericUnit`,
`numericValue`, `score`, `scoreDisplayMode`, `title`, `warnings`.

### Identifying opportunities (verified against Lighthouse 13.4.1, 2026-08-02)

`metricSavings` alone is **not** enough to identify an opportunity. Three
things about the live data make a naive filter wrong:

1. **Lighthouse attaches `metricSavings` to almost everything**, including
   diagnostics and informational audits. On a page scoring 100, 22 audits
   carried `metricSavings` — every one of them `{"LCP":0,"FCP":0}` or
   similar. Filtering on "has metricSavings" reports a perfect page as having
   work to do.
2. **Diagnostics carry `score: null` with `scoreDisplayMode: "notApplicable"`**
   (`long-tasks`, `layout-shifts`, `bootup-time`,
   `non-composited-animations`). A null score is neither passing nor failing,
   so a "score < 1" test lets them through. `notApplicable`, `informative`,
   `manual`, and `error` are Lighthouse's markers for "not a scored,
   actionable finding".
3. **`numericValue` is a measurement, not a saving**, even when
   `numericUnit` is `millisecond`. `mainthread-work-breakdown` reports how
   long the main thread was busy. Only `details.overallSavingsMs` is a
   saving — and Lighthouse 13's newer `*-insight` audits
   (`render-blocking-insight`, `font-display-insight`) omit it entirely,
   carrying the estimate only in `metricSavings`.

`details.type === "opportunity"` still exists in Lighthouse 13 (5 audits in
the sample), but no longer covers the `*-insight` audits that hold the real
estimates, so it cannot be the sole test either.

The rule this package settled on: skip the non-actionable display modes, skip
passing audits (`score >= 1`), require the audit to be opportunity-shaped
(`details.type === "opportunity"` **or** non-empty `metricSavings`, which
keeps the five lab-metric audits out), and require a positive estimated
saving. Verified live: a page scoring 100 yields 0 opportunities, and one
scoring 95 yields 2 real ones.

## Lighthouse scoring

Performance score weights (unchanged since Lighthouse 10, still current):

| Metric | Weight |
|---|---|
| Total Blocking Time (TBT) | 30% |
| Largest Contentful Paint (LCP) | 25% |
| Cumulative Layout Shift (CLS) | 25% |
| First Contentful Paint (FCP) | 10% |
| Speed Index (SI) | 10% |

Time to Interactive was removed in Lighthouse 10 and must not be stored as a
lab metric column.

Score bands: **0–49 poor (red)**, **50–89 needs improvement (orange)**,
**90–100 good (green)**.
