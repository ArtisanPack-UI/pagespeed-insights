<?php

/**
 * PageSpeedInsights package configuration.
 *
 * @package    ArtisanPack_UI
 * @subpackage PageSpeedInsights
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | PageSpeed Insights API Key
    |--------------------------------------------------------------------------
    |
    | REQUIRED. The PageSpeed Insights API will not run without a key: Google's
    | shared anonymous project has a daily quota of zero, so keyless requests
    | fail with HTTP 429 every time. There is no supported keyless or anonymous
    | mode.
    |
    | Create a key in the Google Cloud Console with the PageSpeed Insights API
    | enabled, then set PAGESPEED_API_KEY in your environment.
    |
    | This value is used by the default "config" driver. The "database" and
    | "cms" drivers ignore it and read the key from their own storage instead.
    |
    */
    'api_key' => env( 'PAGESPEED_API_KEY' ),

    /*
    |--------------------------------------------------------------------------
    | Configuration Driver
    |--------------------------------------------------------------------------
    |
    | Which driver backs the ApiKeyRepository. Supported: "config", "database",
    | "cms".
    |
    | - "config"   reads the api_key value above; read-only.
    | - "database" stores the key encrypted in the pagespeed_configurations
    |              table.
    | - "cms"      stores the key encrypted via the CMS framework's Settings
    |              module, and is only available when
    |              `artisanpack-ui/cms-framework` is installed.
    |
    */
    'driver' => env( 'PAGESPEED_CONFIG_DRIVER', 'config' ),

    /*
    |--------------------------------------------------------------------------
    | API Endpoint
    |--------------------------------------------------------------------------
    |
    | The runPagespeed endpoint. Google publishes two hosts for the same API:
    | the www.googleapis.com form used here (from Google's own Get Started
    | guide) and the canonical discovery form,
    | https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed.
    | Either works.
    |
    */
    'endpoint' => env( 'PAGESPEED_ENDPOINT', 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ),

    /*
    |--------------------------------------------------------------------------
    | Requested Categories
    |--------------------------------------------------------------------------
    |
    | Written in the response-key spelling (lower-kebab). The client translates
    | each entry to the API's upper-snake request enum (best-practices becomes
    | BEST_PRACTICES) and repeats the `category` query parameter once per
    | entry. Omitting the parameter entirely would return performance only,
    | which is a silent way to lose three scores.
    |
    | Lighthouse changes its category lineup roughly every two releases — PWA
    | is deprecated as of Lighthouse 12 and AGENTIC_BROWSING is new — so the
    | parser tolerates entries here that the response does not carry, and
    | response categories that are not listed here.
    |
    */
    'categories' => [ 'performance', 'accessibility', 'best-practices', 'seo' ],

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a single PageSpeed run. Google's own runs take 20-60
    | seconds and occasionally longer, so this sits well above a typical HTTP
    | timeout. Values that are not positive numbers fall back to 90.
    |
    */
    'timeout' => env( 'PAGESPEED_TIMEOUT', 90 ),

    /*
    |--------------------------------------------------------------------------
    | Opportunities Limit
    |--------------------------------------------------------------------------
    |
    | How many Lighthouse opportunities to keep from each run, ordered by
    | estimated savings. The full audit tree is far too large to store, and
    | the tail of it is not actionable. Consumers can reshape the pruned list
    | through the `ap.pageSpeed.opportunities` filter.
    |
    */
    'opportunities_limit' => 10,

    /*
    |--------------------------------------------------------------------------
    | Default Test Frequency
    |--------------------------------------------------------------------------
    |
    | How often a monitored URL is retested when its row carries no per-URL
    | override. Supported: "hourly", "daily", "weekly", "monthly". Anything
    | else falls back to "weekly".
    |
    | Bear the API quota in mind: each run costs one request per form factor,
    | so 200 URLs on both mobile and desktop is 400 requests per cycle.
    |
    */
    'test_frequency' => env( 'PAGESPEED_TEST_FREQUENCY', 'weekly' ),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where RunPageSpeedTest jobs are dispatched. Both values are null by
    | default, meaning the application's default connection and queue.
    |
    | A dedicated queue is worth considering on a busy application: a single
    | PageSpeed run blocks its worker for 20-60 seconds, so a scheduled cycle
    | over a few dozen URLs will starve anything sharing the queue with it.
    |
    */
    'queue' => [
        'connection' => env( 'PAGESPEED_QUEUE_CONNECTION' ),
        'queue'      => env( 'PAGESPEED_QUEUE' ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | The most PageSpeed requests this application will start in one minute,
    | across every worker. Jobs over the limit are released back onto the
    | queue rather than dropped, so a large cycle spreads itself out instead
    | of failing.
    |
    | The default of 30 is deliberately conservative. Google does not publish
    | a PageSpeed Insights rate limit — not in the Get Started guide, not in
    | the API reference, and not in the FAQ — so the commonly cited figures
    | are folklore rather than documentation. Check the quota page for your
    | own project in the Google Cloud Console before raising this.
    |
    | Set to 0 to disable throttling entirely.
    |
    */
    'rate_limit' => [
        'per_minute' => env( 'PAGESPEED_RATE_LIMIT_PER_MINUTE', 30 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Behaviour
    |--------------------------------------------------------------------------
    |
    | - "timeout"     seconds one job may run for. Sits above the request
    |                 timeout above so the HTTP client, which knows which URL
    |                 it was testing, is the thing that gives up first.
    | - "tries"       attempts before the run is recorded as failed. Applies
    |                 to transient failures only; a missing API key is never
    |                 retried, because no amount of waiting produces one.
    | - "backoff"     seconds to wait before each retry.
    | - "quota_delay" seconds to wait after a genuine quota rejection. This
    |                 releases the job rather than consuming a retry, so an
    |                 exhausted quota postpones a run instead of failing it.
    |
    */
    'job' => [
        'timeout'     => env( 'PAGESPEED_JOB_TIMEOUT', 120 ),
        'tries'       => env( 'PAGESPEED_JOB_TRIES', 3 ),
        'backoff'     => [ 60, 300 ],
        'quota_delay' => env( 'PAGESPEED_QUOTA_DELAY', 1800 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | - "enabled"           whether the package registers its own hourly
    |                       `pagespeed:monitor` task. Turn this off to call
    |                       the command from your own schedule instead.
    | - "persist_hook_urls" whether URLs contributed through the
    |                       `ap.pageSpeed.registerUrls` filter are saved
    |                       before a cycle. They have to be: an unsaved URL
    |                       has nowhere to record `last_tested_at`, so it
    |                       would come due on every single cycle regardless
    |                       of its cadence, and its results would have no row
    |                       to hang history from.
    |
    | The task runs hourly rather than on the test frequency because "hourly"
    | is itself a supported cadence, and because a URL only comes due once its
    | own interval has elapsed — an hourly tick is how often the package
    | *looks*, not how often it tests.
    |
    */
    'scheduling' => [
        'enabled'           => env( 'PAGESPEED_SCHEDULING_ENABLED', true ),
        'persist_hook_urls' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long `pagespeed:prune` keeps history for. Two windows rather than
    | one, because the two things a result row holds cost wildly different
    | amounts to keep: the scores are a few dozen bytes and are the entire
    | point of recording history, while a retained raw payload is hundreds of
    | kilobytes of data the package has already parsed into its own columns.
    |
    | - "days"          delete results older than this. A year keeps a
    |                   full seasonal cycle, so this year's Black Friday can
    |                   be compared with last year's.
    | - "keep_raw_days" null `raw_response` on results older than this,
    |                   leaving the scores in place. Raw payloads are kept to
    |                   diagnose a parsing problem against a real response,
    |                   and a month-old payload has already answered that
    |                   question or never will.
    |
    | Either window can be set to 0 (or null) to turn that half off. Setting
    | `days` to 0 means history is never deleted, which is a real choice on a
    | small monitored set — but it is a choice, not the default, because an
    | hourly cadence on 200 URLs writes 3.5 million rows a year.
    |
    */
    'retention' => [
        'days'          => env( 'PAGESPEED_RETENTION_DAYS', 365 ),
        'keep_raw_days' => env( 'PAGESPEED_RETENTION_KEEP_RAW_DAYS', 30 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sitemap Discovery
    |--------------------------------------------------------------------------
    |
    | Settings for `php artisan pagespeed:discover-sitemap`, which populates
    | the monitored set from the site's own sitemap.
    |
    | - "url"     the sitemap to read. Null means sitemap.xml at app.url.
    | - "limit"   the most URLs one run will discover. The cap exists because
    |             a large site lists thousands of pages and every activated
    |             one costs quota on every cycle. Discovered URLs are stored
    |             inactive unless the command is run with --activate.
    | - "timeout" seconds to wait for a single sitemap document. Sitemap index
    |             files are followed, so one run may fetch several.
    |
    */
    'sitemap' => [
        'url'     => env( 'PAGESPEED_SITEMAP_URL' ),
        'limit'   => env( 'PAGESPEED_SITEMAP_LIMIT', 50 ),
        'timeout' => env( 'PAGESPEED_SITEMAP_TIMEOUT', 15 ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Turning a silent score regression into a notification. Detection runs
    | after each completed run, comparing it with the previous completed run
    | for the same URL and form factor.
    |
    | - "enabled"        the master switch. Off means nothing is compared, no
    |                    hook fires, and nothing is sent.
    | - "drop_points"    how many points a category may lose against the
    |                    previous run before it counts as a regression. Set to
    |                    0 (or null) to turn drop detection off and alert on
    |                    the floors alone.
    | - "thresholds"     an absolute floor per category. A score below its
    |                    floor alerts whatever the previous run said —
    |                    including on a URL's very first run, which has
    |                    nothing to be compared with. Null means no floor.
    | - "skip_degraded"  whether a run that completed while losing a category
    |                    or a lab metric is passed over when looking for
    |                    something to compare against. It should be: a thin
    |                    run is a weak baseline, and comparing against one
    |                    manufactures a "recovery" on the next clean run. The
    |                    current run is never skipped — a category that was
    |                    scored last time and is null now is exactly the
    |                    signal this feature exists for — but the notification
    |                    says the run was degraded.
    | - "channels"       the notification channels to deliver on.
    | - "mail_to"        who to email. An array, or a comma-separated string
    |                    for the env var. Empty means nobody, in which case
    |                    detection still fires the hook and logs.
    | - "notifiable"     an optional class the container can resolve to
    |                    something notifiable — a team model, a Slack routing
    |                    object — notified alongside `mail_to`.
    | - "digest"         one monitoring cycle produces N URLs x 2 form factors
    |                    of results, and alerting on each one separately is
    |                    how an alert becomes something people filter into a
    |                    folder. Regressions are buffered for `wait` seconds
    |                    and sent as a single notification. `store` names a
    |                    cache store; null uses the default one.
    |
    */
    'alerts' => [
        'enabled'     => env( 'PAGESPEED_ALERTS_ENABLED', true ),
        'drop_points' => env( 'PAGESPEED_ALERT_DROP_POINTS', 10 ),

        'thresholds' => [
            'performance'    => env( 'PAGESPEED_ALERT_THRESHOLD_PERFORMANCE' ),
            'accessibility'  => env( 'PAGESPEED_ALERT_THRESHOLD_ACCESSIBILITY' ),
            'best-practices' => env( 'PAGESPEED_ALERT_THRESHOLD_BEST_PRACTICES' ),
            'seo'            => env( 'PAGESPEED_ALERT_THRESHOLD_SEO' ),
        ],

        'skip_degraded' => true,

        'channels'   => [ 'mail' ],
        'mail_to'    => env( 'PAGESPEED_ALERT_MAIL_TO' ),
        'notifiable' => null,

        'digest' => [
            'enabled' => true,
            'wait'    => env( 'PAGESPEED_ALERT_DIGEST_WAIT', 300 ),
            'store'   => env( 'PAGESPEED_ALERT_DIGEST_STORE' ),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Routes
    |--------------------------------------------------------------------------
    |
    | The authenticated JSON endpoints that back the React and Vue components,
    | and any front end an application writes for itself.
    |
    | - "enabled"             whether the routes are registered at all. Off is
    |                         the right choice for an install that only uses
    |                         the console commands, the queue, and the Livewire
    |                         components: an endpoint nobody calls is still an
    |                         endpoint somebody can call.
    | - "prefix"              the path every endpoint sits under.
    | - "middleware"          the stack they run through. `auth` is in the
    |                         default for a reason — the endpoints read a
    |                         site's performance history and one of them spends
    |                         API quota — and removing it publishes both.
    | - "allow_external_urls" whether `POST /test` and `POST /urls` accept a URL
    |                         that is neither monitored nor on this
    |                         application's own origin. Off by default: an ad
    |                         hoc run spends a slice of the API quota, and
    |                         monitoring a URL spends one on every cycle for as
    |                         long as the row lives, so an authenticated user
    |                         must not be able to point either at arbitrary
    |                         third-party sites. Turn it on for an installation
    |                         that legitimately monitors other people's sites —
    |                         an agency dashboard, most obviously — and whose
    |                         authenticated users are trusted to choose what
    |                         gets tested.
    |
    | The read endpoints are never widened by this flag. They only ever answer
    | for URLs in this installation's own monitored set, whatever it says.
    |
    */
    'routes' => [
        'enabled'             => true,
        'prefix'              => 'pagespeed',
        'middleware'          => [ 'web', 'auth' ],
        'allow_external_urls' => env( 'PAGESPEED_ALLOW_EXTERNAL_URLS', false ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Raw Responses
    |--------------------------------------------------------------------------
    |
    | Whether to keep the full runPagespeed payload on each stored result.
    | Off by default: a single response runs to hundreds of kilobytes, so
    | retaining every one grows the history table by two orders of magnitude
    | for data the package already parses into its own columns.
    |
    | Worth turning on temporarily when diagnosing a parsing problem against
    | real responses.
    |
    */
    'store_raw_response' => env( 'PAGESPEED_STORE_RAW_RESPONSE', false ),

];
