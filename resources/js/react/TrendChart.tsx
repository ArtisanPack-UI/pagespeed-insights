/**
 * React historical trend chart.
 *
 * The React half of `\ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart`.
 * Below two usable measurements the component says what it has instead of
 * drawing it — a one-point chart is a dot a reader will read a direction into.
 * "No history at all" and "no history in this range" are separated, because
 * widening the range and running a test are different remedies.
 *
 * ### Why the line is drawn here rather than by the library's chart
 *
 * A trend carries one series per form factor, each on its own timestamps, with
 * null points where a completed run lost the measurement. The component
 * library's `Chart` takes a series as a plain `number[]` against shared
 * labels, which cannot express either — and it pulls in ApexCharts, an
 * optional peer a host application need not have installed. So the line is an
 * inline SVG: no extra dependency, and a gap stays a gap.
 */

import { useCallback, useMemo, useState } from 'react'
import { Card } from '@artisanpack-ui/react/layout'
import { Loading } from '@artisanpack-ui/react/feedback'
import { Select } from '@artisanpack-ui/react/form'
import type { PsiStrategy } from '../shared/client'
import { metricLabel, strategyLabel } from '../shared/labels'
import {
    TREND_DEFAULT_METRIC,
    TREND_DEFAULT_RANGE,
    TREND_MAX_RESULTS,
    TREND_METRICS,
    TREND_RANGES,
    TREND_STATE_EMPTY,
    TREND_STATE_INSUFFICIENT,
    TREND_STATE_OUT_OF_RANGE,
    fetchPsiTrend,
    isTrendCategory,
    type PsiTrendData,
    type PsiTrendSeries,
} from '../shared/trends'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import { TitledAlert } from './TitledAlert'

/**
 * The colour each form factor's line is drawn in.
 */
const SERIES_COLORS: Record<string, string> = {
    mobile: 'var(--color-primary, #6366f1)',
    desktop: 'var(--color-secondary, #a855f7)',
}

/**
 * Props for {@link TrendChart}.
 */
export interface TrendChartProps {
    /** The URL to read. Must be one this installation monitors. */
    url: string
    /** The measurement plotted first. @defaultValue 'performance' */
    initialMetric?: string
    /** The range plotted first, in days. @defaultValue 90 */
    initialRange?: number
    /** One form factor, or omitted for every one that has data. */
    strategy?: PsiStrategy | null
    /** Path the package's routes are mounted under. @defaultValue '/pagespeed' */
    endpointBase?: string
    /** Fetch instance override, useful for SSR and for tests. */
    fetchImpl?: typeof fetch
    /** Chart height in pixels. @defaultValue 240 */
    height?: number
}

/**
 * One measurement plotted over time, mobile against desktop.
 */
export function TrendChart( props: TrendChartProps ) {
    const {
        url,
        initialMetric = TREND_DEFAULT_METRIC,
        initialRange = TREND_DEFAULT_RANGE,
        strategy = null,
        endpointBase,
        fetchImpl,
        height = 240,
    } = props

    const [ metric, setMetric ] = useState<string>( initialMetric )
    const [ range, setRange ] = useState<number>( initialRange )

    const load = useCallback(
        ( signal: AbortSignal ) =>
            fetchPsiTrend( { url, metric, range, strategy, endpointBase, fetchImpl, signal } ),
        [ url, metric, range, strategy, endpointBase, fetchImpl ],
    )

    const { data, error, loading, reload } = usePsiResource<PsiTrendData>( load )

    // Matched on the URL only. A run on either form factor adds a point to
    // this chart, since both are plotted.
    usePsiResultStored( ( event ) => {
        if ( event.url === url ) {
            reload()
        }
    } )

    const state = data?.state ?? TREND_STATE_EMPTY
    const title = metricLabel( metric )

    const metricOptions = useMemo(
        () => TREND_METRICS.map( ( value ) => ( { value, label: metricLabel( value ) } ) ),
        [],
    )

    const rangeOptions = useMemo(
        () =>
            TREND_RANGES.map( ( days ) => ( {
                value: String( days ),
                label: `Last ${ days } days`,
            } ) ),
        [],
    )

    return (
        <div className="ap-psi-trend">
            <Card
                title="Trend"
                subtitle={ url }
                menu={
                    <div className="ap-psi-trend__controls flex flex-wrap items-end gap-2">
                        <Select
                            label="Metric"
                            value={ metric }
                            onChange={ ( event ) => setMetric( event.target.value ) }
                            options={ metricOptions }
                            optionValue="value"
                            optionLabel="label"
                            className="select-sm"
                        />

                        <Select
                            label="Range"
                            value={ String( range ) }
                            onChange={ ( event ) => setRange( Number( event.target.value ) ) }
                            options={ rangeOptions }
                            optionValue="value"
                            optionLabel="label"
                            className="select-sm"
                        />
                    </div>
                }
            >
                { error ? (
                    <TitledAlert
                        color="error"
                        title="This trend could not be loaded"
                        description={ error.message }
                    />
                ) : loading && null === data ? (
                    <p className="ap-psi-trend__loading flex items-center gap-2">
                        <Loading size="sm" />
                        <span>Loading trend…</span>
                    </p>
                ) : TREND_STATE_EMPTY === state ? (
                    <p className="ap-psi-trend__empty">
                        No PageSpeed test has run for this URL yet. A trend appears once there are at least two
                        results to compare.
                    </p>
                ) : TREND_STATE_OUT_OF_RANGE === state ? (
                    <TitledAlert
                        color="info"
                        title="Nothing tested in this range"
                        description="This URL has stored results, but none inside the selected range. Choose a wider range to see them."
                    />
                ) : TREND_STATE_INSUFFICIENT === state ? (
                    <TitledAlert
                        color="info"
                        title="Not enough history yet"
                        description={
                            data?.hasOlderHistory
                                ? `Only one measurement of ${ title } falls inside the selected range, and one measurement is not a trend. Choose a wider range to include older results.`
                                : `There is only one measurement of ${ title } so far, and one measurement is not a trend. The chart appears once a second test has run.`
                        }
                    />
                ) : (
                    <>
                        <TrendPlot
                            series={ data?.series ?? [] }
                            metric={ metric }
                            height={ height }
                            label={ title }
                        />

                        { data?.hasGaps ? (
                            /*
                                The break in the line is the point. A run that
                                lost this measurement is a hole in the history,
                                not a low score, and joining straight over it
                                would hide the very gap that explains an
                                odd-looking trend.
                            */
                            <TitledAlert
                                color="warning"
                                title="The line has gaps"
                                description={ `Some runs in this range completed without returning ${ title }, so the line breaks rather than joining across them.` }
                                className="mt-4"
                            />
                        ) : null }

                        { data?.truncated ? (
                            <TitledAlert
                                color="info"
                                title="Showing the most recent results only"
                                description={ `This range holds more than ${ TREND_MAX_RESULTS } results, so only the ${ TREND_MAX_RESULTS } most recent are plotted. Choose a narrower range to see the rest in detail.` }
                                className="mt-4"
                            />
                        ) : null }

                        <p className="ap-psi-trend__summary text-xs opacity-70 mt-4">
                            { 1 === data?.pointCount
                                ? `1 measurement of ${ title }.`
                                : `${ data?.pointCount ?? 0 } measurements of ${ title }.` }
                        </p>
                    </>
                ) }
            </Card>
        </div>
    )
}

/**
 * The plotted lines, as inline SVG.
 *
 * Category scores are pinned to a 0-100 axis so that a page holding steady in
 * the nineties does not render as a jagged mountain range, which is what an
 * auto-scaled axis makes of three points of noise. A lab metric has no such
 * ceiling, so its axis is scaled to the data.
 */
function TrendPlot( props: {
    series: PsiTrendSeries[]
    metric: string
    height: number
    label: string
} ) {
    const { series, metric, height, label } = props

    const geometry = useMemo( () => {
        const plotted = series
            .map( ( line ) => ( {
                strategy: line.strategy,
                points: line.points.filter( ( point ) => Number.isFinite( Date.parse( point.x ) ) ),
            } ) )
            .filter( ( line ) => line.points.length > 0 )

        const timestamps = plotted.flatMap( ( line ) => line.points.map( ( p ) => Date.parse( p.x ) ) )
        const values = plotted.flatMap( ( line ) =>
            line.points.map( ( p ) => p.y ).filter( ( y ): y is number => null !== y ),
        )

        if ( 0 === timestamps.length || 0 === values.length ) {
            return null
        }

        const minX = Math.min( ...timestamps )
        const maxX = Math.max( ...timestamps )
        const isCategory = isTrendCategory( metric )
        const minY = 0
        const maxY = isCategory ? 100 : Math.max( ...values )

        return { plotted, minX, maxX, minY, maxY: maxY > minY ? maxY : minY + 1 }
    }, [ series, metric ] )

    if ( null === geometry ) {
        return null
    }

    const { plotted, minX, maxX, minY, maxY } = geometry
    const width = 600
    const padding = 8
    const spanX = maxX - minX || 1

    const project = ( x: number, y: number ): [ number, number ] => [
        padding + ( ( x - minX ) / spanX ) * ( width - padding * 2 ),
        height - padding - ( ( y - minY ) / ( maxY - minY ) ) * ( height - padding * 2 ),
    ]

    return (
        <figure className="ap-psi-trend__chart" aria-label={ `${ label } over time` }>
            <svg
                viewBox={ `0 0 ${ width } ${ height }` }
                preserveAspectRatio="none"
                className="w-full"
                style={ { height: `${ height }px` } }
                role="img"
            >
                { plotted.map( ( line ) => (
                    <g key={ `psi-trend-${ line.strategy }` }>
                        { segments( line.points ).map( ( segment, index ) => (
                            <polyline
                                key={ `psi-trend-${ line.strategy }-${ index }` }
                                fill="none"
                                stroke={ SERIES_COLORS[ line.strategy ] ?? 'currentColor' }
                                strokeWidth={ 2 }
                                points={ segment
                                    .map( ( point ) =>
                                        project( Date.parse( point.x ), point.y as number ).join( ',' ),
                                    )
                                    .join( ' ' ) }
                            />
                        ) ) }

                        { /*
                            A marker on every measurement, so a point stranded
                            between two gaps still appears. A polyline of one
                            point draws nothing, and joining it to its
                            neighbours would be the bridging the nulls exist to
                            prevent.
                        */ }
                        { line.points
                            .filter( ( point ) => null !== point.y )
                            .map( ( point, index ) => {
                                const [ cx, cy ] = project( Date.parse( point.x ), point.y as number )

                                return (
                                    <circle
                                        // Keyed on position as well as
                                        // timestamp: two runs of one form
                                        // factor can share a second, and a
                                        // duplicate key drops a marker.
                                        key={ `psi-trend-${ line.strategy }-${ index }-${ point.x }` }
                                        cx={ cx }
                                        cy={ cy }
                                        r={ 2.5 }
                                        fill={ SERIES_COLORS[ line.strategy ] ?? 'currentColor' }
                                    />
                                )
                            } ) }
                    </g>
                ) ) }
            </svg>

            <figcaption className="ap-psi-trend__legend flex flex-wrap gap-3 text-xs opacity-70 mt-2">
                { plotted.map( ( line ) => (
                    <span key={ `psi-legend-${ line.strategy }` } className="flex items-center gap-1">
                        <span
                            aria-hidden="true"
                            className="inline-block w-3 h-1 rounded"
                            style={ { background: SERIES_COLORS[ line.strategy ] ?? 'currentColor' } }
                        />
                        { strategyLabel( line.strategy ) }
                    </span>
                ) ) }
            </figcaption>
        </figure>
    )
}

/**
 * Split a series at its null points, so the line breaks at every gap.
 *
 * A single surviving point between two gaps is dropped: a polyline of one point
 * draws nothing, and joining it to its neighbours would be the very bridging
 * the nulls exist to prevent.
 */
function segments( points: PsiTrendSeries[ 'points' ] ): PsiTrendSeries[ 'points' ][] {
    const runs: PsiTrendSeries[ 'points' ][] = []
    let current: PsiTrendSeries[ 'points' ] = []

    for ( const point of points ) {
        if ( null === point.y ) {
            if ( current.length > 1 ) runs.push( current )
            current = []
            continue
        }

        current.push( point )
    }

    if ( current.length > 1 ) runs.push( current )

    return runs
}
