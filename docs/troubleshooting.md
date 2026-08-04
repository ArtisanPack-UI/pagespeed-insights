---
title: Troubleshooting
---

# Troubleshooting

This package follows a **no silent failures** policy: everything it tolerates,
it also reports. This page maps each signal back to a cause and a fix.

| Symptom | Jump to |
|---|---|
| HTTP 429, or "quota" errors on a brand-new install | [A 429 that actually means "no API key"](#a-429-that-actually-means-no-api-key) |
| Genuine quota exhaustion | [A 429 that really is quota](#a-429-that-really-is-quota) |
| Scores came back, but not all of them | [A run completed but the scores are partial](#a-run-completed-but-the-scores-are-partial) |
| A monitored URL's history just stops | [A monitored URL stopped producing history](#a-monitored-url-stopped-producing-history) |
| HTTP 200, but the run failed anyway | [HTTP 200 carrying a `runtimeError`](#http-200-carrying-a-runtimeerror) |
| The Core Web Vitals panel is empty or says "whole site" | [No CrUX field data, or origin-level data](#no-crux-field-data-or-origin-level-data) |
| Nothing is being tested at all | [Nothing is being queued](#nothing-is-being-queued) |
| An endpoint answers 403 | [403 from an endpoint](#403-from-an-endpoint) |
| A regression happened and nobody was told | [No alert arrived](#no-alert-arrived) |

## The three exception types

Keyless and quota-exhausted both surface as HTTP 429, so the client classifies
them before throwing. Conflating them is the single most expensive mistake
available here — one is a one-line config fix, the other is a wait.

| Exception | Cause | `isRetryable()` | What to do |
|---|---|---|---|
| `MissingApiKeyException` | No API key is configured | **false** | Configure one. Retrying never helps. |
| `QuotaExceededException` | A **keyed** project is out of quota | true | Back off and try later. Check the Cloud Console quota page. |
| `PageSpeedApiException` | Transport failure, API error, or Lighthouse's own `runtimeError` | usually true | Read the message; most are transient. |

`SitemapException` is a fourth, unrelated to a PageSpeed run — it is only thrown
by `pagespeed:discover-sitemap`.

---

## A 429 that actually means "no API key"

**Symptom.** Every run fails with HTTP 429. The message mentions quota. Nothing
has ever succeeded on this installation.

**Cause.** Google's shared anonymous project has a daily quota of **zero**. A
keyless request is rejected with 429 and `metadata.quota_limit_value: "0"` —
the same status a genuinely exhausted project returns. There is no usable
keyless mode.

**Fix.** Configure a key. See
[Installation → Getting a PageSpeed Insights API key](installation.md#getting-a-pagespeed-insights-api-key).

```bash
php artisan pagespeed:test https://example.com/    # exits 3 when no key is configured
```

**How the package tells you.** It checks whether a key was configured *before*
classifying a 429, so you get a `MissingApiKeyException` whose message names the
fix rather than a quota error:

- `pagespeed:test` exits **`3`** (`configuration`) and does so *before* spending
  20–60 seconds on a request that could not succeed.
- `pagespeed:monitor` refuses the whole cycle with one loud error, rather than
  queueing N URLs × 2 form factors of identically failing jobs.
- `RunPageSpeedTest` writes one `failed` row immediately with the fix in
  `error_message` and **never retries** — no amount of waiting produces a key.
- `POST /pagespeed/test` answers `409 no_api_key`.
- The Livewire and JS score cards render the `no-api-key` state.

**If a key *is* set and you still get this**, the key exists but the PageSpeed
Insights API is not enabled for its project — step 3 of the setup — or the key
carries an application restriction. Requests come from your server, so an
HTTP-referrer restriction rejects them; leave **Application restrictions** set
to **None** and restrict by API instead.

---

## A 429 that really is quota

**Symptom.** Runs used to succeed and now intermittently 429. A key is
configured and the API is enabled.

**Cause.** The project's quota is exhausted, or requests are arriving faster
than its per-minute limit allows.

**Fix.**

- Check **APIs & Services → PageSpeed Insights API → Quotas** in the Google
  Cloud Console for the limits that apply to your project. **Google does not
  publish these numbers anywhere in its documentation**, so the commonly cited
  figures are folklore — your own Console page is the only authority.
- Lower `rate_limit.per_minute` (default 30, deliberately conservative).
- Reduce the monitored set, lengthen `test_frequency`, or drop a form factor.
  Each run costs one request **per form factor**, so 200 URLs on both mobile and
  desktop is 400 requests per cycle.

**What the package does meanwhile.** A quota rejection on a configured key
**releases** the job with `job.quota_delay` (1800s) rather than consuming a
retry, so an exhausted quota postpones a run instead of failing it. No result
row is written and the URL stays owed a run.

---

## A run completed but the scores are partial

**Symptom.** A result row exists and is `completed`, but a category is blank, a
lab metric is missing, or the trend chart has a gap.

**Cause.** Lighthouse returned a payload that scored some things and not others.
The parser tolerates that rather than failing the run — but records everything
it tolerated.

**Where to look: the `warnings` column** on `pagespeed_results`.

```php
$result->wasDegraded();
$result->warningList();          // flat, human-readable
$result->warnings;               // the raw keys below
$result->scores()->unrecognized();
```

| `warnings` key | Means |
|---|---|
| `run_warnings` | Lighthouse's own `runWarnings`, verbatim — usually the best clue |
| `unrecognized_categories` | The response carried a category this package does not store |
| `missing_categories` | A requested category did not come back |
| `missing_metrics` | A lab metric audit id did not come back |
| `missing_field_data` | CrUX returned no field data |

**Fixes by key.**

- **`unrecognized_categories`** — a Lighthouse release added a category
  (`agentic-browsing`, for instance). Nothing is broken; the package stores four
  categories and does not guarantee a new one is surfaced until a release adds
  it. See [home → Compatibility policy](home.md#compatibility-policy).
- **`missing_categories`** — most often the category was never requested. Check
  `pagespeed-insights.categories`, and `--categories` on `pagespeed:test`.
- **`missing_metrics`** — lab metrics only come back with the `performance`
  category. If performance was requested and a metric is still absent, Lighthouse
  could not measure it on that page; `run_warnings` usually says why.
- **`run_warnings` on their own** — the page loaded but Lighthouse was unhappy
  with something about it. Read the strings; they are Google's own wording.

**Why it matters downstream.** A degraded run is skipped as an alert baseline by
default (`alerts.skip_degraded`), so a clean run after a thin one is not
reported as a recovery it never made. `pagespeed:test` prints every warning and
carries them in `--json`, so a degraded build never looks like a clean one.

Turn `store_raw_response` on temporarily to diagnose a parsing problem against a
real payload; `retention.keep_raw_days` expires them again.

---

## A monitored URL stopped producing history

**Symptom.** A trend chart flatlines at its last good score. No new rows are
appearing for that URL.

This is the failure regression detection *cannot* see, because it only runs when
a new result exists. Ask directly:

```bash
php artisan pagespeed:check-staleness --dry-run
```

The report names the likely cause per URL:

| `cause` | Means | Fix |
|---|---|---|
| `no_api_key` | No key configured — explains the whole list at once | [Configure one](#a-429-that-actually-means-no-api-key) |
| `scheduling_disabled` | `scheduling.enabled` is off in this environment | Turn it on, or call the commands from your own schedule |
| `failing` | Failed rows have been written; `cause_detail` quotes the `error_message` | Fix what it says — often a page now returning 404 |
| `not_running` | **No rows at all**, not even a failure | The runs are not reaching a worker: the scheduler or the queue, not Google |

`not_running` is the one people misread. It does not mean Google refused
anything; it means nothing was ever attempted. Check that a queue worker is
running and that Laravel's scheduler is wired up.

**Why the URL keeps being re-queued.** Only a run that produced a measurement
updates `last_tested_at`. A failed run does not, because that column drives
due-ness and letting a failure satisfy the cadence would make a URL that has
stopped being testable read as freshly tested. A permanently broken URL is
therefore re-queued every cycle, writing a failed row each time, until you fix it
or pause it:

```php
app( UrlRegistry::class )->deactivate( $url );
```

See [Alerts → Pages that stop reporting](alerts.md#pages-that-stop-reporting).

---

## HTTP 200 carrying a `runtimeError`

**Symptom.** The API answered 200, but the run is recorded as `failed`.

**Cause.** Lighthouse reports its own failures *inside* a successful HTTP
response, at `lighthouseResult.runtimeError`. A 200 is Google saying "I
delivered a report", not "the page was measured".

The package reads that field and throws `PageSpeedApiException` with
Lighthouse's error code and message rather than storing a report full of nulls
as a success.

**Common codes.**

| Code | Means |
|---|---|
| `ERRORED_DOCUMENT_REQUEST` | Lighthouse could not load the page — a 4xx/5xx, or DNS |
| `FAILED_DOCUMENT_REQUEST` | The request to the page failed outright |
| `NO_FCP` | The page never painted; usually a redirect loop or a blank render |
| `NOT_HTML` | The URL served something that is not a document |
| `DNS_FAILURE` | The hostname does not resolve from Google's infrastructure |

**Fix.** Load the URL yourself from outside your network. A page that works for
you and not for Lighthouse is usually behind auth, behind an IP allowlist, or
serving a different response to Google's user agent. Note that **embedded
credentials do not help**: `https://user:pass@example.com/` has its userinfo
stripped before storage, because PageSpeed fetches the page from Google's own
infrastructure where userinfo is not honoured — keeping it would write a
password in plaintext without making a protected site testable.

`pagespeed:test` surfaces the Lighthouse error code alongside exit code `4`.

---

## No CrUX field data, or origin-level data

**Symptom.** The Core Web Vitals panel is empty, or says the numbers describe
the whole site.

These are **two different states**, deliberately kept apart:

| State | Means | What to do |
|---|---|---|
| `no-field-data` | CrUX has no data for this page | Nothing is broken. The page needs enough real Chrome traffic to be reported on. Lab metrics still work. |
| `origin-level` | CrUX fell back to origin-level data | The numbers are real, but they describe the **whole origin**, not this page. Do not read them as a verdict on this URL. |

Field data is reported at the **75th percentile**, which is what CrUX
publishes — not a mean, and not a single measurement.

The origin-fallback case is the one that misleads. Without the distinction, a
brand-new page would appear to have excellent vitals because the site's home
page does. `FieldData::isOriginFallback()` and `isOriginLevel()` expose it in
PHP; the endpoints and all three component sets render it as its own state.

A run with no field data at all also records `missing_field_data` in
[`warnings`](#a-run-completed-but-the-scores-are-partial), which makes it a
degraded run.

---

## Nothing is being queued

**Symptom.** `pagespeed:monitor` reports nothing due, or reports jobs queued but
no results appear.

Work through these:

1. **Is a key configured?** `pagespeed:monitor` refuses the whole cycle without
   one. See [above](#a-429-that-actually-means-no-api-key).
2. **Are there any active monitored URLs?** Sitemap-discovered URLs are stored
   **inactive** unless `--activate` was passed.
3. **Is anything actually due?** A URL only comes due once its own
   `test_frequency` has elapsed. `--url=` tests one now regardless.
4. **Is a queue worker running?** Tests are queued jobs. Check the queue and
   connection under `pagespeed-insights.queue` — if you set a dedicated queue,
   the worker must be listening on it.
5. **Is Laravel's scheduler wired up?** `php artisan schedule:work`, or the cron
   entry. The package registers its tasks under `scheduling.enabled`.
6. **Is the rate limiter releasing everything?** A job over
   `rate_limit.per_minute` is released back onto the queue rather than run. A
   large cycle spreads out; it does not stall. `0` disables throttling.

A cycle whose jobs have not drained within the hour will queue the same URLs
again on the next tick, because a URL stays due until a run *completes*. If you
are near that, give PageSpeed its own queue and worker.

---

## 403 from an endpoint

| `error` | Means | Fix |
|---|---|---|
| `url_not_monitored` | A read endpoint was asked about a URL this installation does not monitor | Add it to the monitored set. The read endpoints never widen, whatever `allow_external_urls` says. |
| `url_not_allowed` | `POST /test` or `POST /urls` named a URL off this application's own origin | Set `routes.allow_external_urls` to `true` — appropriate for an agency dashboard, not for a normal site. |

`url_not_monitored` is returned whether the URL is somebody else's site or one
this installation has simply never added: a 404 there would let a caller map the
monitored set by probing it.

If `POST` or `DELETE` fails with a **419** instead, that is CSRF — the default
route stack includes `web`. Send `X-CSRF-TOKEN`, or move the endpoints onto a
stateless stack. See
[HTTP endpoints → Configuration](http-endpoints.md#configuration).

---

## No alert arrived

Work down the list; each step is logged, so the log can answer this too.

1. **`alerts.enabled`** — off means nothing is compared, no hook fires, nothing
   is sent.
2. **Is anybody configured to receive it?** With no `alerts.mail_to` and no
   `alerts.notifiable`, detection still runs, still fires
   [`ap.pageSpeed.scoreRegressed`](hooks.md#appagespeedscoreregressed), and still
   logs at debug level — it just has nowhere to send an email.
3. **Was the drop big enough?** `alerts.drop_points` defaults to 10 points. The
   thresholds ship **unset**, so a floor breach cannot fire until you set one.
4. **Was there a baseline?** A URL's first run has nothing to compare against
   (a floor still applies). Failed runs are never used as a baseline, and
   degraded ones are skipped by default under `alerts.skip_degraded` — set it to
   `false` if you would rather compare against them.
5. **Is the digest still buffering?** Regressions are held for
   `alerts.digest.wait` (300s) and sent as one notification. On a `sync` queue
   the delay is ignored entirely and everything sends immediately — set
   `alerts.digest.enabled` to `false` there, or run a real queue.
6. **Did a `pagespeed:test --store` run produce it?** That path deliberately
   fires no hooks and raises no alert: a CI run's audience is the pipeline that
   invoked it, and a branch build must not page the team.
7. **Was it a staleness alert you expected?** Those come from
   `pagespeed:check-staleness` on the schedule, not from a stored result, and a
   still-broken URL re-alerts only once per `alerts.staleness.repeat_after` (a
   day). A `--dry-run` neither sends nor consumes that window.

---

## Getting more detail

- Every tolerated skip, refused hook contribution, and undeliverable
  notification is **logged with the key it skipped**. Start with the log.
- `store_raw_response` keeps the full payload on new results, for diagnosing a
  parsing problem against a real response. Turn it off again afterwards — a
  payload is hundreds of kilobytes.
- `pagespeed:test --json` prints scores, metrics, warnings, and per-budget
  verdicts in one document, including on the failure paths.
- [PSI API reference](psi-api-reference.md) documents the verified API behaviour
  this package is built against, including the exact response fields named
  above.
