---
title: FAQ
---

# FAQ

## Do I really need an API key?

Yes. There is no usable keyless mode: Google's shared anonymous project has a
daily quota of **zero**, so a keyless request answers HTTP 429 every time. The
anonymous tier exists in this package only as the diagnostic path that produces
the "no API key configured" error.

See [Installation → Getting a PageSpeed Insights API key](installation.md#getting-a-pagespeed-insights-api-key).

## Does it cost anything?

Billing is not required to create a PageSpeed Insights API key or to use the
API.

## What are the quota limits?

**Google does not publish them.** Not in the Get Started guide, not in the API
reference, and not in the FAQ. The figures you will find quoted online are
folklore rather than documentation.

Check **APIs & Services → PageSpeed Insights API → Quotas** in the Google Cloud
Console for the limits that actually apply to your own project. That uncertainty
is exactly why `rate_limit.per_minute` defaults to a conservative **30** — raise
it only after you have looked at your own quota page.

## How much quota will my setup use?

Each run costs **one request per form factor**. So:

```
requests per cycle = active URLs × strategies per URL
```

200 URLs on both mobile and desktop is 400 requests per cycle. At a `daily`
cadence that is 400 a day; at `hourly`, 9,600. Lengthen `test_frequency`, drop a
form factor, or deactivate URLs to bring it down.

## How often should I test?

`weekly` is the default and is right for most sites. `daily` is reasonable for a
handful of important pages. `hourly` is supported but expensive — 24× the quota
and 24× the rows.

Per-URL overrides let you mix: hourly on the checkout page, weekly on everything
else.

## Why is the scheduled task hourly if my URLs are weekly?

Hourly is how often the package **looks**, not how often it tests. A URL only
comes due once its own `test_frequency` has elapsed. The tick has to be hourly
because `hourly` is itself a supported per-URL cadence — anything less frequent
would make it unreachable.

## Can I run a test from a web request?

No. A run takes 20–60 seconds and sometimes longer. Use the queued job
(`RunPageSpeedTest::dispatch()`), the `POST /pagespeed/test` endpoint (which
queues and returns a ticket to poll), or `pagespeed:test` from the console.

`PageSpeedInsights::test()` is synchronous and belongs in a job or a command.

## Why does my history table grow so fast?

An hourly cadence on 200 URLs across both form factors writes about **3.5
million rows a year**. That is why the package prunes itself: `retention.days`
(365) deletes old results and `retention.keep_raw_days` (30) discards raw
payloads earlier, since a payload is hundreds of kilobytes of data already
parsed into columns.

`store_raw_response` is off by default for the same reason. See
[Configuration → Retention](configuration.md#retention).

## Why does a category sometimes have no score?

Either it was not requested, or Lighthouse did not return it. Both are recorded
in the `warnings` column rather than passed over, and a run carrying either is
**degraded**. See
[Troubleshooting → A run completed but the scores are partial](troubleshooting.md#a-run-completed-but-the-scores-are-partial).

An unscored category renders as an em dash rather than a zero, everywhere. A
zero would be a measurement; the em dash is the absence of one.

## Lighthouse added a new category. Will I see it?

Not until a release of this package adds it. Lighthouse changes its category
lineup roughly every two releases — `PWA` is deprecated as of Lighthouse 12 and
`AGENTIC_BROWSING` has been added — so the package stores a fixed four,
tolerates the rest, and records what it did not store in
`warnings.unrecognized_categories`.

See [home → Compatibility policy](home.md#compatibility-policy).

## Why did Time to Interactive disappear?

Lighthouse removed it from the performance score in version 10. The package
stores FCP, LCP, TBT, CLS, and Speed Index.

## How is the performance score calculated?

By Lighthouse, from weighted lab metrics. As of the current release:

| Metric | Weight |
|---|---|
| Total Blocking Time | 30% |
| Largest Contentful Paint | 25% |
| Cumulative Layout Shift | 25% |
| First Contentful Paint | 10% |
| Speed Index | 10% |

Score bands are **0–49** poor, **50–89** needs improvement, **90–100** good.
Those weights are Google's and change between Lighthouse releases; see
[PSI API reference](psi-api-reference.md).

## What is the difference between lab metrics and field data?

**Lab metrics** come from Lighthouse's own simulated run — one measurement, one
device profile, reproducible. **Field data** comes from the Chrome UX Report
(CrUX): real Chrome users over the trailing period, reported at the **75th
percentile**.

Lab metrics always exist on a successful run. Field data only exists if the page
has enough real traffic. See
[Troubleshooting → No CrUX field data](troubleshooting.md#no-crux-field-data-or-origin-level-data).

## The vitals panel says the numbers describe the whole site. Why?

CrUX fell back to **origin-level** data, because it has none for that specific
page. The numbers are real, but they are about the origin rather than the URL.
The package renders that as its own state precisely so a new page does not
appear to have excellent vitals just because the home page does.

## Why do I need `www.example.com` and `example.com` as separate URLs?

Because either can serve a different document. URL canonicalization erases
scheme and host case, the default port, the fragment, and a trailing slash on a
non-root path — but **not** query strings and **not** `www.`, because both can
change what is served.

So `https://example.com/about`, `https://example.com/about/`, and
`HTTPS://Example.com/about#team` are one monitored page. `www.example.com` is
another.

## Can I monitor a site that is not mine?

By default, no. `POST /test` and `POST /urls` accept a monitored URL or one on
this application's own origin; anything else is `403 url_not_allowed`. Both
spend quota, and an authenticated user must not be able to point that at
arbitrary sites.

Set `routes.allow_external_urls` to `true` for an installation that legitimately
does — an agency dashboard. It widens the two write endpoints and never the read
ones.

## Can I monitor a staging site behind a password?

Not with HTTP auth. PageSpeed fetches the page from Google's own infrastructure,
which has no credentials — and embedded userinfo
(`https://user:pass@example.com/`) is stripped before storage rather than kept,
because Google does not honour it and storing it would write a password in
plaintext for nothing.

An IP allowlist that includes Google's fetchers, or a shared-secret query
parameter, are the usual routes.

## Who can add monitored URLs or spend quota?

Whoever can reach the surface. The endpoints, the Livewire components, and the
CMS widgets all carry **no authorization of their own** beyond the middleware
you configure — mounting or exposing one is the authorization decision, because
a package cannot know what your admin role is called.

Add your own policy to `routes.middleware`, and place the URL manager behind
whatever gate your application already uses.

## Do I have to use the components?

No. The console commands, the queued job, and the alerting are all useful with
no front end at all — set `routes.enabled` to `false` and skip Livewire
entirely. An endpoint nobody calls is still an endpoint somebody can call.

## Can I use my own front end?

Yes. The [HTTP endpoints](http-endpoints.md) are the supported way, and the
[shared TypeScript fetch layer](react-components.md#shared-fetch-layer) ships
typed clients for every one of them, usable without either component set.

## Can I send alerts somewhere other than email?

Yes, three ways: add channels to `alerts.channels`, point `alerts.notifiable` at
a class the container can resolve (a team model, a Slack routing object), or
listen on [`ap.pageSpeed.scoreRegressed`](hooks.md#appagespeedscoreregressed) and
[`ap.pageSpeed.urlWentStale`](hooks.md#appagespeedurlwentstale) and route them
yourself. The hooks fire whether or not anybody is configured to receive an
email.

## Why didn't a failed run alert me?

A single failure is not an alert. It writes a `failed` result row and the URL
stays owed a run; it only becomes an alert if it keeps happening long enough to
make the URL **stale** — which is what keeps one transient 5xx from paging
anybody. See [Alerts → Which conditions notify](alerts.md#which-conditions-notify).

## Does `pagespeed:test --store` trigger alerts?

No, and it fires no hooks either. A CI run's audience is the pipeline that
invoked it, and a branch build must not page the team.

## Does the package call Google in its own test suite?

Never. Runs are served from checked-in response fixtures. Your application's
tests should fake the HTTP client or seed result rows with the factories — see
[Testing](testing.md).
