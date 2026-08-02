# PageSpeed Insights API — verified reference

Implementation notes for the PSI API as it actually behaves, captured while
scaffolding this package. Everything below was checked against live sources on
the date shown, not copied from the planning document.

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

**Keyless requests are effectively dead.** A live keyless request on
2026-08-01 returned HTTP `429` with:

```
Quota exceeded for quota metric 'Queries' and limit 'Queries per day'
of service 'pagespeedonline.googleapis.com' for consumer 'project_number:583797351490'.
```

with `metadata.quota_limit_value: "0"` against `defaultPerDayPerProject`. A
limit value of zero on the shared keyless project means anonymous access is not
a usable fallback, only a documented one.

> **Design consequence.** The plan's auth resolution order (§4) lists
> "anonymous, allowed but logged with a warning" as the third tier. Treat that
> tier as a diagnostic path that surfaces a clear "no API key configured"
> error, not as a degraded-but-working mode. The API key is effectively
> **required**, and the README and `MissingApiKeyException` messaging should
> say so.

Commonly cited figures for keyed projects — **not** confirmed by Google's
docs, so verify against Google Cloud Console for the project in use before
tuning `rate_limit.per_minute`:

- 25,000 queries per day
- 100 queries per 100 seconds

The conservative default in config (`rate_limit.per_minute = 30`) sits well
under both and does not depend on either number being right.

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

> **Open item for the API-client issue.** The discovery document's description
> of `percentile` says *"For v4, this field contains pc50. For v5, this field
> contains pc90."* That conflicts with CrUX's own documentation, which states
> Core Web Vitals are reported at **p75**. The description reads as stale.
> Confirm against a real keyed response before writing the field-data parser,
> and label the number correctly in the UI — showing a p75 as a p90 (or vice
> versa) is a silent correctness bug.

`distributions` is an array of `Bucket` whose proportions sum to 1.

For reference, the standalone CrUX API uses lower-snake metric keys
(`largest_contentful_paint`, `interaction_to_next_paint`,
`cumulative_layout_shift`, `first_contentful_paint`,
`experimental_time_to_first_byte`, `round_trip_time`, …). PSI's embedded
`loadingExperience.metrics` has historically used upper-snake keys
(`LARGEST_CONTENTFUL_PAINT_MS`, `INTERACTION_TO_NEXT_PAINT`,
`CUMULATIVE_LAYOUT_SHIFT_SCORE`, `FIRST_CONTENTFUL_PAINT_MS`,
`EXPERIMENTAL_TIME_TO_FIRST_BYTE`). **The exact PSI-side keys could not be
confirmed live** because the keyless probe was quota-blocked; capture them
from a real keyed response when building the fixtures.

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
`metricSavings` is the field to pull for the pruned opportunities extract.

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
