---
title: HTTP Endpoints
---

# HTTP endpoints

Authenticated JSON endpoints, behind the `pagespeed` prefix and the `web` and
`auth` middleware by default. They back the [React](react-components.md) and
[Vue](vue-components.md) components and are the supported way to build a front
end of your own.

| Method | Path | Answers |
|---|---|---|
| `GET` | `/pagespeed/scores?url=&strategy=` | The newest run's four category scores and lab metrics |
| `GET` | `/pagespeed/core-web-vitals?url=&strategy=` | The newest run's CrUX field data, page-level and origin-level |
| `GET` | `/pagespeed/opportunities?url=&strategy=` | What Lighthouse says is worth fixing, heaviest first |
| `GET` | `/pagespeed/trends?url=&metric=&range=&strategy=` | One measurement over time, mobile against desktop |
| `GET` | `/pagespeed/urls` | The monitored set |
| `POST` | `/pagespeed/urls` | Start monitoring a URL |
| `DELETE` | `/pagespeed/urls/{id}` | Stop monitoring one. Its result history is kept. |
| `POST` | `/pagespeed/test` | Queue an ad hoc run. Returns an id to poll with. |
| `GET` | `/pagespeed/results/{id}` | Poll that id, or fetch any stored run by its own id |

Every route is named — `pagespeed-insights.scores`,
`pagespeed-insights.urls.store`, and so on — so `route()` works whatever
`routes.prefix` is set to.

## States, not empty lists

Every response carries a `state` naming which of several honest answers it is,
rather than leaving a caller to infer one from an empty list.

| Endpoint | States |
|---|---|
| `scores` | `empty`, `failed`, `degraded`, `loaded` |
| `core-web-vitals` | `empty`, `failed`, `no-field-data`, `origin-level`, `loaded` |
| `opportunities` | `empty`, `failed`, `not-measured`, `none`, `loaded` |
| `trends` | `empty`, `out-of-range`, `insufficient`, `loaded` |

An empty opportunities list means "nothing found" or "nothing looked for"; an
empty vitals panel means "CrUX has no data" or "the run failed"; a blank trend
means "no history" or "no history *in this range*". Those pairs have different
remedies, so they are different states. The meanings are spelled out under
[Livewire components → States](livewire-components.md#states); all three
component sets render the same ones.

Bands (`good`, `needs-improvement`, `poor`) are computed server-side against
Google's published thresholds — 90 and 50 for a Lighthouse score. Labels and
colours are **not** sent: those are the client's to choose, and a JSON endpoint
that baked them in would hand every caller a string in the server's locale.

## Query parameters

| Parameter | Endpoints | Values | Default |
|---|---|---|---|
| `url` | all reads | An absolute http(s) URL | required |
| `strategy` | scores, vitals, opportunities, trends | `mobile`, `desktop` | `mobile` |
| `metric` | trends | `performance`, `accessibility`, `best-practices`, `seo`, or a lab metric audit id | `performance` |
| `range` | trends | `7`, `30`, `90`, `365` | `90` |

An unrecognized `strategy`, `metric`, or `range` falls back to its default
rather than erroring, because a chart control that sends a stale value should
draw something rather than break. On `trends`, omitting `strategy` plots both
form factors.

## Authorization

**These endpoints carry no authorization of their own beyond the configured
middleware.** An authenticated user is an authorized one: whoever can reach
`/pagespeed/urls` can add and remove monitored URLs, and whoever can reach
`/pagespeed/test` can spend a slice of the API quota. That is the same stance
the Livewire components take — mounting one is the authorization decision — and
it is deliberate, because a package cannot know what an application's admin role
is called.

Put your own policy in `routes.middleware`
(`['web', 'auth', 'can:manage-pagespeed']`, say) if "logged in" is broader than
"allowed to manage performance monitoring" on your installation.

### Which URLs an endpoint will answer for

Within that grant, authentication is still not the whole of the question,
because every endpoint takes a URL from the caller.

- **The read endpoints only ever serve URLs in this installation's own monitored
  set** — stored rows and hook contributions both. Anything else is
  `403 url_not_monitored`, whatever the flag below says. The results table is
  keyed on a plain URL column, so without this an authenticated user could read
  whatever this installation happens to have stored about a third party's site.
- **`POST /test` and `POST /urls` accept a monitored URL, or one on this
  application's own origin.** Both spend API quota — the first once, the second
  on every cycle for as long as the row lives — and an authenticated user must
  not be able to point that at arbitrary sites. Anything else is
  `403 url_not_allowed`.

Set `routes.allow_external_urls` (or `PAGESPEED_ALLOW_EXTERNAL_URLS`) to `true`
on an installation that legitimately monitors other people's sites — an agency
dashboard, most obviously. It widens the two write endpoints and never the read
ones.

## Errors

Every refusal is a JSON body carrying a stable machine-readable code in `error`
alongside a prose `message`, so a client branches on the code rather than on the
wording:

```json
{ "error": "url_not_monitored", "message": "This installation does not monitor that URL." }
```

| `error` | Status | Meaning |
|---|---|---|
| `invalid_url` | 422 | The `url` parameter is missing, blank, not an http(s) URL, or too long |
| `invalid_attribute` | 422 | `POST /urls` or a URL update carried an unusable `label`, `strategies`, or `testFrequency` |
| `url_not_monitored` | 403 | A read endpoint was asked about a URL this installation does not monitor |
| `url_not_allowed` | 403 | A write endpoint was asked to spend quota on a URL off this site, with `allow_external_urls` off |
| `already_monitored` | 409 | `POST /urls` named a URL that is already stored |
| `no_api_key` | 409 | `POST /test` was called with no API key configured, so no run could succeed |
| `queue_unavailable` | 500 | `POST /test` could not dispatch the job |
| `not_found` | 404 | No monitored URL or run has that id |

`url_not_monitored` is deliberately the same answer whether the URL is somebody
else's site or simply one this installation has never added: a 404 there would
let a caller map the monitored set by probing it.

The shared TypeScript client surfaces these on `PageSpeedInsightsError.code`,
falling back to `http_error` when a response carries no `error` key at all — a
proxy's own 502 page, most often.

## Monitored URLs

```http
GET /pagespeed/urls

{ "urls": [
    { "id": 4, "url": "https://example.com/pricing", "label": "Pricing",
      "source": "manual", "isActive": true, "testFrequency": "daily",
      "strategies": ["mobile", "desktop"], "lastTestedAt": "…", "editable": true },
    { "id": null, "url": "https://example.com/checkout", "label": null,
      "source": "hook", "isActive": true, "editable": false }
] }
```

A hook contribution that has never been saved carries a null `id` and
`editable: false`, because it is an unsaved model owned by whichever package
registered it and the next request would rebuild it from the filter whatever
this endpoint did to it. **Posting such a URL is allowed, and is the documented
way to take one over**: it creates a stored row, which then wins over the hook.

```http
POST /pagespeed/urls
{ "url": "https://example.com/pricing", "label": "Pricing",
  "strategies": ["mobile"], "testFrequency": "daily" }

201 Created
```

`DELETE /pagespeed/urls/{id}` stops monitoring one and keeps its result history.

## Queueing a run

```http
POST /pagespeed/test
{ "url": "https://example.com/pricing", "strategy": "mobile" }

202 Accepted
{ "id": "9f1c…", "status": "queued", "url": "https://example.com/pricing", "strategy": "mobile" }
```

Then poll:

```http
GET /pagespeed/results/9f1c…

{ "id": 412, "status": "completed", "url": "…", "strategy": "mobile", "result": { … } }
```

The id is a **ticket** rather than a row id, because a queued run has no row
until it finishes. Writing a pending row would have given you a real id
immediately and put a third status into a table every other reader of which
assumes two — the score card, the vitals card, and the opportunities table would
all have started rendering an in-flight run as a completed one that measured
nothing. So the ticket records what was queued and which result id was newest at
the time, and resolves to the row the run produced.

`status` is `queued`, `completed`, `failed`, or `timed-out`. The last is a real
answer rather than an endless spinner: past ten minutes the ticket stops
implying that waiting will help. It does not claim the run failed — it may still
be queued — but a stalled queue worker must not present as a slow one.

A numeric `{id}` is read as a stored result id instead, so any run you have the
id of can be fetched in full later.

## Configuration

| Key | Env | Default | Meaning |
|---|---|---|---|
| `routes.enabled` | — | true | Whether the endpoints are registered at all |
| `routes.prefix` | — | `pagespeed` | The path they sit under |
| `routes.middleware` | — | `['web', 'auth']` | The stack they run through |
| `routes.allow_external_urls` | `PAGESPEED_ALLOW_EXTERNAL_URLS` | false | Whether `POST /test` and `POST /urls` accept URLs off this site |

All four are read when the service provider boots. Turning `routes.enabled` off
registers nothing, which is the right choice for an installation that only uses
the console commands, the queue, and the Livewire components — an endpoint
nobody calls is still an endpoint somebody can call.

Replacing `routes.middleware` replaces it **wholesale**, including the `auth`
entry: the package applies the stack you configure rather than adding a guard of
its own on top of it. Two things worth knowing about that stack:

- The default includes `web`, so **`POST` and `DELETE` requests need a CSRF
  token** like any other session-authenticated form post. Send `X-CSRF-TOKEN`,
  or move the endpoints onto a stateless stack (`['api', 'auth:sanctum']`, say)
  if you are calling them from something that has no session.
- There is no `throttle` in the default stack. `POST /test` queues a job per
  call, and while the job middleware holds the whole application to
  `rate_limit.per_minute` API requests — so the quota itself is safe — nothing
  stops an authenticated user filling the queue. Add `throttle:30,1` to
  `routes.middleware` on an installation where "authenticated" is a low bar.
