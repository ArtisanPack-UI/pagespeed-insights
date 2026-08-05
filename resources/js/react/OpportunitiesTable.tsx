/**
 * React Lighthouse opportunities table.
 *
 * The React half of `\ArtisanPackUI\PageSpeedInsights\Livewire\OpportunitiesTable`.
 * An empty list is three different pieces of news — performance was never
 * measured, performance was measured and came back clean, or the run failed
 * outright — and only one of them is good.
 */

import { useCallback } from 'react'
import { Card } from '@artisanpack-ui/react/layout'
import { Badge, Table, type TableHeader } from '@artisanpack-ui/react/data'
import { Loading } from '@artisanpack-ui/react/feedback'
import type { PsiStrategy } from '../shared/client'
import { bandColor, formatSavings, formatTimestamp, strategyLabel } from '../shared/labels'
import {
    OPPORTUNITIES_STATE_EMPTY,
    OPPORTUNITIES_STATE_FAILED,
    OPPORTUNITIES_STATE_NONE,
    OPPORTUNITIES_STATE_NOT_MEASURED,
    fetchPsiOpportunities,
    type PsiOpportunitiesData,
    type PsiOpportunity,
} from '../shared/opportunities'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import { TitledAlert } from './TitledAlert'

/**
 * Props for {@link OpportunitiesTable}.
 */
export interface OpportunitiesTableProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The form factor. @defaultValue 'mobile' */
    strategy?: PsiStrategy
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
}

const HEADERS: TableHeader<PsiOpportunity>[] = [
    {
        key: 'title',
        label: 'Opportunity',
        render: ( _value, row ) => (
            <div className="ap-psi-opportunity">
                <span className="ap-psi-opportunity__title font-medium">{ row.title }</span>

                { row.description ? (
                    <p className="ap-psi-opportunity__description text-xs opacity-70">{ row.description }</p>
                ) : null }
            </div>
        ),
    },
    {
        key: 'savingsMs',
        label: 'Estimated saving',
        className: 'text-end',
        render: ( _value, row ) => {
            const savings = formatSavings( row.savingsMs )

            return (
                <div className="ap-psi-opportunity__savings text-end">
                    { savings ? (
                        <span className="ap-psi-opportunity__savings-value font-medium">{ savings }</span>
                    ) : (
                        /* No estimate is not an estimate of zero. */
                        <span className="ap-psi-opportunity__savings-value opacity-60">—</span>
                    ) }

                    { row.displayValue ? (
                        <p className="ap-psi-opportunity__display-value text-xs opacity-70">
                            { row.displayValue }
                        </p>
                    ) : null }
                </div>
            )
        },
    },
    {
        key: 'score',
        label: 'Audit score',
        className: 'text-end',
        render: ( _value, row ) => (
            <div className="ap-psi-opportunity__score text-end">
                { null === row.score ? (
                    <span className="opacity-60">Unscored</span>
                ) : (
                    <Badge value={ String( row.score ) } color={ bandColor( row.band ) } />
                ) }
            </div>
        ),
    },
]

/**
 * What Lighthouse says is worth fixing on one URL, heaviest first.
 */
export function OpportunitiesTable( props: OpportunitiesTableProps ) {
    const { url, strategy = 'mobile', endpointBase, fetchImpl } = props

    const load = useCallback(
        ( signal: AbortSignal ) =>
            fetchPsiOpportunities( { url, strategy, endpointBase, fetchImpl, signal } ),
        [ url, strategy, endpointBase, fetchImpl ],
    )

    const { data, error, loading, reload } = usePsiResource<PsiOpportunitiesData>( load )

    // Audits are per form factor, so both have to match — like the Livewire
    // table's listener.
    usePsiResultStored( ( event ) => {
        if ( event.url === url && event.strategy === strategy ) {
            reload()
        }
    } )

    const state = data?.state ?? OPPORTUNITIES_STATE_EMPTY
    const fetchedAt = formatTimestamp( data?.result?.fetchedAt )

    return (
        <div className="ap-psi-opportunities">
            <Card
                title="Opportunities"
                subtitle={ url }
                menu={ <Badge value={ strategyLabel( strategy ) } /> }
            >
                { error ? (
                    <TitledAlert
                        color="error"
                        title="These opportunities could not be loaded"
                        description={ error.message }
                    />
                ) : loading && null === data ? (
                    <p className="ap-psi-opportunities__loading flex items-center gap-2">
                        <Loading size="sm" />
                        <span>Loading opportunities…</span>
                    </p>
                ) : OPPORTUNITIES_STATE_EMPTY === state ? (
                    <p className="ap-psi-opportunities__empty">No PageSpeed test has run for this URL yet.</p>
                ) : OPPORTUNITIES_STATE_FAILED === state ? (
                    <TitledAlert
                        color="error"
                        title="The last PageSpeed run failed"
                        description={ data?.result?.errorMessage ?? undefined }
                    />
                ) : OPPORTUNITIES_STATE_NOT_MEASURED === state ? (
                    /*
                        Nothing was found because nothing was looked for.
                        Rendering this as "no opportunities" would report a clean
                        page on the strength of a test that never examined it.
                    */
                    <TitledAlert
                        color="warning"
                        title="Performance was not measured"
                        description="This run did not request the performance category, so Lighthouse collected no opportunity audits. There is nothing to report rather than nothing to fix."
                    />
                ) : OPPORTUNITIES_STATE_NONE === state ? (
                    <TitledAlert
                        color="success"
                        title="No opportunities found"
                        description="Lighthouse measured this page and found nothing above its own reporting threshold worth changing."
                    />
                ) : (
                    <>
                        <Table
                            headers={ HEADERS }
                            rows={ data?.opportunities ?? [] }
                            keyBy="id"
                            className="ap-psi-opportunities__table"
                        />

                        <p className="ap-psi-opportunities__note text-xs opacity-70 mt-4">
                            Savings are Lighthouse&apos;s own estimates of what fixing each item would buy, and
                            are not additive.
                        </p>
                    </>
                ) }

                { fetchedAt ? (
                    <p className="ap-psi-opportunities__timestamp text-xs opacity-70">Last tested { fetchedAt }</p>
                ) : null }
            </Card>
        </div>
    )
}
