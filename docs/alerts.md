---
title: Alerts
---

# Alerts

A trend chart nobody opens is a trend chart nobody reads. Two detectors turn a
silent problem into a notification:

- [**Regression detection**](#regressions) runs after each completed run and
  compares it with the previous completed run for the same URL and form factor.
- [**Staleness detection**](#pages-that-stop-reporting) runs on the schedule and
  reports the monitored URLs that have stopped producing results at all.

Both are governed by `alerts.enabled`. Off means nothing is compared, no hook
fires, and nothing is sent.

## Regressions

Three things count as a regression:

- **A drop.** The score fell by at least `alerts.drop_points` (10) against the
  previous run.
- **A floor breach.** The score is below `alerts.thresholds.<category>`,
  whatever the previous run said. Floors are absolute rather than comparative,
  so they apply to a URL's first run too — a page that has been bad since the
  day it was added is not less bad for having no history.
- **A score that stopped existing.** The category was scored last run and is
  null now. This one is worded apart from a drop on purpose: nothing got slower,
  the page simply stopped being measured for it, and the naive implementation —
  "no number to subtract, so nothing to say" — is exactly the silence this
  feature exists to break.

That third condition is why a category that quietly stops being measured still
notifies. It is also why the notification names *which* category went missing
rather than reporting a numeric drop it cannot compute.

### Which runs are used as a baseline

Failed runs are never used as a baseline, because they carry no scores and
comparing against one would read every recovery as a hundred-point gain.

Runs that completed while losing data — see
[Configuration → Degraded runs](configuration.md#degraded-runs-and-the-warnings-column) —
are skipped as a baseline too, up to ten runs back, so a clean run following a
thin one is not reported as a recovery it never made. Set
`alerts.skip_degraded` to `false` if you would rather compare against them.

A regression detected *on* a degraded run is still reported; the notification
says the run was degraded rather than staying quiet about it.

When there is nothing to compare against, that fact is logged at debug level
rather than passed over silently, so "why did I not get an alert" is a question
the log can answer.

### Digest

One monitoring cycle produces one result per URL per form factor, each inspected
in its own queued job. Alerting on each one directly turns a single bad deploy
into thirty near-identical emails, which is how an alert becomes something
people filter into a folder — so regressions are buffered for
`alerts.digest.wait` seconds (300) and sent as one notification. Exactly one
flush job is queued per window.

**A window whose delivery throws is put back rather than dropped**, and another
flush is scheduled for it, up to three attempts before the window is given up on
and logged. The buffer exists to stop one deploy arriving as thirty emails, not
to swallow the one email it was collapsed into.

A window nobody is configured to receive is *not* retried — that is a permanent
state and the documented way to run this package on hooks and logs alone.

> The digest is a **delayed** job, and the `sync` queue driver ignores delays. On
> a sync queue every regression sends immediately; set `alerts.digest.enabled`
> to `false` there and mean it, or run a real queue.

## Pages that stop reporting

Regression detection only ever runs when a new result exists, so it cannot see
the failure where results stop arriving at all: a revoked or exhausted API key,
a monitored page that started returning 404, a queue worker that died,
`scheduling.enabled` switched off in an environment nobody checks. Nothing new
is written, nothing is compared, nothing is sent — and the trend chart flatlines
at the last good score and looks perfectly healthy. That is performance
monitoring silently ceasing to monitor, which is the exact thing this package
exists to prevent, arriving through the back door.

So a second detector runs on the schedule rather than after a result is stored:

```bash
php artisan pagespeed:check-staleness             # check, and alert about what it finds
php artisan pagespeed:check-staleness --dry-run   # list what is stale without alerting
```

A URL is stale when it has no **completed** run inside its own cadence times
`alerts.staleness.missed_cycles` (2). Tolerance is counted in missed cycles
rather than hours so it scales with each URL: an hourly page is late after two
hours and a monthly one after two months, from the same setting, and a daily URL
is not reported for being an hour behind.

Paused URLs are ignored. A URL that has never completed a run is measured from
the day it was added, so a page registered this morning is new rather than
stale — while one added last month and never successfully tested, which is the
broken-key case, does report.

### The alert names the likely cause

"3 URLs stopped reporting" sends somebody hunting; "3 URLs stopped reporting;
last error: no PageSpeed Insights API key configured" is fixed in a minute. The
data to tell them apart is already on hand:

| Evidence | Diagnosis |
|---|---|
| No API key configured, or `scheduling.enabled` off | Explains the whole list at once, and is named first |
| Failed rows written since the last good run | Quoted with their `error_message` |
| No rows at all — not even a failure | The runs are not reaching a worker: the scheduler or the queue, rather than Google |

### Repetition

Everything found in one pass goes out as a single notification, and a URL that
stays broken alerts again only after `alerts.staleness.repeat_after` seconds (a
day), so a weekend of downtime is one email rather than 48.

`--dry-run` neither sends nor consumes that window, so asking the question by
hand does not silence the next real alert.

**A notification that could not be delivered does not consume the window
either.** The re-alert window is a limit on repetition, not a licence to drop the
first one — a URL that stopped reporting into a five-minute SMTP outage is
alerted about again on the next pass rather than a day later.

Delivery *failing* is not the same as nobody being configured to receive it:
with no recipients at all the window **is** consumed, since re-firing
`ap.pageSpeed.urlWentStale` on every pass for as long as a URL stays broken is
the spam this window exists to prevent, arriving from the other direction.

## Which conditions notify

Putting both detectors together, an alert is sent when:

| Condition | Detector |
|---|---|
| A category score dropped by `alerts.drop_points` or more | Regression |
| A category score fell below its configured floor | Regression |
| A category that was scored last run is **not measured at all** this run | Regression |
| A monitored URL has produced no completed run for `missed_cycles` of its own cadence | Staleness |
| A monitored URL has never completed a run since it was added, past that tolerance | Staleness |

Note the third row: a category that stopped being measured notifies, not only a
numeric drop. Note also what is *not* on the list — a single failed run does not
alert on its own. A failure writes a result row and the URL stays owed a run;
it only becomes an alert if it keeps happening long enough to make the URL
stale, which is what keeps one transient 5xx from paging anybody.

## Delivery

| Key | Default | Meaning |
|---|---|---|
| `alerts.channels` | `['mail']` | Laravel notification channels |
| `alerts.mail_to` | null | Who to email. An array, or a comma-separated string for the env var. |
| `alerts.notifiable` | null | A class the container can resolve to something notifiable — a team model, a Slack routing object — notified alongside `mail_to` |

Two notification classes are sent:
`Notifications\ScoreRegressionNotification` and
`Notifications\StaleUrlNotification`.

Thresholds ship unset because a floor is a per-site judgement: guessed too high
it alerts on everything, too low on nothing.

With nobody in `alerts.mail_to` and no `alerts.notifiable`, detection still
runs, still fires the hooks, and still logs — it just has nowhere to send an
email, which it says at debug level rather than silently. That is a supported
way to run the package: consume
[`ap.pageSpeed.scoreRegressed`](hooks.md#appagespeedscoreregressed) and
[`ap.pageSpeed.urlWentStale`](hooks.md#appagespeedurlwentstale) and route them
wherever you like.

## Full configuration

See [Configuration → Alerts](configuration.md#alerts) for the complete key,
env var, and default table.
