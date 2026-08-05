<script lang="ts">
/**
 * Vue PageSpeed category score card.
 *
 * The Vue half of `\ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard`, and
 * the twin of the React one. Five states, deliberately worded apart from one
 * another: an empty card means "nothing has run yet", and anything else that
 * renders as an empty card is a problem nobody notices.
 */

import type { PsiStrategy as PsiStrategyType } from '../shared/client'

/**
 * How often a running test is asked whether it has finished, in milliseconds.
 *
 * Mirrors `ScoreCard::POLL_SECONDS`.
 */
export const SCORE_CARD_POLL_MS = 5000

/**
 * Props for `ScoreCard`.
 */
export interface ScoreCardProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. @defaultValue 'mobile' */
    strategy?: PsiStrategyType
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** CSRF token for the run-test endpoint. Read from the document when omitted. */
    csrfToken?: string | null
    /** Whether to offer the "Run test" button at all. @defaultValue true */
    allowRunningTests?: boolean
}
</script>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Badge, Button, Card, Loading, Progress, Stat } from '@artisanpack-ui/vue'
import { PageSpeedInsightsError, type PsiStrategy } from '../shared/client'
import { emitPsiResultStored } from '../shared/events'
import { bandColor, bandLabel, formatTimestamp, metricLabel, strategyLabel } from '../shared/labels'
import {
    SCORES_STATE_DEGRADED,
    SCORES_STATE_EMPTY,
    SCORES_STATE_FAILED,
    fetchPsiScores,
    type PsiScoresData,
} from '../shared/scores'
import { TREND_CATEGORIES } from '../shared/trends'
import {
    RUN_STATUS_QUEUED,
    RUN_STATUS_TIMED_OUT,
    fetchPsiRun,
    queuePsiTest,
    type PsiRunStatus,
} from '../shared/runs'
import { usePsiResource } from './use-psi-resource'
import TitledAlert from './TitledAlert.vue'

/**
 * The progress-bar classes, written out in full.
 *
 * A class name assembled as `progress-${ color }` never appears in the source
 * Tailwind scans, so the utility is never generated and every bar renders grey.
 */
const PROGRESS_CLASSES: Record<string, string> = {
    success: 'progress-success',
    warning: 'progress-warning',
    error: 'progress-error',
}

/**
 * The band colour for a gauge's number.
 *
 * The library's Vue `Stat` colours nothing on its own — unlike its React
 * counterpart it takes no colour prop — so the class is aimed at the value
 * through the root, and written out in full for the same reason as above.
 */
const STAT_VALUE_CLASSES: Record<string, string> = {
    success: '[&_.stat-value]:text-success',
    warning: '[&_.stat-value]:text-warning',
    error: '[&_.stat-value]:text-error',
}

/**
 * A run this card is waiting on, and the subject it was queued for.
 */
interface QueuedRun {
    id: number | string
    url: string
    strategy: PsiStrategy
}

const props = withDefaults( defineProps<ScoreCardProps>(), {
    strategy: 'mobile',
    endpointBase: undefined,
    fetchImpl: undefined,
    csrfToken: undefined,
    allowRunningTests: true,
} )

const emit = defineEmits<{
    /** The stored result id, once a queued run finishes. */
    ( event: 'result-stored', id: number | string ): void
}>()

const { data, error, loading, reload } = usePsiResource<PsiScoresData>(
    () => [ props.url, props.strategy, props.endpointBase, props.fetchImpl ],
    ( signal ) =>
        fetchPsiScores( {
            url: props.url,
            strategy: props.strategy,
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            signal,
        } ),
)

// The run being waited on carries the URL and form factor it was queued for,
// rather than being matched against the props at the time it lands. A page
// whose selector moved mid-run would otherwise announce the new URL's name over
// the old URL's result, and refresh the wrong panels.
const ticket = ref<QueuedRun | null>( null )
const actionTitle = ref<string | null>( null )
const actionMessage = ref<string | null>( null )

async function runTest(): Promise<void> {
    actionTitle.value = null
    actionMessage.value = null

    try {
        const queued = await queuePsiTest( {
            url: props.url,
            strategy: props.strategy,
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            csrfToken: props.csrfToken,
        } )

        ticket.value = { id: queued.id, url: props.url, strategy: props.strategy }
    } catch ( thrown: unknown ) {
        actionTitle.value = 'The test could not be started'
        actionMessage.value =
            thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown )
    }
}

// Polls the ticket rather than the scores endpoint. A ticket reports
// "timed-out" as well as "completed", and a card that only re-read the scores
// would wait forever on a run the queue silently dropped.
watch(
    () => [ ticket.value, props.endpointBase, props.fetchImpl ],
    ( _current, _previous, onCleanup ) => {
        const pending = ticket.value

        if ( null === pending ) {
            return
        }

        const controller = new AbortController()
        let active = true

        const check = async (): Promise<void> => {
            try {
                const run = await fetchPsiRun( pending.id, {
                    endpointBase: props.endpointBase,
                    fetchImpl: props.fetchImpl,
                    signal: controller.signal,
                } )

                if ( ! active ) return

                const status: PsiRunStatus = run.status

                if ( RUN_STATUS_QUEUED === status ) {
                    return
                }

                ticket.value = null

                if ( RUN_STATUS_TIMED_OUT === status ) {
                    actionTitle.value = 'The test has not reported back'
                    actionMessage.value =
                        'The run was queued but nothing has reported back. Check that a queue worker is running.'
                } else {
                    // A row landed — including a failed run, which stores one
                    // too. Announced so the vitals card, the opportunities
                    // table, and the trend chart stop describing the previous
                    // run, which is what the Livewire card's
                    // `pagespeed-insights:result-stored` event is for.
                    // A timed-out ticket announces nothing: no row appeared,
                    // and refreshing three panels to show the same data they
                    // already show is noise.
                    emitPsiResultStored( {
                        url: pending.url,
                        strategy: pending.strategy,
                        id: run.id,
                    } )
                    emit( 'result-stored', run.id )
                }

                reload()
            } catch ( thrown: unknown ) {
                if ( ! active ) return

                ticket.value = null
                // The run did start — it is the check on it that failed, and
                // saying otherwise sends somebody to look at the wrong thing.
                actionTitle.value = 'The test could not be checked'
                actionMessage.value =
                    thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown )
            }
        }

        const timer = setInterval( check, SCORE_CARD_POLL_MS )

        onCleanup( () => {
            active = false
            clearInterval( timer )
            controller.abort()
        } )
    },
    { immediate: true },
)

const running = computed( () => null !== ticket.value )
const state = computed( () => data.value?.state ?? SCORES_STATE_EMPTY )
const apiKeyConfigured = computed( () => data.value?.apiKeyConfigured ?? false )
const fetchedAt = computed( () => formatTimestamp( data.value?.result?.fetchedAt ) )
const warnings = computed( () => data.value?.result?.warnings ?? [] )

/**
 * One gauge per category, whether or not the run measured it.
 *
 * An unscored category is not a zero. A zero gauge and a missing gauge mean
 * opposite things, so the unavailable state carries no number and no progress
 * bar at all.
 */
const gauges = computed( () =>
    TREND_CATEGORIES.map( ( category ) => {
        const score = data.value?.scores.find( ( row ) => row.category === category )
        const available = undefined !== score && null !== score.score
        const color = available ? bandColor( score.band ) : undefined

        return {
            category,
            label: metricLabel( category ),
            available,
            value: available ? String( score.score ) : '—',
            score: score?.score ?? 0,
            band: available ? bandLabel( score.band ) ?? undefined : undefined,
            color,
            statClass: color ? STAT_VALUE_CLASSES[ color ] : undefined,
            progressClass: color ? PROGRESS_CLASSES[ color ] : undefined,
        }
    } ),
)
</script>

<template>
    <div class="ap-psi-score-card">
        <Card title="PageSpeed scores" :subtitle="url">
            <template #menu>
                <Badge :value="strategyLabel( strategy )" />
            </template>

            <TitledAlert
                v-if="actionMessage"
                color="warning"
                :title="actionTitle ?? 'The test could not be started'"
                :description="actionMessage"
                class-name="mb-4"
            />

            <TitledAlert
                v-if="error"
                color="error"
                title="These scores could not be loaded"
                :description="error.message"
            />

            <p v-else-if="loading && null === data" class="ap-psi-score-card__loading flex items-center gap-2">
                <Loading size="sm" />
                <span>Loading scores…</span>
            </p>

            <template v-else-if="! apiKeyConfigured && SCORES_STATE_EMPTY === state">
                <!--
                    PageSpeed Insights will not run without an API key —
                    Google's anonymous quota is zero, so every keyless request
                    fails.
                -->
                <TitledAlert
                    color="warning"
                    title="No PageSpeed API key is configured"
                    description="PageSpeed Insights will not run without an API key — Google's anonymous quota is zero, so every keyless request fails."
                />

                <div class="ap-psi-score-card__setup flex flex-col gap-2 mt-4">
                    <p>
                        Create a key in the Google Cloud Console with the PageSpeed Insights API
                        enabled, then set the PAGESPEED_API_KEY environment variable.
                    </p>
                    <pre><code>PAGESPEED_API_KEY=your-key-here</code></pre>
                    <p class="text-xs opacity-70">
                        Applications storing the key in the database or the CMS Settings module
                        should set the pagespeed-insights.driver config value instead.
                    </p>
                </div>
            </template>

            <p v-else-if="SCORES_STATE_EMPTY === state" class="ap-psi-score-card__empty">
                No PageSpeed test has run for this URL yet.
            </p>

            <TitledAlert
                v-else-if="SCORES_STATE_FAILED === state"
                color="error"
                title="The last PageSpeed run failed"
                :description="data?.result?.errorMessage"
            />

            <template v-else>
                <template v-if="SCORES_STATE_DEGRADED === state">
                    <TitledAlert
                        color="warning"
                        title="The last run completed with data missing"
                        description="Some of the gauges below are blank because this run did not return them."
                    />

                    <ul class="ap-psi-score-card__warnings list-disc ps-5 mt-2 mb-4 text-sm">
                        <li v-for="( warning, index ) in warnings" :key="`psi-warning-${ index }`">
                            {{ warning }}
                        </li>
                    </ul>
                </template>

                <div class="ap-psi-score-card__gauges grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div
                        v-for="gauge in gauges"
                        :key="`psi-gauge-${ gauge.category }`"
                        class="ap-psi-gauge"
                    >
                        <template v-if="gauge.available">
                            <Stat
                                :title="gauge.label"
                                :value="gauge.value"
                                :description="gauge.band"
                                :class-name="gauge.statClass"
                            />
                            <Progress
                                :value="gauge.score"
                                :max="100"
                                :class-name="`w-full mt-2 ${ gauge.progressClass ?? '' }`"
                                :aria-label="`${ gauge.label } score: ${ gauge.score } out of 100`"
                            />
                        </template>
                        <template v-else>
                            <Stat
                                :title="gauge.label"
                                value="—"
                                description="Unavailable"
                                class-name="ap-psi-gauge--unavailable opacity-60"
                            />
                            <p class="ap-psi-gauge__note text-xs opacity-70">
                                This run did not return a score for this category.
                            </p>
                        </template>
                    </div>
                </div>

                <p v-if="fetchedAt" class="ap-psi-score-card__timestamp text-xs opacity-70 mt-4">
                    Last tested {{ fetchedAt }}
                </p>
            </template>

            <template v-if="allowRunningTests" #footer>
                <template v-if="running">
                    <Loading size="sm" />
                    <span class="text-sm">Test running…</span>
                    <!--
                        A manual check alongside the poll. The poll is what
                        normally ends the wait, but a browser that has
                        backgrounded the tab throttles it hard, and having no
                        way to ask is what makes a slow run feel stuck.
                    -->
                    <Button color="ghost" size="sm" @click="reload">Check now</Button>
                </template>

                <Button
                    v-else
                    color="primary"
                    :disabled="! apiKeyConfigured"
                    :tooltip="apiKeyConfigured ? undefined : 'Configure a PageSpeed API key to run tests.'"
                    @click="runTest"
                >
                    Run test
                </Button>
            </template>
        </Card>
    </div>
</template>
