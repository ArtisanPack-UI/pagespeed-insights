/**
 * React PageSpeed category score card.
 *
 * The React half of `\ArtisanPackUI\PageSpeedInsights\Livewire\ScoreCard`.
 * Five states, deliberately worded apart from one another: an empty card means
 * "nothing has run yet", and anything else that renders as an empty card is a
 * problem nobody notices.
 */

import { useCallback, useEffect, useRef, useState } from 'react'
import { Card } from '@artisanpack-ui/react/layout'
import { Badge, Progress, Stat } from '@artisanpack-ui/react/data'
import { Loading } from '@artisanpack-ui/react/feedback'
import { Button } from '@artisanpack-ui/react/form'
import { PageSpeedInsightsError, type PsiStrategy } from '../shared/client'
import { emitPsiResultStored } from '../shared/events'
import { bandColor, bandLabel, formatTimestamp, metricLabel, strategyLabel } from '../shared/labels'
import {
    SCORES_STATE_DEGRADED,
    SCORES_STATE_EMPTY,
    SCORES_STATE_FAILED,
    fetchPsiScores,
    type PsiCategoryScore,
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
import { TitledAlert } from './TitledAlert'

/**
 * How often a running test is asked whether it has finished, in milliseconds.
 *
 * Mirrors `ScoreCard::POLL_SECONDS`.
 */
export const SCORE_CARD_POLL_MS = 5000

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
 * A run this card is waiting on, and the subject it was queued for.
 */
interface QueuedRun {
    id: number | string
    url: string
    strategy: PsiStrategy
}

/**
 * Props for {@link ScoreCard}.
 */
export interface ScoreCardProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. @defaultValue 'mobile' */
    strategy?: PsiStrategy
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** CSRF token for the run-test endpoint. Read from the document when omitted. */
    csrfToken?: string | null
    /** Whether to offer the "Run test" button at all. @defaultValue true */
    allowRunningTests?: boolean
    /** Called with the stored result id once a queued run finishes. */
    onResultStored?: ( id: number | string ) => void
}

/**
 * The four category scores for one URL and form factor.
 */
export function ScoreCard( props: ScoreCardProps ) {
    const {
        url,
        strategy = 'mobile',
        endpointBase,
        fetchImpl,
        csrfToken,
        allowRunningTests = true,
        onResultStored,
    } = props

    const load = useCallback(
        ( signal: AbortSignal ) => fetchPsiScores( { url, strategy, endpointBase, fetchImpl, signal } ),
        [ url, strategy, endpointBase, fetchImpl ],
    )

    const { data, error, loading, reload } = usePsiResource<PsiScoresData>( load )

    // The run being waited on carries the URL and form factor it was queued
    // for, rather than being matched against the props at the time it lands.
    // A page whose selector moved mid-run would otherwise announce the new
    // URL's name over the old URL's result, and refresh the wrong panels.
    const [ ticket, setTicket ] = useState<QueuedRun | null>( null )
    const [ actionTitle, setActionTitle ] = useState<string | null>( null )
    const [ actionMessage, setActionMessage ] = useState<string | null>( null )
    const reloadRef = useRef( reload )
    const storedRef = useRef( onResultStored )

    reloadRef.current = reload
    storedRef.current = onResultStored

    const runTest = useCallback( async () => {
        setActionTitle( null )
        setActionMessage( null )

        try {
            const queued = await queuePsiTest( { url, strategy, endpointBase, fetchImpl, csrfToken } )
            setTicket( { id: queued.id, url, strategy } )
        } catch ( thrown: unknown ) {
            setActionTitle( 'The test could not be started' )
            setActionMessage(
                thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
            )
        }
    }, [ url, strategy, endpointBase, fetchImpl, csrfToken ] )

    // Polls the ticket rather than the scores endpoint. A ticket reports
    // "timed-out" as well as "completed", and a card that only re-read the
    // scores would wait forever on a run the queue silently dropped.
    useEffect( () => {
        if ( null === ticket ) {
            return
        }

        const controller = new AbortController()
        let active = true

        const check = async () => {
            try {
                const run = await fetchPsiRun( ticket.id, {
                    endpointBase,
                    fetchImpl,
                    signal: controller.signal,
                } )

                if ( ! active ) return

                const status: PsiRunStatus = run.status

                if ( RUN_STATUS_QUEUED === status ) {
                    return
                }

                setTicket( null )

                if ( RUN_STATUS_TIMED_OUT === status ) {
                    setActionTitle( 'The test has not reported back' )
                    setActionMessage(
                        'The run was queued but nothing has reported back. Check that a queue worker is running.',
                    )
                } else {
                    // A row landed — including a failed run, which stores one
                    // too. Announced so the vitals card, the opportunities
                    // table, and the trend chart stop describing the previous
                    // run, which is what the Livewire card's
                    // `pagespeed-insights:result-stored` event is for.
                    // A timed-out ticket announces nothing: no row appeared,
                    // and refreshing three panels to show the same data they
                    // already show is noise.
                    emitPsiResultStored( { url: ticket.url, strategy: ticket.strategy, id: run.id } )
                    storedRef.current?.( run.id )
                }

                reloadRef.current()
            } catch ( thrown: unknown ) {
                if ( ! active ) return

                setTicket( null )
                // The run did start — it is the check on it that failed, and
                // saying otherwise sends somebody to look at the wrong thing.
                setActionTitle( 'The test could not be checked' )
                setActionMessage(
                    thrown instanceof PageSpeedInsightsError ? thrown.message : String( thrown ),
                )
            }
        }

        const timer = setInterval( check, SCORE_CARD_POLL_MS )

        return () => {
            active = false
            clearInterval( timer )
            controller.abort()
        }
    }, [ ticket, endpointBase, fetchImpl ] )

    const running = null !== ticket
    const state = data?.state ?? SCORES_STATE_EMPTY
    const apiKeyConfigured = data?.apiKeyConfigured ?? false
    const fetchedAt = formatTimestamp( data?.result?.fetchedAt )

    return (
        <div className="ap-psi-score-card">
            <Card
                title="PageSpeed scores"
                subtitle={ url }
                menu={ <Badge value={ strategyLabel( strategy ) } /> }
                footer={
                    allowRunningTests ? (
                        <ScoreCardActions
                            running={ running }
                            apiKeyConfigured={ apiKeyConfigured }
                            onRunTest={ runTest }
                            onCheckNow={ reload }
                        />
                    ) : null
                }
            >
                { actionMessage ? (
                    <TitledAlert
                        color="warning"
                        title={ actionTitle ?? 'The test could not be started' }
                        description={ actionMessage }
                        className="mb-4"
                    />
                ) : null }

                { error ? (
                    <TitledAlert color="error" title="These scores could not be loaded" description={ error.message } />
                ) : loading && null === data ? (
                    <p className="ap-psi-score-card__loading flex items-center gap-2">
                        <Loading size="sm" />
                        <span>Loading scores…</span>
                    </p>
                ) : ! apiKeyConfigured && SCORES_STATE_EMPTY === state ? (
                    <MissingApiKey />
                ) : SCORES_STATE_EMPTY === state ? (
                    <p className="ap-psi-score-card__empty">No PageSpeed test has run for this URL yet.</p>
                ) : SCORES_STATE_FAILED === state ? (
                    <TitledAlert
                        color="error"
                        title="The last PageSpeed run failed"
                        description={ data?.result?.errorMessage ?? undefined }
                    />
                ) : (
                    <>
                        { SCORES_STATE_DEGRADED === state ? (
                            <>
                                <TitledAlert
                                    color="warning"
                                    title="The last run completed with data missing"
                                    description="Some of the gauges below are blank because this run did not return them."
                                />

                                <ul className="ap-psi-score-card__warnings list-disc ps-5 mt-2 mb-4 text-sm">
                                    { ( data?.result?.warnings ?? [] ).map( ( warning, index ) => (
                                        <li key={ `psi-warning-${ index }` }>{ warning }</li>
                                    ) ) }
                                </ul>
                            </>
                        ) : null }

                        <div className="ap-psi-score-card__gauges grid grid-cols-2 gap-4 lg:grid-cols-4">
                            { TREND_CATEGORIES.map( ( category ) => (
                                <Gauge
                                    key={ `psi-gauge-${ category }` }
                                    category={ category }
                                    score={ data?.scores.find( ( row ) => row.category === category ) }
                                />
                            ) ) }
                        </div>

                        { fetchedAt ? (
                            <p className="ap-psi-score-card__timestamp text-xs opacity-70 mt-4">
                                Last tested { fetchedAt }
                            </p>
                        ) : null }
                    </>
                ) }
            </Card>
        </div>
    )
}

/**
 * One category's gauge.
 *
 * An unscored category is not a zero. A zero gauge and a missing gauge mean
 * opposite things, so the unavailable state carries no number and no progress
 * bar at all.
 */
function Gauge( { category, score }: { category: string; score?: PsiCategoryScore } ) {
    const label = metricLabel( category )
    const available = undefined !== score && null !== score.score

    if ( ! available ) {
        return (
            <div className="ap-psi-gauge">
                <Stat
                    title={ label }
                    value="—"
                    description="Unavailable"
                    className="ap-psi-gauge--unavailable opacity-60"
                />
                <p className="ap-psi-gauge__note text-xs opacity-70">
                    This run did not return a score for this category.
                </p>
            </div>
        )
    }

    const color = bandColor( score.band )

    return (
        <div className="ap-psi-gauge">
            <Stat
                title={ label }
                value={ String( score.score ) }
                description={ bandLabel( score.band ) ?? undefined }
                color={ color }
            />
            <Progress
                value={ score.score ?? 0 }
                max={ 100 }
                className={ `w-full mt-2 ${ color ? PROGRESS_CLASSES[ color ] ?? '' : '' }` }
                aria-label={ `${ label } score: ${ score.score } out of 100` }
            />
        </div>
    )
}

/**
 * The setup instructions a keyless installation needs.
 *
 * PageSpeed Insights will not run without an API key — Google's anonymous
 * quota is zero, so every keyless request fails.
 */
function MissingApiKey() {
    return (
        <>
            <TitledAlert
                color="warning"
                title="No PageSpeed API key is configured"
                description="PageSpeed Insights will not run without an API key — Google's anonymous quota is zero, so every keyless request fails."
            />

            <div className="ap-psi-score-card__setup flex flex-col gap-2 mt-4">
                <p>
                    Create a key in the Google Cloud Console with the PageSpeed Insights API enabled, then set
                    the PAGESPEED_API_KEY environment variable.
                </p>
                <pre>
                    <code>PAGESPEED_API_KEY=your-key-here</code>
                </pre>
                <p className="text-xs opacity-70">
                    Applications storing the key in the database or the CMS Settings module should set the
                    pagespeed-insights.driver config value instead.
                </p>
            </div>
        </>
    )
}

/**
 * The card's footer: run a test, or wait for the one that is running.
 */
function ScoreCardActions( props: {
    running: boolean
    apiKeyConfigured: boolean
    onRunTest: () => void
    onCheckNow: () => void
} ) {
    const { running, apiKeyConfigured, onRunTest, onCheckNow } = props

    if ( running ) {
        return (
            <>
                <Loading size="sm" />
                <span className="text-sm">Test running…</span>
                { /*
                    A manual check alongside the poll. The poll is what normally
                    ends the wait, but a browser that has backgrounded the tab
                    throttles it hard, and having no way to ask is what makes a
                    slow run feel stuck.
                */ }
                <Button color="ghost" size="sm" onClick={ onCheckNow }>
                    Check now
                </Button>
            </>
        )
    }

    return (
        <Button
            color="primary"
            onClick={ onRunTest }
            disabled={ ! apiKeyConfigured }
            tooltip={ apiKeyConfigured ? undefined : 'Configure a PageSpeed API key to run tests.' }
        >
            Run test
        </Button>
    )
}
