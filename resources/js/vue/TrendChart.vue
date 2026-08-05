<script lang="ts">
/**
 * Vue historical trend chart.
 *
 * The Vue half of `\ArtisanPackUI\PageSpeedInsights\Livewire\TrendChart`.
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

import type { PsiStrategy } from '../shared/client'

/**
 * Props for `TrendChart`.
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
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Card, Loading, Select } from '@artisanpack-ui/vue'
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
    type PsiTrendPoint,
} from '../shared/trends'
import { usePsiResource } from './use-psi-resource'
import { usePsiResultStored } from './use-psi-result-stored'
import TitledAlert from './TitledAlert.vue'

/**
 * The colour each form factor's line is drawn in.
 */
const SERIES_COLORS: Record<string, string> = {
    mobile: 'var(--color-primary, #6366f1)',
    desktop: 'var(--color-secondary, #a855f7)',
}

/**
 * The plot's coordinate space. The SVG scales to its container.
 */
const PLOT_WIDTH = 600

/**
 * The margin kept clear of the plotted line, in the same coordinate space.
 */
const PLOT_PADDING = 8

const props = withDefaults( defineProps<TrendChartProps>(), {
    initialMetric: TREND_DEFAULT_METRIC,
    initialRange: TREND_DEFAULT_RANGE,
    strategy: null,
    endpointBase: undefined,
    fetchImpl: undefined,
    height: 240,
} )

const metric = ref<string>( props.initialMetric )
const range = ref<number>( props.initialRange )

const { data, error, loading, reload } = usePsiResource<PsiTrendData>(
    () => [
        props.url,
        metric.value,
        range.value,
        props.strategy,
        props.endpointBase,
        props.fetchImpl,
    ],
    ( signal ) =>
        fetchPsiTrend( {
            url: props.url,
            metric: metric.value,
            range: range.value,
            strategy: props.strategy,
            endpointBase: props.endpointBase,
            fetchImpl: props.fetchImpl,
            signal,
        } ),
)

// Matched on the URL only. A run on either form factor adds a point to this
// chart, since both are plotted.
usePsiResultStored( ( event ) => {
    if ( event.url === props.url ) {
        reload()
    }
} )

const state = computed( () => data.value?.state ?? TREND_STATE_EMPTY )
const title = computed( () => metricLabel( metric.value ) )
const pointCount = computed( () => data.value?.pointCount ?? 0 )

const metricOptions = computed( () =>
    TREND_METRICS.map( ( value ) => ( { value, label: metricLabel( value ) } ) ),
)

const rangeOptions = computed( () =>
    TREND_RANGES.map( ( days ) => ( { value: String( days ), label: `Last ${ days } days` } ) ),
)

// `Select` models a string, and the endpoint takes a number of days.
const rangeModel = computed( {
    get: () => String( range.value ),
    set: ( value: string ) => {
        range.value = Number( value )
    },
} )

/**
 * The lines, in the plot's own coordinate space.
 *
 * Category scores are pinned to a 0-100 axis so that a page holding steady in
 * the nineties does not render as a jagged mountain range, which is what an
 * auto-scaled axis makes of three points of noise. A lab metric has no such
 * ceiling, so its axis is scaled to the data.
 */
const plot = computed( () => {
    const plotted = ( data.value?.series ?? [] )
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
    const minY = 0
    const ceiling = isTrendCategory( metric.value ) ? 100 : Math.max( ...values )
    const maxY = ceiling > minY ? ceiling : minY + 1
    const spanX = maxX - minX || 1
    const height = props.height

    const project = ( x: number, y: number ): [ number, number ] => [
        PLOT_PADDING + ( ( x - minX ) / spanX ) * ( PLOT_WIDTH - PLOT_PADDING * 2 ),
        height -
            PLOT_PADDING -
            ( ( y - minY ) / ( maxY - minY ) ) * ( height - PLOT_PADDING * 2 ),
    ]

    return plotted.map( ( line ) => ( {
        strategy: line.strategy,
        label: strategyLabel( line.strategy ),
        color: SERIES_COLORS[ line.strategy ] ?? 'currentColor',
        segments: segments( line.points ).map( ( segment ) =>
            segment
                .map( ( point ) => project( Date.parse( point.x ), point.y as number ).join( ',' ) )
                .join( ' ' ),
        ),
        // A marker on every measurement, so a point stranded between two gaps
        // still appears. A polyline of one point draws nothing, and joining it
        // to its neighbours would be the bridging the nulls exist to prevent.
        markers: line.points
            .filter( ( point ) => null !== point.y )
            .map( ( point, index ) => {
                const [ cx, cy ] = project( Date.parse( point.x ), point.y as number )

                // Keyed on position as well as timestamp: two runs of one form
                // factor can share a second, and a duplicate key drops a
                // marker.
                return { key: `${ index }-${ point.x }`, cx, cy }
            } ),
    } ) )
} )

/**
 * Split a series at its null points, so the line breaks at every gap.
 *
 * A single surviving point between two gaps is dropped: a polyline of one point
 * draws nothing, and joining it to its neighbours would be the very bridging
 * the nulls exist to prevent.
 */
function segments( points: PsiTrendPoint[] ): PsiTrendPoint[][] {
    const runs: PsiTrendPoint[][] = []
    let current: PsiTrendPoint[] = []

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
</script>

<template>
    <div class="ap-psi-trend">
        <Card title="Trend" :subtitle="url">
            <template #menu>
                <div class="ap-psi-trend__controls flex flex-wrap items-end gap-2">
                    <Select
                        v-model="metric"
                        label="Metric"
                        :options="metricOptions"
                        option-value="value"
                        option-label="label"
                        class="select-sm"
                    />

                    <Select
                        v-model="rangeModel"
                        label="Range"
                        :options="rangeOptions"
                        option-value="value"
                        option-label="label"
                        class="select-sm"
                    />
                </div>
            </template>

            <TitledAlert
                v-if="error"
                color="error"
                title="This trend could not be loaded"
                :description="error.message"
            />

            <p v-else-if="loading && null === data" class="ap-psi-trend__loading flex items-center gap-2">
                <Loading size="sm" />
                <span>Loading trend…</span>
            </p>

            <p v-else-if="TREND_STATE_EMPTY === state" class="ap-psi-trend__empty">
                No PageSpeed test has run for this URL yet. A trend appears once there are at least
                two results to compare.
            </p>

            <TitledAlert
                v-else-if="TREND_STATE_OUT_OF_RANGE === state"
                color="info"
                title="Nothing tested in this range"
                description="This URL has stored results, but none inside the selected range. Choose a wider range to see them."
            />

            <TitledAlert
                v-else-if="TREND_STATE_INSUFFICIENT === state"
                color="info"
                title="Not enough history yet"
                :description="
                    data?.hasOlderHistory
                        ? `Only one measurement of ${ title } falls inside the selected range, and one measurement is not a trend. Choose a wider range to include older results.`
                        : `There is only one measurement of ${ title } so far, and one measurement is not a trend. The chart appears once a second test has run.`
                "
            />

            <template v-else>
                <figure v-if="plot" class="ap-psi-trend__chart" :aria-label="`${ title } over time`">
                    <svg
                        :viewBox="`0 0 ${ PLOT_WIDTH } ${ height }`"
                        preserveAspectRatio="none"
                        class="w-full"
                        :style="{ height: `${ height }px` }"
                        role="img"
                    >
                        <g v-for="line in plot" :key="`psi-trend-${ line.strategy }`">
                            <polyline
                                v-for="( segment, index ) in line.segments"
                                :key="`psi-trend-${ line.strategy }-${ index }`"
                                fill="none"
                                :stroke="line.color"
                                :stroke-width="2"
                                :points="segment"
                            />

                            <circle
                                v-for="marker in line.markers"
                                :key="`psi-trend-${ line.strategy }-${ marker.key }`"
                                :cx="marker.cx"
                                :cy="marker.cy"
                                :r="2.5"
                                :fill="line.color"
                            />
                        </g>
                    </svg>

                    <figcaption
                        class="ap-psi-trend__legend flex flex-wrap gap-3 text-xs opacity-70 mt-2"
                    >
                        <span
                            v-for="line in plot"
                            :key="`psi-legend-${ line.strategy }`"
                            class="flex items-center gap-1"
                        >
                            <span
                                aria-hidden="true"
                                class="inline-block w-3 h-1 rounded"
                                :style="{ background: line.color }"
                            />
                            {{ line.label }}
                        </span>
                    </figcaption>
                </figure>

                <!--
                    The break in the line is the point. A run that lost this
                    measurement is a hole in the history, not a low score, and
                    joining straight over it would hide the very gap that
                    explains an odd-looking trend.
                -->
                <TitledAlert
                    v-if="data?.hasGaps"
                    color="warning"
                    title="The line has gaps"
                    :description="`Some runs in this range completed without returning ${ title }, so the line breaks rather than joining across them.`"
                    class-name="mt-4"
                />

                <TitledAlert
                    v-if="data?.truncated"
                    color="info"
                    title="Showing the most recent results only"
                    :description="`This range holds more than ${ TREND_MAX_RESULTS } results, so only the ${ TREND_MAX_RESULTS } most recent are plotted. Choose a narrower range to see the rest in detail.`"
                    class-name="mt-4"
                />

                <p class="ap-psi-trend__summary text-xs opacity-70 mt-4">
                    {{
                        1 === pointCount
                            ? `1 measurement of ${ title }.`
                            : `${ pointCount } measurements of ${ title }.`
                    }}
                </p>
            </template>
        </Card>
    </div>
</template>
