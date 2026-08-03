/**
 * React Core Web Vitals card.
 *
 * The React half of `\ArtisanPackUI\PageSpeedInsights\Livewire\CoreWebVitalsCard`.
 * "Not enough field data" and "showing origin-level data" are two different
 * states with two different messages: showing origin-level numbers as if they
 * were page-level numbers misrepresents the page.
 */

import { useCallback } from 'react'
import { Card } from '@artisanpack-ui/react/layout'
import { Badge, Stat } from '@artisanpack-ui/react/data'
import { Loading } from '@artisanpack-ui/react/feedback'
import type { PsiStrategy } from '../shared/client'
import {
    bandColor,
    bandLabel,
    formatTimestamp,
    formatVital,
    strategyLabel,
    vitalAbbreviation,
    vitalLabel,
} from '../shared/labels'
import {
    VITALS_STATE_EMPTY,
    VITALS_STATE_FAILED,
    VITALS_STATE_NO_FIELD_DATA,
    VITALS_STATE_ORIGIN_LEVEL,
    fetchPsiCoreWebVitals,
    type PsiCoreWebVitalsData,
    type PsiVital,
} from '../shared/core-web-vitals'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import { TitledAlert } from './TitledAlert'

/**
 * Props for {@link CoreWebVitalsCard}.
 */
export interface CoreWebVitalsCardProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. CrUX collects phone and desktop separately. @defaultValue 'mobile' */
    strategy?: PsiStrategy
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
}

/**
 * The latest CrUX field data for one URL.
 */
export function CoreWebVitalsCard( props: CoreWebVitalsCardProps ) {
    const { url, strategy = 'mobile', endpointBase, fetchImpl } = props

    const load = useCallback(
        ( signal: AbortSignal ) =>
            fetchPsiCoreWebVitals( { url, strategy, endpointBase, fetchImpl, signal } ),
        [ url, strategy, endpointBase, fetchImpl ],
    )

    const { data, error, loading, reload } = usePsiResource<PsiCoreWebVitalsData>( load )

    // CrUX field data belongs to the form factor it was requested with, so a
    // desktop run says nothing about the mobile card. Matched on both, like
    // the Livewire card's listener.
    usePsiResultStored( ( event ) => {
        if ( event.url === url && event.strategy === strategy ) {
            reload()
        }
    } )

    const state = data?.state ?? VITALS_STATE_EMPTY
    const originLevel = VITALS_STATE_ORIGIN_LEVEL === state

    // The page-level set is preferred when there is one. When the only data
    // describes the origin, that is what is rendered — labelled as what it is,
    // because knowing the site is slow is better than knowing nothing.
    const fieldData = data?.page ?? data?.origin ?? null
    const dataSubject = fieldData?.id ?? null
    const fetchedAt = formatTimestamp( data?.result?.fetchedAt )

    return (
        <div className="ap-psi-cwv-card">
            <Card
                title="Core Web Vitals"
                subtitle={ url }
                menu={ <Badge value={ strategyLabel( strategy ) } /> }
            >
                { error ? (
                    <TitledAlert
                        color="error"
                        title="This field data could not be loaded"
                        description={ error.message }
                    />
                ) : loading && null === data ? (
                    <p className="ap-psi-cwv-card__loading flex items-center gap-2">
                        <Loading size="sm" />
                        <span>Loading field data…</span>
                    </p>
                ) : VITALS_STATE_EMPTY === state ? (
                    <p className="ap-psi-cwv-card__empty">No PageSpeed test has run for this URL yet.</p>
                ) : VITALS_STATE_FAILED === state ? (
                    <TitledAlert
                        color="error"
                        title="The last PageSpeed run failed"
                        description={ data?.result?.errorMessage ?? undefined }
                    />
                ) : VITALS_STATE_NO_FIELD_DATA === state ? (
                    <TitledAlert
                        color="info"
                        title="Not enough field data"
                        description="The Chrome UX Report has no real-user measurements for this page, and none for the site as a whole either. This is normal for a page with low traffic — it is not an error, and there is nothing to fix."
                    />
                ) : (
                    <>
                        { originLevel ? (
                            <TitledAlert
                                color="warning"
                                title="Showing site-wide data"
                                description={
                                    dataSubject
                                        ? `The Chrome UX Report has too little traffic for this page on its own, so these numbers describe ${ dataSubject } as a whole rather than this page.`
                                        : 'The Chrome UX Report has too little traffic for this page on its own, so these numbers describe the site as a whole rather than this page.'
                                }
                                className="mb-4"
                            />
                        ) : null }

                        <div className="ap-psi-cwv-card__vitals grid grid-cols-1 gap-4 md:grid-cols-3">
                            { ( fieldData?.vitals ?? [] ).map( ( vital ) => (
                                <VitalStat key={ `psi-vital-${ vital.metric }` } vital={ vital } />
                            ) ) }
                        </div>

                        <p className="ap-psi-cwv-card__percentile text-xs opacity-70 mt-4">
                            { `Real-user data from the Chrome UX Report, reported at the ${ data?.percentile ?? 75 }th percentile.` }
                        </p>

                        { fetchedAt ? (
                            <p className="ap-psi-cwv-card__timestamp text-xs opacity-70">
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
 * One vital's tile.
 *
 * A metric CrUX had no measurement for shows an em dash rather than a zero.
 */
function VitalStat( { vital }: { vital: PsiVital } ) {
    const available = null !== vital.value

    return (
        <div className="ap-psi-vital" title={ vitalLabel( vital.metric ) }>
            <Stat
                title={ vitalAbbreviation( vital.metric ) }
                value={ available ? formatVital( vital.metric, vital.value ) ?? '—' : '—' }
                description={ available ? bandLabel( vital.band ) ?? undefined : 'No data' }
                color={ available ? bandColor( vital.band ) : undefined }
                className={ available ? undefined : 'ap-psi-vital--unavailable opacity-60' }
            />
        </div>
    )
}
